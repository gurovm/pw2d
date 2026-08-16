# Review: Post-029b audit — Specs 029 §A2a/A2b/A2c (Fixes 1–3), 030, 031 (picks scope + extension v1.5/1.6)

**Date:** 2026-08-16
**Range:** `f9a1115..HEAD`, excluding what `docs/reviews/029b-extension-review.md` already covered (commit 614057f + its 8 fixes)
**Reviewer:** @reviewer
**Status:** **SHIP-WITH-FIXES** — 4 blockers, all small and local; nothing structural is wrong.

---

## Scope actually reviewed

`app/Actions/{AuditLandingPageFreshness,SelectLandingPagePicks}.php`,
`app/Http/Controllers/Api/{BatchImportController,OfferIngestionController,ProductImportController,TenantListController}.php`,
`app/Models/Product.php` (+ `ProductOffer`), `app/Services/{ListingHealthService,OfferIngestionService,PriceTierRecalculator}.php`,
`app/Support/{ListingHealth,ProductConditionGuard}.php`, `app/Jobs/AuditLandingPageFreshnessJob.php`,
`app/Observers/ProductObserver.php`, `routes/api.php`, `chrome_extension/*`, and the 11 test files.
Downstream callers audited independently of the builder's claim: `ProductCompare`, `SeoSchema`, `ProductResource`,
`ProblemProducts`, `RescanProductFeatures`, `ProcessPendingProduct`, `RecalculatePriceTiers`,
`_pick-card.blade.php`, `product-compare.blade.php`, `similar-products.blade.php`.

---

## Critical Issues (must fix)

### B1. `ProductConditionGuard`'s bare `used` marker is a substring match — and Fix 1 just handed it veto power over a DOM-verified `new`

`TITLE_MARKERS` includes `'used'`, and `firstMatch()` uses `str_contains()`. `"used"` is a substring of
ordinary English words that appear in Amazon/Shopify titles:

| title word | contains `used`? |
|---|---|
| fo**cused** | yes (`f-o-c-`**`u-s-e-d`**) |
| h**oused** | yes |
| **unused** | yes |
| **paused** / aro**used** | yes |

Before Fix 1 this only fired when the payload sent no `condition` at all. `resolveEffectiveCondition()`
branch 3 now makes a title marker beat an explicit `condition: 'new'`, i.e. **the exact payload the
extension sends for every affirmatively-verified clean Amazon page** during the Phase C mass rescan.
Consequence per offer, silently, with no owner-visible reason beyond one log line:

```
OfferIngestionService (existing-offer/rescan branch)
  → resolveEffectiveCondition('new', 'Blue Yeti … focused cardioid pickup') === 'used'
  → ListingHealthService::apply() stores condition='used' on the offer
  → hasCleanOffer() false for a single-offer product → products.is_ignored = true
  → product drops out of bestOffer/best_price/affiliate_url, out of pick eligibility,
    and only a human can reverse it (Spec 029 non-goal).
```

The extension's own `conditionMarkerFromText()` (content.js:65–77) already solves this correctly —
`/\(\s*(?:certified\s+)?used\b[^)]*\)/` or leading `^\s*used\b` — and 029B-S3 explicitly notes
"Server-side guard behavior deliberately unchanged". That deliberate asymmetry was tolerable when the
server guard only *skipped an import*; it is not tolerable now that it *ignores existing products* and
overrides a stronger signal.

**Fix:** port the extension's positional guard into `ProductConditionGuard` (replace the naive
`str_contains` marker list with anchored patterns: `\brenewed\b`, `refurbish`, `open[\s-]?box`,
`\bpre-?owned\b`, parenthetical/leading `used`). Add the false-positive cases as tests
(`ProductConditionGuardTest` currently has zero). **Ops follow-up:** the ~150 listings removed in the
2026-08-12→16 rollout should be spot-checked for `condition='used'` rows whose `raw_title` has no
genuine condition marker — those are false ignores.

### B2. `SelectLandingPagePicks` can still select a product whose `best_offer` is `null`

Three predicates that are documented as mirrors of each other have drifted:

| predicate | price non-null | price > 0 | NEGATIVE_CONDITIONS | PICK_EXCLUDING_FLAGS |
|---|---|---|---|---|
| `Product::bestOffer` (Fix 3) | ✅ | ❌ | ✅ | ✅ |
| `ListingHealthService::hasCleanOffer` | ✅ | ✅ | ✅ | ✅ |
| `AuditLandingPageFreshness::hasEligibleOffer` | ✅ | ✅ | ✅ | ✅ |
| **`SelectLandingPagePicks::hasEligibleOffer`** | ✅ | ✅ | **❌** | ✅ |

`SelectLandingPagePicks` compensates with the product-level `hasConditionMarker()` text check over
`raw_title`/`ai_summary` — but extension v1.4 deliberately added **non-title** condition sources
(schema.org `itemCondition`, `<meta product:condition>`, badge/breadcrumb, Amazon's `#bylineInfo`
"Amazon Renewed"). A `condition='renewed'` offer with a clean `raw_title` is now a normal, expected row.

Reachable sequence (all steps are ordinary rescan outcomes):

1. Product P has offer A (Amazon, $200, clean) and offer B (WLL, $180, `condition='refurbished'`,
   clean title). `hasCleanOffer()` sees A → product stays visible, action `flagged_offer_condition`. ✅
2. A later rescan finds A `unavailable` → flag stored on A, product still visible. ✅
3. `SelectLandingPagePicks`: `hasConditionMarker` false (titles clean); `hasEligibleOffer` **true**
   (B is priced and unflagged — condition never checked) → **P is selected as a pick**.
4. Render: `Product::bestOffer` excludes A (flag) and B (condition) → `null`.
   `_pick-card.blade.php` renders the card with **no price block** (`estimated_price === null`) and
   **no CTA** (`@if ($product->affiliate_url)`) — a "Best Overall" card with nothing to click.
5. `AuditLandingPageFreshness` marks the page `pick_ineligible` forever, and regeneration re-selects
   the same product, because Select and Audit disagree about eligibility.

This is the same shape as the 2026-08-12 prod incident, re-entered through the condition dimension that
Fix 3 introduced into `bestOffer`.

**Fix:** add `&& !in_array($offer->condition, ListingHealth::NEGATIVE_CONDITIONS, true)` to
`SelectLandingPagePicks::hasEligibleOffer()` (keep `hasConditionMarker()` — it catches the
title/summary cases Select needs on top). Do it as part of S2 (one shared predicate) rather than a
fourth copy. Regression test: pick-eligible ⇒ `best_offer !== null`, asserted for every returned pick.

### B3. One detached pick aborts the entire weekly "Verify Live Picks" run

Server (`picksScopeOffers`) deliberately applies **no** `is_ignored`/`status`/category filter — correct,
per Spec 031 ("a pick that has drifted … is exactly what this pass exists to catch"). But a pick whose
product was detached by an AI sweep has `category_id = null`, so its row carries `category_id: null`,
and popup.js:360-367 refuses the whole run:

```js
const missing = offers.filter((o) => !o.category_id).length;
if (missing > 0) {
    showError(`Server returned ${missing} pick(s) without category_id — the picks list must include it. Update the server first.`);
    return;   // ← blocks all ~100 offers over one detached product
}
```

Detached picks are a first-class staleness reason (`pick_ineligible`, `render_short` both exist for
exactly this), so this is not a theoretical state — and the error text sends the owner to fix a server
that is behaving as designed. `RescanListControllerTest` covers ignored/pending picks (line 286) but not
the detached one, which is why it slipped.

**Fix:** either (a) server drops rows whose product has no `category_id` (they can't be re-ingested
anyway — `ingest-offer` requires a tenant-valid `category_id`) and the response reports the count, or
(b) extension skips those rows individually, tallies them as `skipped`, and only aborts if *every* row
is unusable. (a) is simpler and keeps the client dumb. Add the regression test either way.

### B4. A `flagged_offer_condition` pick is reported to the owner as clean, and never names its guide

`ListingHealthService::ACTION_FLAGGED_OFFER_CONDITION` is brand new in this range (Fix 2). Neither
extension tally knows about it:

- `background.js:383-385` — `flagged_condition` / `skipped_condition` / **else `updated`** → a
  refurbished multi-store listing counts as a successful update.
- `background.js:394-399` — `noteFlaggedGuide()` fires on `flagged_condition`, `skipped_condition`,
  `high_price`, `unavailable` — **not** `flagged_offer_condition`. So the *guides* line, which Spec 031
  §"First live run" identifies as the signal that "covered for" the known counter undercount, has the
  same hole.
- `popup.js:822-831` `actionLabels` has no entry → the single-import path prints the raw string
  `flagged_offer_condition` at the owner.

Net effect on the weekly Tier 1 pass: a pick whose best offer went refurbished/renewed while a sibling
offer survives — precisely the case Fix 2 was written for — produces `updated N · flagged 0`, no guide
named, amber never shown. The owner's documented response rule ("full category rescan of the named
guide") is never triggered. The counter half of this is already logged in `docs/tasks/todo.md:269`; the
`flagged_guides` half is not, and it is the one that matters.

**Fix:** treat `flagged_offer_condition` exactly like `flagged_condition` in `noteFlaggedGuide()`'s
condition set and in the tally (`results.flagged_condition++` or a new counter), and add the
`actionLabels` entry. Three-file rule does not apply (background.js + popup.js only, no endpoint change),
but bump the manifest anyway since the popup's visible output changes.

---

## Suggestions (recommended improvements)

**S1. `ProductCompare::scoredProducts()` was deliberately left on the old price definition — the
rationale doesn't hold.** `docs/tasks/todo.md:317` justifies it with Spec 029's "Compare pages
unchanged for now". That precedent is about not *hiding* flagged products from compare pages; it says
nothing about which price the page reasons with. What actually happens now:

- `'best_price' => $p->offers->min('scraped_price')` (line 193) — includes refurbished / `high_price` /
  `unavailable` offers, and only selects `offers:id,product_id,scraped_price`.
- That value becomes `estimated_price`, which feeds `ProductScoringService`'s **price dimension**, and
  the price slider filters on raw `offers.scraped_price` too (line 186).
- The same card's `$$$` badge comes from `price_tier`, which `PriceTierRecalculator` now computes from
  the **filtered** `best_price`, and the CTA comes from `affiliate_url` → filtered `best_offer`.

So "best mic under $200" can surface a product that is only under $200 via a refurbished listing, rank
it well on price, badge it `$$$`, and link to the $350 clean offer. One card, three price definitions.
Not a blocker (no literal price string is rendered on the compare grid), but it is the same class of bug
Fix 3 was called in to kill, and it lives on the highest-traffic surface. Fix by selecting
`condition,listing_flags` in that eager load and applying the shared predicate before `min()`; the
cache key already varies by filter so no invalidation work is needed.

**S2. Four copies of "is this offer purchasable", three different definitions.** See the B2 table.
Extract one predicate — e.g. `ListingHealth::isPurchasable(ProductOffer $o, bool $requirePositivePrice = true): bool`
plus a `ListingHealth::OFFER_HEALTH_COLUMNS = ['condition','listing_flags']` constant to append to every
narrow `offers:` eager load — and have `Product::bestOffer`, `ListingHealthService::hasCleanOffer`,
`SelectLandingPagePicks`, and `AuditLandingPageFreshness` all call it. `.claude/memory/builder/patterns.md:457`
already records the narrow-select trap; a constant makes it mechanical instead of remembered.

**S3. `Product::bestOffer` accepts `scraped_price = 0`.** It filters `!== null` only, while all three
mirrors require `> 0`, and every ingestion endpoint validates `nullable|numeric|min:0` — a `0.00` is
storable. Sorted ascending, a `$0` offer wins every time: `best_price` 0, `estimated_price` 0 (renders
`~$0` on a pick card, since `0 !== null`), `price_tier` 1. One-character fix, aligns the four predicates.

**S4. The `unknown` branch downgrades a stored negative condition and erases its own evidence.**
`ListingHealthService::apply()` writes `condition => 'unknown'` unconditionally, so an offer previously
stored as `refurbished` (the reason its product is `is_ignored`) becomes `unknown` — which is *not* in
`NEGATIVE_CONDITIONS`, so it immediately re-enters `bestOffer`/`hasCleanOffer`/`hasEligibleOffer` while
the product stays ignored. `logIfRecoveringWhileIgnored()` returns before it can fire. The extension
strips `unknown` (029B-B3) so this needs a non-extension client today, but `unknown` is in the public
`ListingHealth::CONDITIONS` vocabulary and all three endpoints accept it. Suggest: never downgrade a
stored negative condition to `unknown` (stamp `health_checked_at` only), or at minimum log through
`logIfRecoveringWhileIgnored()`.

**S5. The instant freshness path is blind to `flagged_offer_condition`.** The negative-condition branch
dispatches no `AuditLandingPageFreshnessJob`, and when a clean sibling survives, `is_ignored`/`category_id`
don't change either, so `ProductObserver` never fires. Yet the product's `best_offer` (hence
`estimated_price`) can jump substantially at that moment — exactly the `price_drift` the nightly audit is
for. Spec 031 already learned this ("Always run `pw2d:landing-pages:audit` after a picks pass"), so the
nightly covers it; still, adding the same `wasChanged()` dispatch to the negative branch is two lines and
removes a documented manual step.

**S6. A price-null sibling silently escalates an offer flag into a product ignore.**
`hasCleanOffer()` requires `scraped_price > 0`, so a product whose second offer merely failed price
extraction (a live, logged bug — `docs/tasks/todo.md:263`, 2 of 31 Clive offers) gets `is_ignored = true`
when its other offer goes refurbished, and only a human can reverse it. Consider logging the near-miss
("ignored because the only sibling offer had no price") so the owner can tell a genuine dead product from
a scraper gap.

**S7. Controller thinness — Q2/Q3 are now overdue, not deferred.** `BatchImportController::import()` is
~200 lines with a nested create/refresh split, an inline dead-listing heuristic, and four counters;
`OfferIngestionController` now carries four private query builders and two docblocks longer than the
methods. Both were already logged (`todo.md` Q2/Q3) when they were half this size. Recommend, in this
order: (1) `RescanWorkList` query object for `categoryScopeOffers`/`picksScopeOffers`/`pickProductSlugs`
— it is a read model, not controller work, and is the only untested-in-isolation piece of Spec 031;
(2) `BatchImportService`; (3) `OfferIngestionRequest`/`RescanListRequest` Form Requests (the `scope`-
dependent rules array in `rescanList()` is exactly what `FormRequest::rules()` conditionals are for).

**S8. Test gaps worth closing alongside the blockers.** No test for: a condition-marker false positive
(B1); pick-eligible ⇒ `best_offer !== null` (B2); a `scope=picks` row for a detached product (B3);
`flagged_offer_condition` tallying (B4, untestable in PHP — note it in the extension QA checklist);
and — the highest-value structural one — a test that a narrow `offers:` eager load still applies the
exclusion, i.e. that nobody can silently disable Fix 3 by forgetting a column.

**S9. Audit-dispatch volume during a rescan walk.** Every material flag change dispatches
`AuditLandingPageFreshnessJob`, each of which re-runs `SelectLandingPagePicks` over the whole category
(all products + offers + full scoring). A 150-offer category sweep can queue ~150 of these against 2
workers. `uniqueFor = 600` collapses same-page bursts only while a job is pending, so this is partly
mitigated, not solved. Consider `->delay(60)` on the instant path, or keying `ShouldBeUnique` release on
completion.

---

## Nitpicks

- **N1.** `popup.js` `actionLabels` missing `flagged_offer_condition` (part of B4) — shows the raw
  enum string to the owner.
- **N2.** `BatchImportController:124` and `ProductImportController:133` set `price_tier` from the single
  incoming payload price, ignoring other stores and the new exclusion, and `priceTierFor(null)` returns
  `null` — a flagged no-price refresh **wipes** the `$$$` badge. `RecalculateCategoryPriceTiers` is
  dispatched right after and computes it correctly; drop the inline assignment.
- **N3.** `PriceTierRecalculator` `continue`s on a null `best_price` without counting it as `skipped`.
  With Fix 3, null `best_price` is materially more common (any all-flagged product), so the command's
  "fixed/skipped" summary now under-reports what it didn't touch.
- **N4.** `logIfRecoveringWhileIgnored()`'s notice carries only `product_id` — no tenant, name, or
  offer id — and has no queryable surface (no Filament column/badge). The "human decides reversal"
  loop currently depends on grepping logs.
- **N5.** `ListingHealthService`'s class docblock says the negative branch "always" stores condition +
  flags but omits that it also **clears** flags to `[]` when the payload carries none — worth one line,
  since that's the interaction B4's reviewers will look for.
- **N6.** `basename(parse_url($url, PHP_URL_PATH))` ASIN extraction is now in four places
  (`categoryScopeOffers`, `picksScopeOffers`, `existingAsins`, `BatchImportController`). One helper.
- **N7.** `declare(strict_types=1)` missing on `Product.php` and `BatchImportController.php` — both
  edited in this range, both listed under CLAUDE.md's "clean, modern PHP 8.3 (strict types…)".
  Everything else new in the range has it.
- **N8.** `popup.js` (~900 lines) is at the edge, not over it — the picks work *reduced* branching by
  parameterising `beginRescanRun(scope)`. If it grows again, split settings/batch/rescan into ES modules
  (MV3 popups support `type="module"`) before adding a fourth mode.

---

## Praise

- **Fix 3 was done in the right place and its blast radius was genuinely traced.** `bestPrice` deriving
  from `bestOffer` (rather than a parallel `min()`) is the correct structural fix, and the two narrow
  `offers:` eager loads that would have silently disabled it (`PriceTierRecalculator`,
  `SelectLandingPagePicks`) were both found and fixed with an explanatory comment at each site. The one
  caller that was consciously skipped (`ProductCompare`) was written down rather than missed — I disagree
  with the call (S1) but the audit itself was real.
- **`resolveEffectiveCondition()` is exactly the right shape:** one static helper, four documented
  branches, a precedence table in the docblock, a dedicated unit test file covering the whole matrix, and
  all three ingestion sites converted. The B1 defect is inherited from the marker list underneath it, not
  from this design.
- **Fix 2's offer-vs-product distinction is well judged.** Introducing a *distinct* response action
  (`flagged_offer_condition`) instead of overloading the existing one keeps ingestion responses honest,
  and refusing to auto-un-ignore while logging a notice respects the stated non-goal without letting the
  state go silently stale.
- **Spec 031's picks scope avoided the JSON-`LIKE` trap deliberately and documented why**, with a
  pointer to the Spec 030 §B1 lesson. `pickProductSlugs()` is bounded, `picksScopeOffers()` eager-loads
  `product:id,category_id` (no N+1), tenant scoping is explicit and belt-and-braces, and the multi-page
  tie-break is deterministic and documented as arbitrary. 19 tests in that file, including per-row
  `category_id` assertions rather than spot checks.
- **The extension's two modes were parameterised, not forked.** `rescanRun.scope` touching only the
  work-list URL, the wording, and the tally is the correct call, and it means pause/resume/CAPTCHA/
  watchdog/generation-counter behaviour cannot drift between modes. Mutual exclusion is genuinely
  three-way and closed in both directions.
- **`TenantListController`** — the "not behind `InitializeTenancyFromPayload`" decision, the threat model
  for exposing tenant ids, and the "no `data` JSON exposure" constraint are all written down at the class
  level. The `orderByRaw` is safe (`name` is a real column per `Tenant::getCustomColumns()`), which the
  comment anticipates a reader asking.
- **Comment quality throughout the range explains *why*, cites the incident, and names the spec clause.**
  This review was fast to write because of it.
