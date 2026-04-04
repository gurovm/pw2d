# Review: API & Ingestion Layer
**Date:** 2026-04-04
**Status:** Approved with comments

**Scope:** API controllers (BatchImport, ProductImport, OfferIngestion), SitemapController, middleware (tenancy, auth), Form Requests, OfferIngestionService, route files (api.php, web.php, tenant.php).

---

## Critical Issues (must fix)

### C1: OfferIngestionService -- unique constraint violation on matched product offers

`OfferIngestionService::processIncomingOffer()` (line 113) creates a new `ProductOffer` when AI matches an incoming offer to an existing product, but does not check whether a `(product_id, store_id)` pair already exists. The `product_offers` table has `unique(['product_id', 'store_id'])`. If the same store already has an offer for that product (with a different URL), the INSERT will throw a database exception.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Services/OfferIngestionService.php`, line 113

**Fix:** Use `updateOrCreate` keyed on `['product_id' => $matchedProductId, 'store_id' => $store->id]` instead of a bare `create()`, or add an explicit check before creating. The current URL-based dedup (step 2) only catches the same URL, not a different URL from the same store for the same product.

### C2: OfferIngestionController uses inline validation instead of a Form Request

`OfferIngestionController::ingest()` calls `$request->validate()` directly in the controller. The other two import controllers (`BatchImportController`, `ProductImportController`) properly use dedicated `FormRequest` classes. This violates the project standard of validation via Form Requests.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Http/Controllers/Api/OfferIngestionController.php`, line 16-27

**Fix:** Create `app/Http/Requests/OfferIngestionRequest.php` and type-hint it in the controller method signature.

### C3: BatchImportController is a fat controller

`BatchImportController::import()` is ~115 lines of business logic: ASIN matching via raw SQL, price filtering heuristics, product/offer creation, and job dispatch. Per project standards, controllers should be thin with business logic delegated to Services or Actions.

By contrast, `OfferIngestionController` correctly delegates to `OfferIngestionService`. The batch import should follow the same pattern (e.g., a `BatchImportService`).

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Http/Controllers/Api/BatchImportController.php`

---

## Suggestions (recommended improvements)

### S1: SitemapController leaks cross-tenant data on the central domain

`SitemapController::index()` queries `Category`, `Product`, and `Preset` without explicit tenant filtering. On tenant domains, the `BelongsToTenant` global scope handles scoping. On the central domain (`pw2d.com`), tenancy is not initialized, so queries run unscoped -- the sitemap would list all products and categories across every tenant.

If the central domain is not intended to have a public sitemap with all tenant data, restrict it with the `EnsureCentralDomain` middleware (block) or add an explicit check. If the central sitemap should exist but only for central data, add a guard.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Http/Controllers/SitemapController.php`

### S2: existingAsins() loads unbounded URL list into memory

`ProductImportController::existingAsins()` calls `$query->pluck('url')` followed by `->map()`, loading all offer URLs into a single collection. With thousands of offers per tenant/category, this could consume significant memory.

Consider extracting ASINs at the database level with a raw select (e.g., `SUBSTRING_INDEX`) to avoid transferring full URLs, or add a `limit()` safeguard. The Chrome Extension already knows which category it is scraping, so the result set is bounded by category, but the `category_id` parameter is optional.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Http/Controllers/Api/ProductImportController.php`, line 45-52

### S3: existingAsins() category_id is not validated

The `category_id` query parameter on `existingAsins()` is used directly from `$request->category_id` without validation. While Eloquent's parameterized binding prevents SQL injection, the input is not validated as an integer or checked for existence. A malformed value would silently return no results rather than a clear 422 error.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Http/Controllers/Api/ProductImportController.php`, line 47-48

### S4: BatchImportController missing `declare(strict_types=1)` and return type

`BatchImportController` is the only API controller missing `declare(strict_types=1)` at the top of the file. Its `import()` method also lacks a `: JsonResponse` return type hint, while the other two controllers properly declare return types.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Http/Controllers/Api/BatchImportController.php`

### S5: ASIN extraction logic is duplicated and fragile

ASIN extraction appears in three different places using three different techniques:
1. `BatchImportController` line 43: `SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/dp/', -1), '?', 1)` (MySQL raw SQL)
2. `BatchImportController` line 50: `basename(parse_url($offer->url, PHP_URL_PATH))` (PHP)
3. `ProductImportController::existingAsins()` line 52: same `basename(parse_url(...))` approach

These parse differently. The SQL version handles query strings (`?tag=...`), the PHP `basename` version does not strip query strings (though `parse_url` with `PHP_URL_PATH` does strip them). If Amazon URLs change format (e.g., include trailing path segments), these could diverge.

**Fix:** Extract a shared `AsinExtractor::fromUrl(string $url): ?string` helper and use it consistently, or store the ASIN explicitly on `ProductOffer` as a denormalized column.

### S6: Duplicated "no features" guard in BatchImportController and ProductImportController

Both `BatchImportController::import()` (line 24-29) and `ProductImportController::import()` (line 64-70) check `$category->features->isEmpty()` and return a 400 error. This guard could live in a shared validation rule or in the respective Form Requests' `after()` hook via database-backed validation.

### S7: Middleware class references in api.php use FQCN strings instead of imports

Route middleware in `routes/api.php` uses string class names (e.g., `'App\Http\Middleware\VerifyExtensionToken'`) instead of the `::class` constant. While functionally correct, using imports + `::class` is the Laravel convention and enables IDE navigation and refactoring support.

**File:** `/Users/mg/projects/power_to_decide/pw2d/routes/api.php`, lines 15-16, 25-26, 37-38

### S8: database-schema.md references stale `store_name` column

The `docs/database-schema.md` file still documents `product_offers.store_name` (line 53) and `unique(['product_id', 'store_name'])`. The actual schema now uses `store_id` FK to the `stores` table, with `unique(['product_id', 'store_id'])`. This could mislead future developers or agents.

---

## Praise (what was done well)

1. **Chrome Extension contract compliance is solid.** All five documented API endpoints (`/api/categories`, `/api/existing-asins`, `/api/products/batch-import`, `/api/product-import`, `/api/extension/ingest-offer`) match exactly between `routes/api.php` and the Chrome Extension's `popup.js` / `background.js`. Both `X-Extension-Token` and `X-Tenant-Id` headers are consistently sent and consumed.

2. **Middleware layering is well-designed.** The three-layer approach (`VerifyExtensionToken` for auth, `InitializeTenancyFromPayload` for tenant context, `throttle` for rate limiting) is clean and composable. Different rate limits for read (60/min), write (30/min), and offer ingestion (120/min) show thoughtful calibration.

3. **VerifyExtensionToken is fail-secure.** If no token is configured in the environment, all requests are blocked rather than allowed. The `hash_equals()` comparison prevents timing attacks.

4. **InitializeTenancyFromPayload is well-documented and defensive.** The PHPDoc explains its purpose clearly. It checks both header and body for tenant ID with proper 422/404 error responses.

5. **OfferIngestionService follows good service-layer patterns.** Clear flow documentation in the class docblock, proper error handling around AI calls (try/catch with fallback), heuristic fallback matching, and existence verification of matched products (guarding against stale cache references). The `@param` and `@return` PHPDoc array shapes are excellent.

6. **Explicit tenant_id in API controllers.** Both `BatchImportController` and `ProductImportController` derive `tenant_id` from `$category->tenant_id` and pass it explicitly to `Product::create()` and `ProductOffer::create()`. This is the correct safety-net pattern documented in `project_context.md` section 11.

7. **Form Requests for BatchImport and ProductImport** are properly separated with clear validation rules. The `authorize()` methods correctly return `true` with a comment explaining that token middleware handles authentication.

8. **tenant.php is intentionally empty.** The comment explains that web.php serves all domains with tenancy middleware applied globally -- no duplicated route declarations.

9. **Proper use of `cursor()` in SitemapController** for the potentially large products query, avoiding loading all records into memory at once.
