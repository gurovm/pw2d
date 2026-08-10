# Security Audit: Spec 029 Phase A (Extension Rescan / Listing Health) + Spec 030 (Landing-Page Freshness)
**Date:** 2026-08-10
**Scope:** Uncommitted changes — API ingestion controllers/requests, `routes/api.php`, `OfferIngestionService`, `ListingHealthService`, `PriceTierRecalculator`, `AuditLandingPageFreshnessJob`, `RecalculateCategoryPriceTiers`, `ProductObserver`, `SelectLandingPagePicks`, `AuditLandingPageFreshness`, console commands, `LandingPageResource`, `LandingPage`/`ProductOffer` models, the three `2026_08_10_*` migrations, new tests.

---

## Critical (fix immediately)

None found.

## High (fix before release)

None found.

## Medium (fix soon)

| # | Issue | Location | Fix |
|---|-------|----------|-----|
| M1 | **Automatic un-ignore on re-import defeats condition flagging.** The refresh branch resets `'is_ignored' => false` (and `status => 'pending_ai'`) on every re-POST of a known ASIN. Any holder of the shared extension token can resurrect a product that Spec 029's DOM condition detection (or a human in Filament) ignored, simply by replaying `/api/product-import` for its ASIN without a `condition` field. This directly violates Spec 029's non-goal: *"No automatic un-ignoring … reversal stays a human decision in Filament,"* and creates a flip-flop loop with the rescan pipeline (rescan flags → legacy import unflags). | `app/Http/Controllers/Api/ProductImportController.php:124` | Remove the reset from `$productUpdates` and skip re-queueing ignored products: <br>```php
if ($product->is_ignored) {
    return response()->json([
        'success' => true,
        'action'  => 'skipped_ignored',
        'message' => 'Product is ignored (condition/human decision) — un-ignore in Filament first.',
        'product' => ['id' => $product->id],
    ]);
}
``` placed before the `$existingOffer->update()` block, and drop `'is_ignored' => false` from `$productUpdates`. |
| M2 | **Matched-product existence check bypasses tenant scoping.** `Product::withoutGlobalScopes()->where('id', $matchedProductId)->exists()` validates only that the ID exists *somewhere*. `$matchedProductId` comes from the `ai_matching_decisions` cache; today its writes are tenant-scoped, but a single poisoned/stale cache row (or a future `AiService::matchProduct` regression) would let ingestion attach the current tenant's offer to **another tenant's product** via the subsequent `updateOrCreate`, and `ListingHealthService::apply()` would then set `is_ignored` / `condition` on that cross-tenant product — exactly the tenant-pollution failure mode this pipeline must never allow. One `where` clause makes it structurally impossible. | `app/Services/OfferIngestionService.php:158` | ```php
if ($matchedProductId && !Product::withoutGlobalScopes()
        ->where('id', $matchedProductId)
        ->where('tenant_id', $tenantId)
        ->exists()) {
    $matchedProductId = null;
}
``` |
| M3 | **Filament picks repeater: `disabled()->dehydrated()` fields are client-tamperable, and `itemLabel` resolves cross-tenant products.** Filament's documented caveat: a `disabled()` field that is also `dehydrated()` can still be rewritten through crafted Livewire update payloads — a panel user can swap a pick's `product_id`/`role` to arbitrary IDs, breaking the "picks are chosen by DATA" guarantee the resource header comment relies on. Compounding it, `itemLabel` uses `Product::withoutGlobalScopes()->find($state['product_id'])`, so a tampered (or merely mistyped) ID renders **another tenant's product name** in the admin UI — a cross-tenant info disclosure the moment the panel ever has more than one trusted user. (Public rendering stays safe — the controller's render-time product lookup is tenant-scoped — so impact is admin-side integrity/disclosure.) | `app/Filament/Resources/LandingPageResource.php:119-142` | 1) Make the identity fields non-dehydrated (`->disabled()->dehydrated(false)`) and restore them server-side from the stored record in `Pages\EditLandingPage`: <br>```php
protected function mutateFormDataBeforeSave(array $data): array
{
    $stored = $this->record->picks ?? [];
    foreach ($data['picks'] ?? [] as $i => &$pick) {
        $pick['product_id']         = $stored[$i]['product_id'] ?? null;
        $pick['role']               = $stored[$i]['role'] ?? null;
        $pick['est_price_snapshot'] = $stored[$i]['est_price_snapshot'] ?? null;
    }
    return $data;
}
```<br>2) In `itemLabel`, use the tenant-scoped `Product::find(...)` (ambient Filament tenancy is initialized via the `TenantSet` bridge) instead of `withoutGlobalScopes()`. |

## Low / Informational

- **L1 — Navigation badge falls back to an unscoped cross-tenant count in central context.** `getNavigationBadge()` keys the cache on `'landing-pages-stale-badge:' . tenant('id')`; when tenancy is not initialized (central panel context) the key degenerates to a shared `...badge:` entry and `staleQuery()` runs **unscoped** (stancl's `BelongsToTenant` scope is inactive without an initialized tenant), caching an all-tenants stale count. Count-only disclosure, but violates the spec-027 S1 lesson's spirit. Fix (`app/Filament/Resources/LandingPageResource.php:49`): `if (tenant('id') === null) { return null; }` before computing.
- **L2 — `listing_flags` array size is unbounded.** `'listing_flags' => 'nullable|array'` accepts thousands of repeated `high_price` entries (each element passes `Rule::in`), bloating the JSON column and every later `in_array` scan. Fix in all three request definitions (`OfferIngestionController.php:31`, `BatchImportRequest.php:31`, `ProductImportRequest.php:30`): `'listing_flags' => 'nullable|array|max:5'` and add `'distinct'` to the `listing_flags.*` rules.
- **L3 — `rescan-list` returns an unbounded result set.** `->get()` over all of a category's offers, repeatable at 120 req/min. Bounded in practice by category size (~hundreds), but add a cap for hygiene (`OfferIngestionController.php:60`): `->limit(1000)` (the extension walks sequentially anyway; it can re-request as `health_checked_at` advances).
- **L4 — Queued jobs silently no-op under a leaked ambient tenant.** Both jobs reuse `tenancy()->initialized` without verifying it matches `$this->tenantId`. If any *other* job ever leaks an initialized tenancy on a worker, these jobs run under the wrong tenant; the tenant-scoped `find()` then returns null and the job exits — **fail-closed** (no cross-tenant read/write is possible), but the audit/recalc silently doesn't happen. Hardening for both `AuditLandingPageFreshnessJob.php:67` and `RecalculateCategoryPriceTiers.php:45`: <br>`if ($alreadyInitialized && tenant('id') !== $this->tenantId) { tenancy()->end(); $alreadyInitialized = false; }`
- **L5 — `existingAsins` uses raw `$request->category_id` without validation.** Safe today (parameter binding + tenant scope from initialized tenancy), but inconsistent with every sibling endpoint; validate with the same tenant-scoped `Rule::exists` (`ProductImportController.php:52`).
- **L6 — Verify Filament edits preserve `est_price_snapshot`.** The picks repeater schema declares no `est_price_snapshot` component; depending on Filament's repeater dehydration, an admin edit may strip the snapshot from the picks JSON, silently disabling the `price_drift` staleness check for that page. Add a regression test (edit a page via `Livewire::test(EditLandingPage::class)`, assert the snapshot survives); the M3 `mutateFormDataBeforeSave` fix also closes this deterministically.
- **L7 — `'updated_at' => $now` inside `$offer->update([...])`** (`BatchImportController.php:100`) is silently discarded by mass-assignment (`updated_at` is not in `$fillable`); Eloquent touches the timestamp anyway. Harmless — remove for clarity.

## Passed Checks

- **Rescan-list tenant isolation (priority 1):** `category_id` validated with `Rule::exists('categories','id')->where('tenant_id', tenant('id'))` — a cross-tenant category ID fails with 422 before any query runs; offer/product queries additionally run under stancl's initialized-tenancy global scopes (triple layer: validation rule + `ProductOffer` scope + `whereHas('product')` scope). Covered by `tests/Feature/RescanListControllerTest.php::it_does_not_leak_offers_across_tenants` (cross-tenant 422 asserted, own-tenant single-offer asserted). Note the shared-token model itself: one `X-Extension-Token` grants ingestion access to *every* tenant via `X-Tenant-Id` — accepted by design (single-owner), unchanged by this spec.
- **Auth middleware parity:** `/extension/rescan-list` sits in the same group as `/extension/ingest-offer` (`VerifyExtensionToken` → `hash_equals`, fail-secure when token unconfigured; `InitializeTenancyFromPayload` → 404 on unknown tenant, 422 on missing header; `throttle:120,1`). 403-without-token covered by test.
- **Condition / listing_flags validation (priority 3):** all three endpoints validate `condition` against `ListingHealth::CONDITIONS` and each `listing_flags.*` element against `RECOGNIZED_FLAGS` (`['high_price']` only) via `Rule::in` — no free-form values reach the DB (size caveat: L2). `ListingHealth` is a single shared vocabulary class; no drift between paths.
- **Mass assignment (priority 2):** `condition`/`listing_flags`/`health_checked_at` were added to `ProductOffer::$fillable` but are *only ever written* by `ListingHealthService` with validated values — no controller spreads raw request input into `create`/`updateOrCreate`. `tenant_id` is always set explicitly from the tenant-validated category or `tenant('id')`. `is_ignored` flips only on products resolved through tenant-scoped lookups (M2 hardening recommended for the AI-match edge).
- **ASIN/product confusion:** batch-import ASIN dedup is constrained to the tenant's own Amazon store + the tenant-validated category; ingest-offer URL dedup is constrained to a tenant-owned `store_id` under initialized tenancy.
- **Job/observer tenancy (priority 4):** both jobs carry scalar `(tenantId, id)` payloads (no serialized models → no serialized-tenant mismatch), capture tenant from **row data** (`$product->tenant_id`, `$category->tenant_id`), guard against nested `initialize()`, and `tenancy()->end()` in `finally`. Wrong-tenant reuse fails closed (L4). `AuditLandingPageFreshnessJob::dispatchForProduct()` scopes explicitly by `where('tenant_id', $product->tenant_id)` — the S1 lesson applied correctly outside ambient tenancy. `ProductObserver` is registered and only dispatches (never blocks the write).
- **Raw SQL:** all raw fragments are either constant (`COALESCE(health_checked_at, updated_at)`, the Filament `CASE WHEN` sort) or parameterized (`picks LIKE ?` with an integer-built pattern, `LOWER(name) = ?`). No user input interpolated.
- **Filament cache keys (priority 5):** `LandingPage::cacheKey()`/sitemap invalidation derive from the **row's** `tenant_id` (S1 lesson honored in model hooks); the navigation badge uses ambient tenancy legitimately for a per-tenant UI count (central-context fallback: L1).
- **Log injection (priority 6):** user-controlled values (`raw_title`, slugs) appear only in Monolog **context arrays** (JSON-encoded, newlines escaped), never interpolated into message strings; `condition` values in logs are validated enums. No CRLF injection path.
- **Migrations:** additive nullable columns with correct `down()`; `amazon_reviews_count` nullable change is data-preserving; no defaults that could mislabel existing rows (`null` = never DOM-checked, as documented).
- **Freshness engine write surface:** `AuditLandingPageFreshness` performs exactly one write (its own `landing_pages` row) — no product/offer mutation from the audit path; nightly command initializes/ends tenancy per tenant inside `try/finally`.
- **Pick eligibility:** `SelectLandingPagePicks` reads `listing_flags` from tenant-scoped offers only; `high_price` exclusion applies to the best offer, matching spec semantics.
- **Test coverage of the security-relevant behavior:** cross-tenant rescan-list, token 403, all four negative conditions → `is_ignored`, high_price-without-ignore, clean-report clears flags, absent-condition no-op, job tenancy init/reuse/unknown-tenant — all present in the new suites.
