# Review: Spec 024 — Compare-Page CWV Initial-Render Weight Cut (F31)

**Date:** 2026-06-27
**Reviewer:** Code Reviewer (Claude)
**Scope:** `app/Livewire/ProductCompare.php`, `app/Support/SeoSchema.php` (read-only, downstream), `resources/views/livewire/product-compare.blade.php`, `tests/Feature/Compare/CompareRenderLimitTest.php`
**Status:** Needs changes — 1 BLOCKER (SEO/schema regression, #6)

---

## BLOCKER (must fix before ship)

### B1 — ItemList schema + meta description now reflect 6 products instead of 12 (SEO regression)
**Where:** `app/Livewire/ProductCompare.php:619` → `app/Support/SeoSchema.php:52, 294-297, 324, 407-468`

`render()` passes the (now `renderLimit`-capped) collection into the SEO builder:

```php
// ProductCompare::render() line 613-621
$seo = SeoSchema::forCategoryPage(
    ...,
    $this->visibleProducts,   // <-- now capped at min(renderLimit=6, displayLimit) on the initial render
    $this->activePreset,
);
```

`SeoSchema::forLeafCategory()` builds **two** SEO artifacts from that exact collection:

1. **ItemList JSON-LD** — `buildItemListSchema()` iterates `$visibleProducts` (`SeoSchema.php:423`). Before Spec 024 the initial server render emitted **12** `ListItem`s; it now emits **6**. Googlebot does not execute the `x-intersect` → `$wire.revealMore()` round-trip, so the crawled/indexed ItemList is permanently halved. The whole point of Spec 024 is an SEO ranking gain on the proven compare surface — shipping it while *shrinking* the ItemList rich result on the same page is self-defeating.
2. **Meta description** — `SeoSchema.php:294-297` builds `"Compare {$productCount} top {$category->name}..."` from `$visibleProducts->count()`. This now renders `Compare 6 top ...` instead of `Compare 12 top ...`. Minor, but it is user/SERP-facing copy regressing as a side-effect of a render-perf change.

Spec 024 §2 ("Out of scope: content / schema") and §5.4 ("Confirm the page still passes the Spec 022/023 schema checks") make clear the schema set must NOT change. It did.

**Root cause:** the ItemList schema source was coupled to the render window. Pre-024 that coupling was harmless because the render window *was* the full intended set (12). Spec 024 split "what we render initially" from "what the page represents", but the schema source was not re-pointed.

**Fix (pick one, A preferred):**
- **A — feed the schema the intended full set, not the initial render window.** Pass a separate, `displayLimit`-bounded collection to `SeoSchema::forCategoryPage()` for schema purposes, e.g. expose a `schemaProducts()` computed that returns the top `displayLimit` scored products (12) with the same full-data eager-load + score-sync as `visibleProducts()`, and pass `$this->schemaProducts` at `ProductCompare.php:619`. This restores the 12-item ItemList and the "Compare 12 top" copy regardless of how many cards are server-rendered. Cost: one extra `whereIn` of 12 rows (the work Spec 024 explicitly removed from the *render* path) — acceptable because it feeds `<head>`, not the visible DOM weight that LCP cares about. Document why it is intentionally decoupled from `renderLimit`.
- **B — build ItemList from `scoredProducts` (lightweight) capped at `displayLimit`.** `scoredProducts` already holds the full scored set as light objects but lacks `name`/`slug`/`offers`/`ai_summary` (it only selects `id, brand_id, amazon_rating, price_tier`), so `buildItemListSchema()` would emit empty `name`/`url`. Not viable without widening the `scoredProducts` select — so prefer A.

**Regression test to add (this is the gap that let it through):** assert the ItemList `itemListElement` count in the **initial HTTP render** equals `min(displayLimit, scoredCount)` (i.e. 12 for a 14-product category), not 6. The existing `SeoSchemaTest` feeds `SeoSchema` hand-built collections directly and never exercises the live `visibleProducts`→ItemList wiring, and `CompareRenderLimitTest` asserts card count but never schema count — so neither suite catches this. Add a test that does `$this->get('/compare/{slug}')`, extracts the ItemList JSON-LD, and counts `itemListElement`.

---

## SHOULD-FIX (recommended)

### S1 — Skeleton height is an approximation of real card height; verify CLS empirically
**Where:** `product-compare.blade.php:342` (skeleton `h-85 md:h-97.5`) vs the real card (`:238-330`, no fixed height — `flex flex-col h-full` over image `h-44 md:h-52` + variable body).

Grid container classes match exactly between the real grid (`:234`) and the sentinel grid (`:337`) — `grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-1.5 md:gap-5` — good, that is the important part. But the skeleton uses a *fixed* height (340px / 390px) while the real card height is content-driven (brand row + 2-line title `min-h-8/min-h-10` + score bar + price + CTA on top of the 176/208px image). These are close but not guaranteed equal, and the reveal swaps a separate skeleton grid out while a taller/shorter real-card row grows in. CLS is itself a CWV (Spec 024 §6), so a mismatch here partially eats the LCP win.

The automated suite cannot measure CLS (it renders HTML, not layout — same blind spot as the Spec 025 Alpine lesson in `docs/lessons.md`). **Action:** before/at deploy, load `/compare/mechanical-gaming-keyboards?preset=streamer`, scroll to trigger the reveal, and watch the Layout Shift overlay (Chrome DevTools Rendering → "Layout Shift Regions" / Performance panel CLS). If the swap shifts, nudge `h-85/h-97.5` to match a measured real card, or set a `min-h` on the real card's row to lock it. Tailwind v4 resolves `h-85`/`h-97.5` via the dynamic spacing scale, so the values themselves are valid.

### S2 — Spec called for "6 real + 6 skeleton" (Option B); implementation renders skeletons only when 7+ products remain — fine, but note the single-reveal-to-12 assumption
**Where:** `revealMore()` `ProductCompare.php:308-311`, sentinel `product-compare.blade.php:336-345`.

The state machine is correct and loop-free (each `revealMore()` strictly increases `renderLimit` toward `displayLimit`; the H2H/staging guard in `hasMoreToReveal()` at `:594` exactly mirrors the exempt branches in `visibleProducts()`; `x-intersect.once` prevents re-fire). No off-by-one found across the 6→12→(loadMore)→24 walk — `CompareRenderLimitTest::load_more_re_arms_sentinel_and_reveal_more_continues` proves it.

One observation, not a bug: the sentinel always renders exactly **6** skeletons (`@for ($i = 0; $i < 6; $i++)`), but the gap-to-`displayLimit` can be smaller (e.g. only 2 products remain between `renderLimit` and the real count). In that case 6 skeletons appear and only ~2 real cards replace them, so 4 skeleton slots collapse on reveal — a downward shift. Low impact (most leaf categories have 12+ products) but worth capping the skeleton count at `min(6, scoredCount - renderLimit, displayLimit - renderLimit)` if S1 measurement shows shift. Pass that number from the component rather than computing it in Blade (keeps logic out of the view).

---

## NITPICK

### N1 — `hasMoreToReveal()` is passed to the view two ways
**Where:** `ProductCompare.php:634` passes `'hasMoreToReveal' => $this->hasMoreToReveal()` as view data, and the method is also public/callable. Harmless, but the PHPDoc at `:577-589` explaining "not marked `#[Computed]`" is good — keep it. No change needed; calling it once in `render()` and reusing the bool is correct.

### N2 — Comment accuracy in `visibleProducts()`
**Where:** `ProductCompare.php:281` comment says "DB/scoring work shrinks too." The DB *fetch* of full product data shrinks (6 rows vs 12) — correct and verified. But "scoring" does NOT shrink: `scoredProducts` still scores the entire 200+ set (correct per Spec 024 §2 "no change to scoredProducts"). The comment slightly overstates. Reword to "full-data fetch shrinks (scoring is unchanged — see scoredProducts)" to avoid implying the scoring cost dropped.

---

## Verified GOOD (no action)

- **Performance / #1:** Normal path (`ProductCompare.php:276-285`) fetches full data via `Product::whereIn('id', $topIds)` where `$topIds` is the top `min(renderLimit=6, displayLimit)` — the eager-loaded full-data query genuinely shrinks from 12 rows to 6 on initial load, not just the HTML. `with(['brand','featureValues.feature','offers.store'])` covers every relation the card touches — **no N+1**.
- **#1 scoring untouched:** `scoredProducts()` (`:150-210`) still scores the full cached set; only the render/hydrate count was cut. Matches Spec 024 §2.
- **State machine / #2:** No off-by-one, no infinite sentinel, no "sentinel shows but reveal can't advance" case. `revealMore()` is `min(+6, displayLimit)` — always progresses, always capped (`CompareRenderLimitTest` tests 3, 5, 11). H2H + pinned-staging guard in `hasMoreToReveal()` (`:594`) correctly returns false and exactly matches the two exempt branches in `visibleProducts()` (`:230`, `:252`).
- **Score-sync / #3:** All three branches (H2H, staging, normal) preserve the `pluck('id')->map(...keyBy...)` order-restore + `match_score`/`feature_scores` attach. Tests 8, 12 cover it.
- **CLS grid footprint / #4:** Sentinel grid column/gap classes match the real grid exactly (see S1 for the height caveat).
- **Alpine safety / #5:** `x-intersect.once="$wire.revealMore()"` is a clean call expression. No bare `const`/`let` introduced anywhere; the pre-existing `x-init` at `:235` (auto-animate import) and `:395` (`setTimeout`) are expression-form and fine. No Spec 025 regression.
- **Standards / #7:** Component stays thin; strict typing on new methods (`revealMore(): void`, `hasMoreToReveal(): bool`); no business logic leaked into Blade beyond the existing `$bestMatchId` `@php`. PHPDoc on new members is thorough.
- **Tests:** 17 new tests, `RefreshDatabase`, factories with explicit `slug`, happy + edge (≤6, exactly-6, exactly-8) + exemptions covered. Only gap is the ItemList schema-count assertion (see B1).

---

## Ship verdict

**Do not ship as-is.** One real blocker (B1): the `renderLimit` cut silently halves the ItemList JSON-LD and the "Compare N top" meta description on the server-rendered (= crawled) page, undoing part of the SEO gain this spec exists to deliver. Decouple the schema source from the render window (fix A) and add an initial-HTTP-render ItemList-count regression test. S1 (CLS measurement) must be done manually at deploy since the suite can't see layout shift. Everything else is solid and ships once B1 is resolved.
