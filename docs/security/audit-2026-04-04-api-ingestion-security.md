# Security Audit: API & Ingestion Layer
**Date:** 2026-04-04
**Scope:** Chrome Extension API endpoints, ingestion services, authentication middleware, route configuration, input validation
**Files Audited:**
- `app/Http/Controllers/Api/BatchImportController.php`
- `app/Http/Controllers/Api/ProductImportController.php`
- `app/Http/Controllers/Api/OfferIngestionController.php`
- `app/Http/Controllers/SitemapController.php`
- `app/Http/Middleware/VerifyExtensionToken.php`
- `app/Http/Middleware/InitializeTenancyIfApplicable.php`
- `app/Http/Middleware/InitializeTenancyFromPayload.php`
- `app/Http/Requests/ProductImportRequest.php`
- `app/Http/Requests/BatchImportRequest.php`
- `app/Services/OfferIngestionService.php`
- `routes/api.php`
- `routes/web.php`

---

## Critical (fix immediately)

No critical issues found. The previous audit's Critical items (C1-C4) have been remediated:
- C1 (hardcoded token): Token now loaded from `chrome.storage.local`, not in source.
- C2/C3/C4 (unscoped API queries): `InitializeTenancyFromPayload` middleware now initializes tenancy on all API routes. The `BelongsToTenant` trait's `TenantScope` automatically scopes all model queries once tenancy is initialized.

---

## High (fix before release)

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| H1 | **`category_id` validation uses `exists:categories,id` without tenant scope** | `BatchImportRequest.php` L19, `ProductImportRequest.php` L19, `OfferIngestionController.php` L23 | The `exists:categories,id` validation rule runs a raw `SELECT ... WHERE id = ?` query that does NOT go through Eloquent (it uses the Query Builder directly), so the `BelongsToTenant` global scope does NOT apply. A caller with a valid token can pass a `category_id` belonging to a different tenant and the validation will pass. The subsequent `Category::findOrFail()` call IS tenant-scoped (via `BelongsToTenant`), so it would return 404 -- preventing actual data injection. However, this means the error message reveals the existence of the category ID ("The selected category_id is invalid" for non-existent IDs vs. 404 for cross-tenant IDs), which is a minor information leak. More critically, if any code path ever removes the `findOrFail` safety net, the validation alone would not prevent cross-tenant writes. | Add an explicit tenant constraint to the `exists` rule. In each validation rule set, replace `'exists:categories,id'` with a closure or Rule: `['required', 'integer', Rule::exists('categories', 'id')->where('tenant_id', tenant('id'))]`. Example for `BatchImportRequest`: ```php use Illuminate\Validation\Rule; public function rules(): array { return [ 'category_id' => [ 'required', 'integer', Rule::exists('categories', 'id') ->where('tenant_id', tenant('id')), ], // ... ]; } ``` Apply the same pattern to `ProductImportRequest` and `OfferIngestionController`. |
| H2 | **ASIN/external_id not validated as alphanumeric -- allows URL path injection** | `BatchImportRequest.php` L21 (`products.*.asin`: `string\|max:20`), `ProductImportRequest.php` L20 (`external_id`: `string\|max:20`) | The ASIN is interpolated into an Amazon URL: `"https://www.amazon.com/dp/{$p['asin']}"`. Without alphanumeric validation, a malicious value like `../../secrets` would produce `https://www.amazon.com/dp/../../secrets`. While this URL is stored and not fetched by the server in these controllers, it could cause unexpected behavior in the `ProcessPendingProduct` image download (which extracts the ASIN via `basename(parse_url(...))`), and it pollutes the database with invalid URLs. Amazon ASINs are always 10 alphanumeric characters (starting with B0). | Add regex validation: ```php // BatchImportRequest 'products.*.asin' => 'required|string|regex:/^[A-Z0-9]{10}$/i', // ProductImportRequest 'external_id' => 'required|string|regex:/^[A-Z0-9]{10}$/i', ``` |
| H3 | **`SitemapController` exposes all tenants' data on the central domain** | `app/Http/Controllers/SitemapController.php` | The sitemap runs on web routes with `InitializeTenancyIfApplicable`. On tenant domains, queries are scoped. On the central domain (`pw2d.com`), tenancy is NOT initialized, so `Category::get()`, `Product::cursor()`, and `Preset::get()` return records from ALL tenants. This leaks product slugs, category slugs, and preset names across tenant boundaries. A competitor visiting `pw2d.com/sitemap.xml` can see the full product catalog of all tenants. | Guard the sitemap against running on central domains: ```php public function index() { if (!tenancy()->initialized) { abort(404); } // ... existing code } ``` Alternatively, if a central-domain sitemap is desired, it should only list static pages (about, contact, etc.) and not query tenant-scoped models. |

---

## Medium (fix soon)

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| M1 | **`OfferIngestionService::processIncomingOffer()` allows arbitrary store creation via `store_slug`** | `app/Services/OfferIngestionService.php` L48-51 | `Store::firstOrCreate(['slug' => $data['store_slug'], ...])` creates a new store for any slug value. The `store_slug` is validated only as `string\|max:100` with no format restriction. An attacker with the extension token could flood the tenant with garbage stores (e.g., thousands of stores with random slugs), polluting the admin interface and potentially affecting affiliate URL generation for products matched to these stores. | Add slug format validation in `OfferIngestionController`: ```php 'store_slug' => 'required|string|max:100|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', ``` This restricts to lowercase alphanumeric with hyphens (standard slug format). Also consider adding an `exists` check or an allowlist of known stores if the business model does not require dynamic store creation. |
| M2 | **`url` and `image_url` accept any URL scheme (including `data:`, `file:`)** | `OfferIngestionController.php` L17-18 (`url`: `url`), `BatchImportRequest.php` L26 (`image_url`: `url`), `ProductImportRequest.php` L25 (`image_url`: `url`) | Laravel's `url` validation rule without parameters accepts hundreds of URI schemes including `data:`, `file:`, `ftp:`, etc. A `data:text/html,...` URL stored as a product URL would be rendered as an `href` in the frontend (albeit HTML-escaped by Blade). A `file:///etc/passwd` `image_url` would be passed to `Http::get()` in the image download job (mitigated by the SSRF host check, but causes a `TypeError` from `str_ends_with(null, ...)` when `parse_url` returns null for the host). | Restrict to HTTPS only: ```php 'url' => ['required', 'url:https', 'max:2000'], 'image_url' => ['nullable', 'url:https', 'max:2000'], ``` Laravel supports `url:https` to restrict to specific schemes (Laravel 11+). Apply to all three request classes. |
| M3 | **`existingAsins` endpoint accepts unvalidated `category_id` query parameter** | `app/Http/Controllers/Api/ProductImportController.php` L47-49 | `$request->category_id` is used directly in a `whereHas` without any validation -- no type check, no existence check. While it is parameterized (no SQL injection) and the query is tenant-scoped via `BelongsToTenant`, passing a non-integer value like an array (`?category_id[]=1&category_id[]=2`) could produce unexpected query behavior. | Add validation: ```php public function existingAsins(Request $request): JsonResponse { $request->validate([ 'category_id' => 'nullable|integer|exists:categories,id', ]); // ... rest unchanged } ``` |
| M4 | **Batch import allows cost amplification: 100 products per request, 30 requests/minute = 3,000 AI jobs/minute** | `routes/api.php` L27-32, `BatchImportRequest.php` L20 | The `batch-import` endpoint is rate-limited at 30 req/min and each request can carry up to 100 products. Each new product dispatches a `ProcessPendingProduct` job that calls the Gemini API (paid per token). An attacker with the extension token could inject 3,000 products per minute, each triggering an AI evaluation. At ~$0.01 per Gemini call, that is $30/minute or $1,800/hour in API costs. | Reduce the batch size limit and/or apply a per-tenant daily import cap: ```php // BatchImportRequest - reduce max: 'products' => 'required|array|min:1|max:50', ``` Also consider a separate, tighter rate limit for batch-import: ```php // routes/api.php Route::post('/products/batch-import', [BatchImportController::class, 'import']) ->middleware('throttle:10,1'); // 10 batch requests/minute ``` And add a daily cap check in the controller: ```php $todayCount = Product::where('created_at', '>=', now()->startOfDay())->count(); if ($todayCount > 1000) { return response()->json(['error' => 'Daily import limit reached'], 429); } ``` |
| M5 | **`OfferIngestionService` uses `withoutGlobalScopes()` for heuristic matching without verifying tenant ownership of the matched product** | `app/Services/OfferIngestionService.php` L93-99, L107 | Lines 93-99 use `Product::withoutGlobalScopes()` with an explicit `where('tenant_id', $tenantId)` -- this is correctly scoped. Line 107 uses `Product::withoutGlobalScopes()->where('id', $matchedProductId)->exists()` to check if a product still exists, but does NOT filter by `tenant_id`. If `matchProduct()` returns a product ID from a different tenant (due to a stale cache entry), the existence check would pass and the offer would be attached cross-tenant. | Add tenant filtering to the existence check: ```php if ($matchedProductId && !Product::withoutGlobalScopes() ->where('id', $matchedProductId) ->where('tenant_id', $tenantId) ->exists()) { $matchedProductId = null; } ``` |

---

## Low / Informational

| # | Issue | Location | Notes |
|---|-------|----------|-------|
| L1 | **Rate limiter keyed by IP only** | `routes/api.php` L17, L27, L38 | The `throttle:X,1` middleware keys by IP. If the extension is used by multiple operators behind the same NAT/VPN, they share the rate limit. Conversely, an attacker with multiple IPs bypasses it entirely. Consider keying by the extension token: use a named rate limiter in `AppServiceProvider::boot()` keyed by `$request->header('X-Extension-Token')`. Not urgent because the token itself is a shared secret. |
| L2 | **No request logging/audit trail for API import operations** | All API controllers | Import actions are logged via `Log::info()` but there is no structured audit trail linking requests to the extension token or client IP. If the token is compromised, there is no way to distinguish legitimate vs. malicious traffic in logs. Consider adding a middleware that logs `[$ip, $tenantId, $route, $timestamp]` for all API requests. |
| L3 | **`BatchImportController` catches all exceptions silently per-product** | `app/Http/Controllers/Api/BatchImportController.php` L119-124 | The `try/catch` around each product in the batch loop logs a warning but continues processing. If a systemic error occurs (e.g., DB connection lost), all 100 products will fail silently and the response will report `created: 0, refreshed: 0` with `success: true`. This is more of a reliability concern than security, but the `success: true` response could mask issues. |
| L4 | **`image_url` in batch import is not validated against SSRF allowlist at ingestion time** | `BatchImportRequest.php` L26, `ProductImportRequest.php` L25 | The `image_url` values are stored as-is and later fetched in `ProcessPendingProduct`. While the job has SSRF protection, validating the host at ingestion time would provide defense-in-depth and prevent storing obviously malicious URLs. |
| L5 | **419 CSRF redirect suppresses error feedback** | `bootstrap/app.php` L21-24 | The 419 handler silently redirects back without a flash message. Users never see that their token expired. Consider adding: `return redirect()->back()->with('warning', 'Session expired, please try again.')`. (Carried forward from previous audit.) |

---

## Passed Checks

- **Token authentication is timing-safe.** `VerifyExtensionToken` uses `hash_equals()` for comparison, preventing timing attacks. It also fails secure (blocks all requests) if no token is configured. The query-string fallback from the previous audit has been removed.
- **Token is NOT in source code.** The Chrome extension loads the token from `chrome.storage.local` (set via the popup settings UI), not hardcoded. The server reads from `env('CHROME_EXTENSION_KEY')`.
- **All API routes require authentication.** Every route group in `routes/api.php` applies `VerifyExtensionToken` middleware. There are no unprotected import endpoints.
- **All API routes initialize tenancy.** Every route group applies `InitializeTenancyFromPayload`, which requires an `X-Tenant-Id` header (or `tenant_id` body param) and validates the tenant exists. Unknown tenant IDs return 404.
- **Rate limiting is applied to all API routes.** Read endpoints at 60/min, import at 30/min, offer ingestion at 120/min.
- **CSRF protection on web routes.** All web routes go through Laravel's default `VerifyCsrfToken` middleware. API routes are stateless/tokenized.
- **`InitializeTenancyIfApplicable` fails secure.** Unknown domains (not central, not a registered tenant domain) get a 404, not unscoped access.
- **Mass assignment protection.** All models (`Product`, `ProductOffer`, `Store`, `Category`, `Brand`) define explicit `$fillable` arrays. No model uses `$guarded = []`. `tenant_id` is included in `$fillable` on all tenant-scoped models.
- **SQL injection protection.** The `DB::raw("SUBSTRING_INDEX(...)")` in `BatchImportController` L43 is a static SQL expression. The dynamic values (`$incomingAsins`) are passed via `whereIn()` which parameterizes them. The `whereRaw('LOWER(name) = ?', ...)` in `OfferIngestionService` L98 uses a parameterized placeholder. No raw SQL with unbound user input.
- **`ImageOptimizer::toWebp()` is shell-safe.** Uses `Symfony\Component\Process\Process` with an array of arguments. No shell interpolation possible.
- **Tenant domain resolution is validated.** `InitializeTenancyIfApplicable` uses `DomainTenantResolver::resolve()` in a try-catch, aborting 404 on failure.
- **No sensitive data in API responses.** Import responses return only `success`, `created`, `refreshed` counts, and product IDs. No API keys, internal errors, or stack traces are exposed.
- **Product data correctly derives `tenant_id` from the category.** Both `BatchImportController` and `ProductImportController` set `tenant_id` on new products/offers from `$category->tenant_id`, not from user input. This prevents a caller from injecting products into a different tenant than the category belongs to.

---

## Remediation Priority

| Severity | Count | Recommended Timeline |
|----------|-------|---------------------|
| Critical | 0 | -- |
| High | 3 | Fix before next deploy |
| Medium | 5 | Fix within 1 week |
| Low | 5 | Track and address opportunistically |

The highest-priority items are **H1** (tenant-scoped `exists` validation), **H2** (ASIN format validation), and **H3** (sitemap cross-tenant leak). H1 and H3 are multi-tenant isolation issues. H2 prevents URL path injection into stored Amazon URLs.
