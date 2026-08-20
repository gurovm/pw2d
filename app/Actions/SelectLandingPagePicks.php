<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Services\ProductScoringService;
use App\Support\ListingHealth;
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
     * Spec 034 §1 — words that precede a model-number token but carry no
     * identity of their own (generic marketing/category language), so they
     * must never be folded into the model key.
     */
    private const MODEL_TOKEN_STOPWORDS = [
        'series', 'gen', 'edition', 'professional', 'espresso',
        'machine', 'coffee', 'super', 'automatic', 'fully',
    ];

    /**
     * Spec 034 §1 — a preceding qualifier token (e.g. "giga") is folded into
     * the model key only when both it AND the bare candidate token are short.
     * Long candidate tokens (e.g. a full SKU like "ecam29043sb") are already
     * self-identifying and must NOT be prefixed — verified against the
     * spec's hand-checked table (Magnifica Evo ECAM29043SB -> "ecam29043sb",
     * not "evoecam29043sb"). See docs/questions.md for this judgment call.
     */
    private const MODEL_TOKEN_MAX_JOIN_LEN = 5;

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
            // Spec 029 amendment (2026-08-10, `unavailable` added 2026-08-12; fixed
            // 2026-08-12 to check every offer instead of only `best_offer` — see
            // hasEligibleOffer() below): a product is pick-eligible only if it has at
            // least one offer a reader could actually buy today — priced and free of
            // any pick-excluding flag (`high_price` = bad deal today, `unavailable` =
            // nothing to buy today). The product itself stays visible elsewhere on the
            // site; it's excluded from pick eligibility only, not ignored outright.
            ->reject(fn (Product $p) => !self::hasEligibleOffer($p))
            ->values();

        if ($products->isEmpty()) {
            throw new \RuntimeException(
                "Category \"{$category->name}\" has no eligible products for a landing page "
                . '(need: fully processed, not ignored, image + ai_summary present).'
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
        $isDuplicateOfPicked = function (Product $candidate) use (&$pickedIds, $products): bool {
            $candidateKey = self::modelKey($candidate);

            foreach ($pickedIds as $pickedId) {
                $picked = $products->firstWhere('id', $pickedId);

                if ($picked === null) {
                    continue;
                }

                $pickedKey = self::modelKey($picked);

                if ($candidateKey !== null && $pickedKey !== null) {
                    // Both sides have a confirmed model identity — it alone
                    // decides. Equal -> duplicate. Different -> definitively
                    // not a duplicate; do NOT fall through to similarity.
                    if ($candidateKey === $pickedKey) {
                        return true;
                    }

                    continue;
                }

                $candidateNorm = self::normalizeName($candidate->name);

                if ($candidateNorm === '') {
                    continue;
                }

                $pickedNorm = self::normalizeName($picked->name);

                if ($pickedNorm === '') {
                    continue;
                }

                if (str_contains($candidateNorm, $pickedNorm) || str_contains($pickedNorm, $candidateNorm)) {
                    return true;
                }

                similar_text($candidateNorm, $pickedNorm, $percent);

                if ($percent >= 85.0) {
                    return true;
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
                'Category "%s" is not ready for a landing page: only %d eligible pick(s) found (minimum %d required).',
                $category->name,
                count($picks),
                self::MIN_PICKS,
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
     * True if ANY of the product's offers is purchasable — priced, free of a
     * negative condition, and free of a pick-excluding listing flag (see
     * {@see ListingHealth::isPurchasable()}). Condition-MARKER exclusion
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
        return $product->offers->contains(fn ($offer) => ListingHealth::isPurchasable($offer));
    }

    /**
     * Lowercase, strip everything but alphanumerics — the normalization Addendum A §2b's
     * duplicate guard compares ("Keychron Q6 Max Black" vs "Keychron Q6 Max - Black" both
     * normalize to "keychronq6maxblack").
     */
    private static function normalizeName(?string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $name)) ?? '';
    }

    /**
     * Spec 034 §1 — the product's identity key: `{brand_id}:{model token}`, or
     * null when the product has no brand or no digit-bearing token at all (e.g.
     * "Gaggia Cadorna Prestige"), in which case $isDuplicateOfPicked falls back
     * to the normalized-name similarity guard instead.
     *
     * Two products are variants of the same machine when this key is non-null
     * and equal on both sides — verified against Spec 034's real-name table:
     *   "JURA Z10 Super-Automatic Espresso Machine - Gen 1"  -> z10
     *   "Jura Z10 Aluminum White"                            -> z10 (same machine)
     *   "JURA GIGA 10 Espresso Machine"                      -> giga10
     *   "Jura GIGA X8 Professional"                          -> gigax8
     *   "JURA X10 Dark Inox" / "JURA J10 Twin"               -> x10 / j10 (distinct)
     *   "Philips 4400 Series Fully Automatic"                -> 4400
     *   "De'Longhi Magnifica Evo ECAM29043SB"                -> ecam29043sb
     */
    private static function modelKey(Product $product): ?string
    {
        if ($product->brand_id === null) {
            return null;
        }

        $tokens = self::tokenize($product->name);

        // Candidate tokens are those containing at least one digit; take the
        // FIRST one in name order — model numbers lead, generation/SKU suffixes
        // trail, so this drops "Gen 1" and trailing catalogue numbers for free.
        $candidateIndex = null;

        foreach ($tokens as $i => $token) {
            if (preg_match('/\d/', $token) === 1) {
                $candidateIndex = $i;
                break;
            }
        }

        if ($candidateIndex === null) {
            return null;
        }

        $modelToken = $tokens[$candidateIndex];

        // Join to the immediately-preceding alpha token (e.g. "giga" + "10" ->
        // "giga10") only when BOTH sides are short (<= MODEL_TOKEN_MAX_JOIN_LEN)
        // and the preceding token is not the brand name or a generic stopword.
        // The candidate-side length check keeps an already-self-identifying long
        // SKU (e.g. "ecam29043sb") from being prefixed with unrelated line-name
        // copy ("evo") it doesn't need — see the class-const doc comment.
        if ($candidateIndex > 0 && strlen($modelToken) <= self::MODEL_TOKEN_MAX_JOIN_LEN) {
            $prevToken = $tokens[$candidateIndex - 1];

            if (
                preg_match('/\d/', $prevToken) !== 1
                && strlen($prevToken) <= self::MODEL_TOKEN_MAX_JOIN_LEN
                && !in_array($prevToken, self::MODEL_TOKEN_STOPWORDS, true)
                && !in_array($prevToken, self::tokenize((string) $product->brand?->name), true)
            ) {
                $modelToken = $prevToken . $modelToken;
            }
        }

        return $product->brand_id . ':' . $modelToken;
    }

    /**
     * Lowercase and split on every run of non-alphanumeric characters, dropping
     * empty tokens (e.g. "De'Longhi Magnifica Evo ECAM29043SB" -> ["de",
     * "longhi", "magnifica", "evo", "ecam29043sb"]).
     *
     * @return list<string>
     */
    private static function tokenize(string $value): array
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($value)) ?? '';

        return array_values(array_filter(explode(' ', trim($normalized)), fn (string $t) => $t !== ''));
    }
}
