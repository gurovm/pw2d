<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Services\ProductScoringService;
use App\Support\ListingHealth;
use App\Support\ModelIdentity;
use App\Support\ProductConditionGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deterministic pick selection for a "Best X" landing page (Spec 027 §4).
 *
 * Picks are chosen entirely by data — the AI (AiService::generateLandingPageContent)
 * only writes prose about the picks selected here. Keeps the "data-driven" claim
 * honest and the page regenerable.
 */
class SelectLandingPagePicks
{
    private const MAX_PICKS = 7;
    private const MIN_PICKS = 5;

    /**
     * Spec 034 §2 — soft cap: at most this many picks may share a `brand_id`
     * when an under-quota alternative exists for the slot. See $pickBrandAware.
     */
    private const MAX_PICKS_PER_BRAND = 3;

    /**
     * @return list<array{product_id: int, role: string}>
     *   role ∈ overall/budget/premium/preset:{slug}. Fill-in picks (beyond the four
     *   named roles) reuse role "overall" — they are simply the next-highest entries
     *   in the same default-weighted ranking used for the #1 "Best Overall" pick.
     *
     * @throws \RuntimeException When fewer than MIN_PICKS eligible products exist.
     */
    public function execute(Category $category): array
    {
        $features = Feature::where('category_id', $category->id)->get();

        // Eligibility gate (Spec 027 §4): processed, not ignored, category attached
        // (implicit via the category_id scope below), image + ai_summary present.
        $products = Product::where('category_id', $category->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->whereNotNull('ai_summary')
            ->with([
                'featureValues:id,product_id,feature_id,raw_value',
                // Perf M2: `store_id` (+ eager `offers.store` below) is required for
                // `Product::bestOffer`'s commission/priority tiebreak — omitting it left
                // `$offer->store` resolving to null here, so on a price tie this action
                // could pick a different "best" offer than AuditLandingPageFreshness
                // (which already eager-loads `offers.store`), risking flapping
                // selection_drift/pick_ineligible verdicts.
                // Fix 3 (2026-08-15): `condition` must be selected — Product::bestOffer
                // (read via the `image_url` accessor's best-offer fallback below) now
                // excludes NEGATIVE_CONDITIONS offers based on this column; an
                // unselected column would resolve as null and silently look clean.
                // S2 (2026-08-16): uses ListingHealth::OFFER_HEALTH_COLUMNS so a future
                // health column added there is picked up here automatically.
                'offers:id,product_id,store_id,scraped_price,image_url,raw_title,' . implode(',', ListingHealth::OFFER_HEALTH_COLUMNS),
                'offers.store',
                // Spec 034 §1: modelKey() reads brand->name to exclude the brand
                // itself from the model-token join; eager-loaded (id,name only)
                // so the per-candidate calls in $isDuplicateOfPicked/modelKey
                // below don't N+1 across the category's full product pool.
                'brand:id,name',
            ])
            ->get()
            // image_url is a computed accessor (local path → best offer → any offer);
            // reuses the same resolution chain the rest of the site uses to decide
            // whether a product actually has a displayable image.
            ->filter(fn (Product $p) => filled($p->image_url))
            // Addendum A §2a: exclude condition-marked products (renewed/refurbished/
            // open box/pre-owned/used) — checked against every offer's raw_title AND
            // the product's own ai_summary (an earlier AI pass can itself fabricate a
            // condition claim in prose, e.g. "even if renewed" — see builder memory).
            ->reject(fn (Product $p) => self::hasConditionMarker($p))
            ->values();

        // Spec 029 amendment (2026-08-10, `unavailable` added 2026-08-12; fixed
        // 2026-08-12 to check every offer instead of only `best_offer` — see
        // hasEligibleOffer() below): a product is pick-eligible only if it has at
        // least one offer a reader could actually buy today — priced and free of
        // any pick-excluding flag (`high_price` = bad deal today, `unavailable` =
        // nothing to buy today). Spec 041: and that offer must have been
        // health-checked at least once. The product itself stays visible elsewhere
        // on the site; it's excluded from pick eligibility only, not ignored outright.
        // $awaitingCheck counts the ones excluded ONLY for lack of a check, so a
        // too-small pool can say "rescan first" instead of "pool too small".
        $awaitingCheck = $products
            ->reject(fn (Product $p) => self::hasEligibleOffer($p))
            ->filter(fn (Product $p) => $p->offers->contains(fn ($offer) => ListingHealth::isPurchasable($offer)))
            ->count();

        $products = $products->filter(fn (Product $p) => self::hasEligibleOffer($p))->values();

        $awaitingNote = $awaitingCheck > 0 ? sprintf(' %d products await a health check — rescan the category first.', $awaitingCheck) : '';

        if ($products->isEmpty()) {
            throw new \RuntimeException(
                "Category \"{$category->name}\" has no eligible products for a landing page "
                . '(need: fully processed, not ignored, image + ai_summary present, a health-checked purchasable offer).'
                . $awaitingNote
            );
        }

        // "Best Overall" scoring path: identical to ProductCompare's default-weight
        // scoring (ProductScoringService::scoreAllProducts, all weights = 50/neutral).
        $defaultWeights = $features->mapWithKeys(fn (Feature $f) => [$f->id => 50])->toArray();

        $scored = (new ProductScoringService())
            ->scoreAllProducts($products, $features, $defaultWeights, amazonRatingWeight: 50, priceWeight: 50)
            ->sortByDesc('match_score')
            ->values();

        $picks     = [];
        $pickedIds = [];

        // Addendum A §2b / Spec 034 §1: reject a candidate that is a variant of an
        // already-picked product. Product identity is decided by modelKey() FIRST
        // (brand_id + strongest model token, e.g. "Z10 Gen 1" and "Z10 Aluminum
        // White" both key to the same z10) — string-similarity measures marketing
        // copy length, not product identity, and is inverted relative to truth on
        // the real names this rule exists for (Spec 034 "Why"). The model key is
        // AUTHORITATIVE for identity: when both sides have one, it alone decides
        // — equal keys are a duplicate, different keys are NOT, full stop. The
        // similarity fallback below runs ONLY when at least one side has no
        // model token at all (e.g. "Gaggia Cadorna Prestige"), exactly as
        // originally shipped. A confirmed key difference must veto the
        // similarity check rather than be overruled by it — real names in the
        // live pool score high on similar_text despite being different machines
        // (e.g. "Philips 4400 Series..." vs "Philips 1200 Series..." at 95.7%,
        // "Jura Z10 Aluminum White" vs "Jura Z8 Aluminum White" at 92.3%), so
        // letting the fallback re-run after a confirmed non-match would
        // reintroduce exactly the over-merging this spec exists to prevent.
        // Checked at every pick site below so the NEXT-best candidate is tried
        // instead of leaving the role/slot empty.
        // Spec 043: the pairwise decision itself now lives in ModelIdentity::match()
        // (shared with the VS-page rival selection and save guard); this closure only
        // loops the picked set and keeps the rejection logging.
        // Spec 041 §3: when BOTH products carry a `model` (and a brand) the pair is a
        // duplicate iff brand_id and normalized model match — that decides, like the
        // model key does below. Either side NULL -> the Spec 034 logic runs unchanged.
        // Every rejection is logged once per pair ($loggedPairs): the closure is
        // re-evaluated for the same candidates at every pick site.
        $loggedPairs = [];

        $isDuplicateOfPicked = function (Product $candidate) use (&$pickedIds, &$loggedPairs, $products, $category): bool {
            $reject = function (Product $picked, string $path, string $key) use ($candidate, $category, &$loggedPairs): bool {
                $pair = min($candidate->id, $picked->id) . ':' . max($candidate->id, $picked->id);

                if (!isset($loggedPairs[$pair])) {
                    $loggedPairs[$pair] = true;
                    Log::info('SelectLandingPagePicks: duplicate rejected', [
                        'category_id'  => $category->id,
                        'candidate_id' => $candidate->id,
                        'picked_id'    => $picked->id,
                        'path'         => $path,
                        'key'          => $key,
                    ]);
                }

                return true;
            };

            foreach ($pickedIds as $pickedId) {
                $picked = $products->firstWhere('id', $pickedId);

                if ($picked === null) {
                    continue;
                }

                $match = ModelIdentity::match($candidate, $picked);

                if ($match !== null) {
                    return $reject($picked, $match['path'], $match['key']);
                }
            }

            return false;
        };

        // Spec 034 §2: brand-cap bookkeeping, keyed by brand_id (never brand name
        // string) — shared by $addPick (records) and $pickBrandAware (reads).
        $brandCounts = [];

        $addPick = function (?Product $product, string $role) use (&$picks, &$pickedIds, &$brandCounts, $isDuplicateOfPicked): bool {
            if ($product === null || in_array($product->id, $pickedIds, true) || $isDuplicateOfPicked($product)) {
                return false;
            }

            $picks[]     = ['product_id' => $product->id, 'role' => $role];
            $pickedIds[] = $product->id;

            if ($product->brand_id !== null) {
                $brandCounts[$product->brand_id] = ($brandCounts[$product->brand_id] ?? 0) + 1;
            }

            return true;
        };

        // Spec 034 §2: resolves the best pick-eligible candidate from $candidates
        // (already sorted best-first) matching $predicate, PREFERRING one whose
        // brand is still under MAX_PICKS_PER_BRAND. The cap is soft: if every
        // remaining eligible candidate is over-quota, the best of THOSE is
        // returned instead of leaving the slot empty (a category with only one
        // or two viable brands must still reach MIN_PICKS) and it's logged so a
        // genuinely monocultural category is visible as data. Every pick site
        // below funnels its candidate search through this one place so the cap
        // — like the duplicate guard in $addPick — can never be applied
        // inconsistently between roles.
        $pickBrandAware = function (Collection $candidates, callable $predicate, string $role) use (
            &$pickedIds,
            &$brandCounts,
            $isDuplicateOfPicked,
            $category,
        ): ?Product {
            $eligible = fn (Product $p): bool => !in_array($p->id, $pickedIds, true)
                && !$isDuplicateOfPicked($p)
                && $predicate($p);

            $underQuota = $candidates->first(fn (Product $p) => $eligible($p)
                && ($p->brand_id === null || ($brandCounts[$p->brand_id] ?? 0) < self::MAX_PICKS_PER_BRAND));

            if ($underQuota !== null) {
                return $underQuota;
            }

            $overQuota = $candidates->first($eligible);

            if ($overQuota !== null) {
                Log::info(sprintf(
                    'SelectLandingPagePicks: brand cap (%d picks) exceeded for category "%s" (id %d), role "%s" '
                    . '— no under-quota candidate available; picked brand_id=%s over quota rather than leave the slot empty.',
                    self::MAX_PICKS_PER_BRAND,
                    $category->name,
                    $category->id,
                    $role,
                    $overQuota->brand_id ?? 'null',
                ));
            }

            return $overQuota;
        };

        // Best Overall — the single highest default-weighted score.
        $addPick($pickBrandAware($scored, fn (Product $p) => true, 'overall'), 'overall');

        // Best Budget / Best Premium — top-scored within price_tier 1 / 3.
        $addPick(
            $pickBrandAware($scored, fn (Product $p) => (int) $p->price_tier === 1, 'budget'),
            'budget',
        );
        $addPick(
            $pickBrandAware($scored, fn (Product $p) => (int) $p->price_tier === 3, 'premium'),
            'premium',
        );

        // Best for {preset} — top-scored under that preset's weights, for the
        // category's top presets (by sort_order, max 3), skipping already-picked products.
        // Scoring mirrors AiService::generatePresetContent's established preset-ranking
        // approach: sum of (feature raw_value * pivot weight) over the preset's weighted
        // features only (unlisted features contribute nothing — no neutral default).
        $presets = $category->presets()->with('presetFeatures')->orderBy('sort_order')->take(3)->get();

        foreach ($presets as $preset) {
            $weightMap = $preset->presetFeatures->pluck('weight', 'feature_id')->toArray();

            if (empty($weightMap)) {
                continue;
            }

            $role = 'preset:' . Str::slug($preset->name);

            // Sorted-by-score candidates, brand-cap resolution deferred to
            // $pickBrandAware (its own pickedIds/duplicate check makes the
            // pickedIds reject above a performance optimization, not a
            // correctness requirement — cheaper to skip already-picked
            // products before scoring them).
            $sortedCandidates = $products
                ->reject(fn (Product $p) => in_array($p->id, $pickedIds, true))
                ->map(fn (Product $p) => [
                    'product' => $p,
                    'score'   => $p->featureValues
                        ->filter(fn ($fv) => isset($weightMap[$fv->feature_id]))
                        ->sum(fn ($fv) => (float) $fv->raw_value * (float) $weightMap[$fv->feature_id]),
                ])
                ->sortByDesc('score')
                ->pluck('product');

            $addPick($pickBrandAware($sortedCandidates, fn (Product $p) => true, $role), $role);
        }

        // Fill remaining slots (to MAX_PICKS) with the next-highest overall scores.
        // Each iteration re-runs $pickBrandAware's own soft-cap search over the
        // (still-ranked) $scored list: it prefers the best remaining under-quota
        // candidate for THIS slot, falling back to the best over-quota one only
        // when every remaining candidate is over-quota — so a pool that skews
        // toward already-capped brands still fills every slot instead of
        // stopping short.
        while (count($picks) < self::MAX_PICKS) {
            $next = $pickBrandAware($scored, fn (Product $p) => true, 'overall');

            if ($next === null || !$addPick($next, 'overall')) {
                break;
            }
        }

        if (count($picks) < self::MIN_PICKS) {
            throw new \RuntimeException(sprintf(
                'Category "%s" is not ready for a landing page: only %d eligible pick(s) found (minimum %d required).%s',
                $category->name,
                count($picks),
                self::MIN_PICKS,
                $awaitingNote,
            ));
        }

        return $picks;
    }

    /**
     * Addendum A §2a: true if any offer's raw_title or the product's own ai_summary
     * matches a condition marker (renewed/refurbished/open box/pre-owned/used).
     */
    private static function hasConditionMarker(Product $product): bool
    {
        if (ProductConditionGuard::matchesSummary($product->ai_summary)) {
            return true;
        }

        foreach ($product->offers as $offer) {
            if (ProductConditionGuard::matchesTitle($offer->raw_title)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if ANY of the product's offers is pick-eligible — purchasable (priced,
     * free of a negative condition and of a pick-excluding listing flag) AND
     * health-checked at least once (see {@see ListingHealth::isPickEligible()},
     * shared with AuditLandingPageFreshness). Condition-MARKER exclusion
     * (renewed/refurbished/open box/pre-owned/used text in raw_title/ai_summary)
     * is checked separately, at the product level, by hasConditionMarker() above
     * — that's a weaker, text-based signal this DOM-verified `condition` column
     * check doesn't replace.
     *
     * Fixed 2026-08-12 (prod incident): the prior version only inspected the
     * product's `best_offer`, but `Product::bestOffer` itself excludes null-price
     * offers. A product whose ONLY offer had gone flagged + null-price (e.g. an
     * Amazon listing a category rescan found "Currently unavailable", price
     * cleared) then had no best_offer at all — the flag check had nothing to
     * inspect and silently passed the product as eligible. It was picked and
     * ranked #1 "Best Overall" on a live landing page with no purchasable offer.
     * Checking every offer directly means the flag check can never be skipped by
     * best-offer absence: a pick the reader can't buy is not a pick.
     *
     * B2 / M3 (2026-08-16 audit): this was the only one of the four "is this
     * offer purchasable" copies that omitted the `NEGATIVE_CONDITIONS` check —
     * a product whose sole eligible offer was `condition: 'renewed'` (clean
     * title, so hasConditionMarker() missed it — extension v1.4 added non-title
     * condition sources) was selectable as a pick, rendered with no price/CTA,
     * and left the page permanently `pick_ineligible` (Select and Audit
     * disagreed). Now delegates to the shared predicate so all four call sites
     * can never drift again.
     */
    private static function hasEligibleOffer(Product $product): bool
    {
        return $product->offers->contains(fn ($offer) => ListingHealth::isPickEligible($offer));
    }
}
