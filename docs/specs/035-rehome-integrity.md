# Spec 035 — Re-home integrity: fire events, clear stale features

**Status:** DRAFT (2026-08-21) · **Depends on:** 029, 030 · **Supersedes part of:** the "landing-page cache" finding in `docs/tasks/todo.md` (see Correction below)

## Why

On 2026-08-21 a cleanup removed **309 stale `product_feature_values` across 55 products in 6 categories** —
rows belonging to a category the product no longer sits in. Affected products carried 12 feature values
where their category defines 6. One of them (#3352) was a live pick on `/best/super-automatic-espresso-machines`,
so the page would have cited *semi-automatic* feature scores on a super-automatic guide.

Clearing them was a one-off. Nothing stops the backlog rebuilding, and the reason is narrower and more
serious than "no one remembered".

### Root cause: two re-home paths bypass Eloquent events entirely

```php
AiSweepCategory.php:88      Product::where('id', $id)->update(['category_id' => null]);
AiAssignCategories.php:104  Product::where('id', $id)->update(['category_id' => $newId]);
```

`Builder::update()` is a **mass update**. It fires no `saving`/`saved` model events. Consequences:

1. **`ProductObserver` never runs for either command.** It already tests `wasChanged('category_id')` and
   dispatches `AuditLandingPageFreshnessJob` — so **Spec 030's instant freshness path is dead for the AI
   sweep**, which is one of the primary ways a published pick gets detached. The nightly audit still
   covers it, so this has been invisible: pages go stale correctly, just never *promptly*.
2. Neither path clears feature values belonging to the old category. `AiAssignCategories` at least
   dispatches `RescanProductFeatures` (line 106), which **adds** the new category's scores — on top of
   the old ones, producing exactly the 12-values-for-a-6-feature-category shape found above.
   `AiSweepCategory` does nothing at all.

A manual category change in Filament uses Eloquent and so does fire the observer — which is why this
only ever bites the automated paths.

## Build

### 1. Make both re-home paths fire events

Replace the mass `->update()` calls with model-level saves. Both sites already sit inside a per-item
`foreach`, so there is no new N+1 — the loop exists either way.

### 2. Clear foreign-category feature values in `ProductObserver`

Extend the existing `saved()` hook, which already computes `$categoryChanged`:

- delete every `product_feature_value` for this product whose `feature.category_id` differs from the
  product's current `category_id` (and **all** of them when `category_id` is now null — a detached
  product's scores belong to no category);
- when a new category is present, dispatch `RescanProductFeatures` so the correct scores replace them.

The observer, not the commands, is the right home: it covers Filament re-homes and any future path for
free, and there is one definition instead of three.

**Deleting is correct, not lossy.** A `Feature` belongs to exactly one category, so a value whose feature
sits in another category can never be read for this product — it is unreachable data that only surfaces
in `featureValues` iteration, which is precisely how it reached a landing page.

### 3. Do not flood the queue — the important constraint

Firing events on the sweep means `AuditLandingPageFreshnessJob::dispatchForProduct()` now runs for
**every** swept product. `AiSweepCategory` works in chunks of 25 over categories of 100+, and each job
re-runs full pick selection for the affected pages. That is the already-logged **S9** concern, and this
spec would turn it from theoretical into routine.

Required: collapse the fan-out. Either make `AuditLandingPageFreshnessJob` `ShouldBeUnique` on
(tenant, landing page) for a short window, or have the commands suppress per-product dispatch and fire
once per affected category after the loop. Prefer `ShouldBeUnique` — it fixes every caller, including
`ListingHealthService`'s per-offer dispatches, rather than just these two.

Whichever is chosen, a sweep of 100 products must not produce 100 audits of the same page.

## Correction — the cache finding was wrong

`docs/tasks/todo.md` logs "a landing-page content update does not bust the page cache." **It does.**
`LandingPage::booted()` registers `saved`/`deleted` hooks that forget both `cacheKey()` and the sitemap
cache. The stale page observed on 2026-08-21 was caused by the ad-hoc regeneration script writing via
`DB::table('landing_pages')->update()` — the same mass-update-bypasses-events trap this spec is about,
in the operator's tooling rather than the application. No application fix is needed; the todo entry
should be corrected and any future ad-hoc content script must go through the model.

That both halves of this session's "two compounding fixes" turned out to be the *same* root cause is the
useful finding: **`Builder::update()` silently skips observers, and this codebase leans on observers for
freshness, caching, and now feature integrity.**

## Tests

- Sweeping a product to `category_id = null` fires `ProductObserver` and dispatches the freshness audit
  (this is the currently-broken Spec 030 path — assert it, since nothing does today).
- Re-homing A → B deletes A's feature values, keeps none of them, and dispatches `RescanProductFeatures`
  for B.
- Detaching to null deletes **all** feature values and dispatches no rescan.
- A product re-homed via Eloquent (the Filament path) behaves identically to one re-homed by command.
- Queue-flood guard: sweeping N products whose pages overlap produces far fewer than N audit jobs —
  assert the collapse, with a comment naming S9.
- `AiAssignCategories` no longer leaves a product holding two categories' feature values (the #3352
  regression — assert the total count equals the new category's feature count).
