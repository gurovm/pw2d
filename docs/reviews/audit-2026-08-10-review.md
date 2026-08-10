# Review: Spec 029 Phase A (listing-health ingestion) + Spec 030 (landing-page freshness engine)
**Date:** 2026-08-10
**Reviewer:** code-reviewer agent
**Status:** Needs changes — **BLOCK** (3 blockers, all small and local; the architecture is sound)

Scope: uncommitted changes across the ingestion pipeline (`OfferIngestionService`,
`BatchImportController`, `ProductImportController`, `ListingHealthService`, rescan-list
endpoint, tier-recalc job/service) and the freshness engine (`AuditLandingPageFreshness`,
`AuditLandingPageFreshnessJob`, `ProductObserver`, `AuditLandingPagesCommand`,
`GenerateLandingPage`, `LandingPageResource`), the three `2026_08_10_*` migrations, and
the new/changed tests. Reviewed against `docs/specs/029-extension-rescan-condition.md`
and `docs/specs/030-landing-page-freshness.md`.

---

## Critical Issues (must fix)

### B1 — Spec 030 instant path is dead on MySQL: JSON `LIKE` pattern never matches a native JSON column

`AuditLandingPageFreshnessJob::dispatchForProduct()` (app/Jobs/AuditLandingPageFreshnessJob.php:55-63):

```php
$pattern = '%"product_id":' . $product->id . ',%';
LandingPage::where('tenant_id', $product->tenant_id)
    ->whereRaw('picks LIKE ?', [$pattern])
```

`landing_pages.picks` is a **native MySQL JSON column** (`$table->json('picks')` in
`2026_07_18_120000_create_landing_pages_table.php`). MySQL stores JSON in an internal
binary format; when a JSON value is coerced to a string for `LIKE`, MySQL emits the
**normalized** text form — `{"product_id": 123, "role": ...}` with a space after every
colon and comma. The space-free pattern `"product_id":123,` therefore **never matches in
production**. On sqlite (the test connection, `:memory:` per phpunit.xml), `json()`
creates a TEXT column storing Laravel's raw space-free `json_encode` output, so every
test passes. The docblock's justification ("stored as text under the hood … works
identically on MySQL") is factually wrong for MySQL, and the whole §B3 instant path —
observer ignore-flip/detach/delete AND the `high_price` trigger in
`ListingHealthService` — silently dispatches zero jobs on prod. Only the nightly command
would ever mark pages stale.

**Fix (recommended):** drop the SQL containment entirely and filter in PHP — load the
tenant's landing pages (bounded: ≤ a dozen rows, same reasoning already written in
`ListingHealthService::warnIfLandingPagePick()`) and `collect($page->picks)->contains(...)`.
Alternative: `whereJsonContains('picks', [['product_id' => $product->id]])` if verified
against BOTH connections on this Laravel version. Either way, add a comment pointing at
this finding, and remove the now-unnecessary "product_id MUST stay the first key"
coupling comment in `GenerateLandingPage` (line ~148). A sqlite test cannot prove this
fix; verify once on prod MySQL via tinker after deploy.

### B2 — Title-marker early-skip defeats the rescan flagging path in all three ingestion endpoints

`ProductConditionGuard::matchesTitle()` early-returns `skipped_condition` **before** the
existing-offer lookup and **before** `ListingHealthService::apply()`:

- `OfferIngestionService::processIncomingOffer()` line ~59
- `BatchImportController::import()` line ~71
- `ProductImportController::import()` line ~83

Consequence for the Phase B/C rescan flow: the extension re-scrapes a live listing whose
true raw title contains "(Renewed)" and posts it with `condition: 'renewed'` (per spec
B1, the title parenthetical is a condition signal). The server **skips and touches
nothing**: the stored cleaned `raw_title` is not healed (the exact A1 "cleaned-title
blindness" fix never lands), the offer's `condition` is not set, the product is **not**
ignored, `health_checked_at` is not stamped — so the product stays a live landing-page
pick AND stays at the head of the rescan-list ordering forever (its
`COALESCE(health_checked_at, updated_at)` never advances). This is precisely the
4-marker-blind-renewed-picks scenario Spec 029 exists to close; §A2 requires
`action: flagged_condition` + `is_ignored = true` for renewed.

The early skip is correct for **brand-new** listings only (don't create junk). **Fix:**
resolve the store/existing offer first; when an existing offer matches (or a non-null
`condition` was supplied), run the normal refresh + `ListingHealthService::apply()` —
treating a title-marker hit as condition evidence (coerce `condition` to the matched
marker when the payload didn't supply one). Keep the skip only for the create-new path.
Add tests per path: rescan-refresh with a marker title → offer fields updated,
`flagged_condition`, product ignored, `health_checked_at` stamped.

### B3 — Filament edit silently erases `est_price_snapshot`, permanently disabling `price_drift`

The `picks` Repeater in `LandingPageResource::form()` declares only
`product_id`/`role`/`headline`/`body`. Filament repeaters dehydrate **only declared
fields** — saving the edit form rewrites the picks JSON without `est_price_snapshot`.
The documented owner workflow (generate draft → tweak prose in Filament → publish, per
this resource's own docblock and Spec 027 §8) therefore wipes snapshots on essentially
every page, and `AuditLandingPageFreshness::hasPriceDrift()` correctly skips
snapshot-less picks — so the `price_drift` contract reason is silently dead for any
edited page until the next `--regenerate`. The existing test
(`editing_headline_and_body_persists_...`) seeds picks **without** snapshots, so it
cannot catch this.

**Fix:** add `Forms\Components\Hidden::make('est_price_snapshot')->dehydrated()` (the
same disabled-but-dehydrated trick already used for `product_id`/`role` works too) to
the repeater schema, plus a regression test asserting the snapshot survives a prose
edit.

---

## Suggestions (should fix)

### S1 — Clearing a `high_price` flag never triggers the instant audit
`ListingHealthService::apply()` dispatches `AuditLandingPageFreshnessJob` only when
`high_price` is **present** in the incoming flags. The clean-report branch (which clears
a previously stored `high_price`) dispatches nothing, so: (a) a page marked stale for
`high_price` stays marked until the nightly run even though the listing recovered; (b) a
recovery that changes re-selection is never instantly caught. Spec 030 §B3 says "Offer
condition/**flag changes** … trigger the same hook" — changes in both directions.
Dispatch when the offer's `condition`/`listing_flags` materially changed (compare
before/after around the `update()`), not only on set.

### S2 — Refresh branches wipe a known `amazon_rating` with null
`BatchImportController` (line ~105) and `ProductImportController` (line ~121) both set
`'amazon_rating' => $p['rating'] ?? null` unconditionally on refresh — one rating-less
rescan nulls a previously known rating. This contradicts A1's own principle ("an omitted
field must never blow away a previously known-good one", applied carefully to
image/stock/reviews in the same diffs) and `OfferIngestionService`'s guarded version
(`!empty($data['rating']) && empty($product->amazon_rating)`). It also has SEO teeth:
the Spec 026 rating gate downgrades rating-less products to URL-only ListItems, so a
mass rescan without ratings would degrade published schema. Guard it like reviews_count.

### S3 — `ProductImportController` refresh auto-un-ignores (`'is_ignored' => false`)
The refresh branch resets `is_ignored => false` unconditionally. A product previously
DOM-flagged renewed (clean title) gets un-ignored by any re-import whose payload lacks
`condition` — violating Spec 029's explicit non-goal: "No automatic un-ignoring …
reversal stays a human decision in Filament." The line predates this spec, but 029 is
what makes it consequential. At minimum stop resetting `is_ignored` on refresh.

### S4 — `'unknown'` clean condition is coerced to `'new'`
`ListingHealthService`'s clean branch stores `'new'` "regardless of the reported clean
value (new/unknown)". Spec 029 B1 deliberately distinguishes "affirmatively looks like a
standard listing" (`new`) from "couldn't tell" (`unknown`); persisting `new` for an
`unknown` report overstates what was verified (an `unknown` may be a mangled page where
a renewed marker was simply missed). Store the reported value; still clear flags and
stamp `health_checked_at`.

### S5 — Tier-recalc dispatched on every refresh, even with an unchanged price
`OfferIngestionService`'s URL-refresh branch dispatches `RecalculateCategoryPriceTiers`
unconditionally. During Phase C (~1,070 offers, one request each) that's roughly one
queued job per offer, each chunk-scanning its whole category, on a 2-worker box — pure
churn when the price didn't move. The job's own docblock claims dispatch happens "after
a price changes"; make the code match (compare price before/after, or
`$existingOffer->wasChanged('scraped_price')`). `BatchImportController` already batches
to once per request — good.

### S6 — Inline validation in `OfferIngestionController` (both actions)
`ingest()` grew the new `condition`/`listing_flags` rules inline and the new
`rescanList()` validates inline — `standards.md` requires Form Requests, and the sibling
endpoints (`BatchImportRequest`, `ProductImportRequest`) already comply. Q2 in
`docs/tasks/todo.md` tracks the ingest half; `rescanList` extends the debt. Fold into
Q2's Form Request extraction.

---

## Nits

- **N1** — A `flagged_condition` on a newly created stub leaves `status = 'pending_ai'`
  forever (no `ProcessPendingProduct` dispatched): ignored-but-pending rows will pollute
  pending-based views (e.g. ProblemProducts). Set `status = null` when flagging a stub.
- **N2** — `rescanList()` labels the last URL path segment `asin` for non-Amazon offers
  too (returns e.g. a Shopify slug under the `asin` key). Null it for non-Amazon stores
  or rename the field.
- **N3** — Migration `..._000002` `down()` restores `NOT NULL DEFAULT 0` without first
  backfilling the nulls it allowed — rollback fails on MySQL if any null rows exist. Add
  a `DB::table('products')->whereNull(...)->update([... => 0])` before the `change()`.
- **N4** — `SelectLandingPagePicks` eager-loads offers without `store_id`/`store`, so
  `best_offer`'s commission/priority tiebreakers are inert there; for equal-price
  multi-store products the `high_price` check may inspect a different offer than the one
  the storefront actually links. Add `store_id` to the select + `offers.store` to the
  `with()` (as `AuditLandingPageFreshness` already does).
- **N5** — `LandingPageResource::getNavigationBadge()` cache key degenerates to the
  shared un-scoped `'landing-pages-stale-badge:'` when `tenant('id')` is null (central
  context, where `BelongsToTenant`'s scope is also inactive → cross-tenant count). Low
  risk in practice (Filament always has a tenant set), but return null early or key on
  `'central'`.
- **N6** — `stale_reasons != '[]'` (ternary filter + `orderByRaw` in
  `LandingPageResource`, `staleQuery()`) relies on MySQL's string→JSON coercion for
  comparisons against a JSON column. It should work, but B1 proves sqlite parity can't
  be assumed — verify once in prod tinker after deploy.

---

## Confirmed correct (spec-contract checks that PASS)

- **renewed→ignore-product vs high_price→flag-offer-only:** exactly per the 029
  amendment — `ListingHealthService` ignores the product only for
  `NEGATIVE_CONDITIONS`; `high_price` stays offer-level and `SelectLandingPagePicks`
  excludes only when the **best** offer carries it (per spec wording). Compare pages
  untouched.
- **Clean-report-clears rule:** implemented (condition + flags cleared,
  `health_checked_at` stamped on every explicit report) — modulo S1/S4 above.
- **4 staleness reasons contract:** all four implemented; below-minimum re-selection
  throw correctly counts as `selection_drift`; `render_short` mirrors the controller's
  filter and correctly catches `status` regressions that `pick_ineligible` deliberately
  excludes; picks without snapshots correctly skipped for `price_drift`.
- **Regeneration clears staleness:** `GenerateLandingPage` resets `stale_reasons` to
  `[]`, re-stamps `freshness_checked_at`, and re-snapshots prices — with tests.
- **Tenancy:** both jobs explicitly (re-)initialize from a dispatch-time tenant id with
  the nested-init guard and `finally` teardown; the nightly command initializes per
  tenant in `try/finally`; rescan-list tenant isolation is proven by a genuinely
  adversarial cross-tenant test; `LandingPage::cacheKey()` stays row-derived per the
  Spec 027 S1 lesson. No ambient-tenancy cache-key writes found (N5 is a read-side
  degenerate case).
- **N+1:** `AuditLandingPageFreshness` eager-loads `offers.store`;
  `PriceTierRecalculator` chunks with a scoped offer select (Q13 closed);
  `warnIfLandingPagePick` is a bounded per-category lookup.
- **A4 batching:** `BatchImportController` dispatches tier recalc once per request;
  `updateOrCreate` on `(product_id, store_id)` lands in all three paths (Q1 closed);
  nullable `reviews_count` semantics are consistent across create/refresh with a
  migration to match.

## Praise

- `ListingHealthService` as the single source of truth for all three ingestion paths is
  exactly the right shape — the rules cannot drift between endpoints, and the
  transition-only landing-pick warning is thoughtful.
- `PriceTierRecalculator` extraction (command + job sharing one chunked implementation,
  I/O injected via callback) is textbook.
- Test quality is high: exact-reasons assertions where isolation is possible, honest
  docblocks where cascade overlap is inherent to the domain, the throwing-AiService spy
  in the Filament publish test, and the reflection helper note. The sqlite blind spot
  (B1) is the one class of bug this suite structurally cannot see — worth a
  `docs/lessons.md` entry: *any raw SQL against a JSON column must be reasoned about (or
  smoke-tested) on MySQL separately; sqlite's TEXT-backed `json()` columns do not
  reproduce MySQL's JSON normalization.*

---

## Fix tasks (to be appended to `docs/tasks/todo.md` under Spec 029/030)

- [ ] **B1 (BLOCKER, Spec 030):** replace the `picks LIKE` JSON containment in
  `AuditLandingPageFreshnessJob::dispatchForProduct()` with a PHP-side filter over the
  tenant's landing pages (or verified `whereJsonContains`) — the current pattern never
  matches MySQL's normalized JSON string form, so the instant path dispatches nothing in
  prod. Remove the "product_id first key" coupling comment in `GenerateLandingPage`.
  Post-deploy: tinker-verify one dispatch on prod MySQL. Log the sqlite/MySQL JSON
  lesson in `docs/lessons.md`.
- [ ] **B2 (BLOCKER, Spec 029):** move the `ProductConditionGuard::matchesTitle` early
  skip after the existing-offer lookup in all three ingestion paths — an existing offer
  (or supplied `condition`) must go through refresh + `ListingHealthService::apply()`
  (flag + ignore), not `skipped_condition`. Otherwise a rescan of a marker-titled
  renewed listing heals nothing and the product stays a live landing pick. Tests per
  path.
- [ ] **B3 (BLOCKER, Spec 030):** add a hidden dehydrated `est_price_snapshot` field to
  `LandingPageResource`'s picks repeater — Filament currently strips it on save, killing
  `price_drift` for every Filament-edited page. Regression test: snapshot survives a
  prose edit.
- [ ] **S1-S6 + N1-N6:** see `docs/reviews/audit-2026-08-10-review.md` (this file) —
  flag-clear audit dispatch, rating null-wipe on refresh, refresh un-ignore removal,
  `unknown` condition preservation, price-change-gated tier-recalc dispatch, Form
  Request extraction (fold into Q2), and the six nits.
