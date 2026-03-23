# Review: Full Codebase Quality Audit
**Date:** 2026-03-22
**Status:** Needs changes

---

## Critical Issues (must fix)

### C1. ProductImportController is a god method (376 lines, business logic in controller)
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Http/Controllers/Api/ProductImportController.php`
**Lines:** 59-375

The `import()` method is approximately 310 lines long and contains:
- Gemini API prompt construction and HTTP call (lines 90-130)
- JSON response parsing and validation (lines 145-215)
- Image downloading with SSRF protection (lines 218-258)
- Brand creation (lines 261-264)
- Product creation/update with anti-duplicate logic (lines 266-304)
- Feature value attachment loop (lines 306-342)

This violates the "thin controllers" rule. The entire method is nearly identical to what `ProcessPendingProduct` does but inline instead of queued. This is the single largest DRY violation in the codebase.

**Fix:** Extract shared logic into a Service class (e.g., `ProductAiProcessingService`) or deprecate this endpoint entirely, since `BatchImportController` already handles the modern SERP flow and delegates to `ProcessPendingProduct` via the queue.

---

### C2. LIKE queries with unescaped user input (SQL wildcard injection)
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Livewire/GlobalSearch.php`
**Lines:** 252, 260, 277

```php
$catQ = Category::where('name', 'like', "%{$term}%");
$presetQ = Preset::with('category:id,name,slug')->where('name', 'like', "%{$term}%");
$productQ = Product::...->where('name', 'like', "%{$term}%");
```

While Laravel's query builder prevents SQL injection via parameterized queries, the `$term` value is interpolated directly into the LIKE pattern without escaping `%` and `_` wildcards. A user typing `%` or `_` will match unintended rows. This is a logic vulnerability, not a SQL injection, but it can cause information disclosure or unexpected behavior.

**Fix:** Use `str_replace(['%', '_'], ['\\%', '\\_'], $term)` or a helper before interpolation.

---

### C3. Setting model cache is not tenant-scoped
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Models/Setting.php`
**Lines:** 17-23

```php
return Cache::rememberForever("setting:{$key}", function () use ($key, $default) {
    $setting = static::where('key', $key)->first();
    return $setting ? $setting->value : $default;
});
```

The cache key `setting:{$key}` is globally shared across all tenants. If Tenant A sets `image_source=external` and Tenant B sets `image_source=local`, whichever is cached first wins for ALL tenants until the cache is cleared. The `BelongsToTenant` trait scopes the DB query, but the cache key does not include tenant context.

**Fix:** Include tenant ID in the cache key:
```php
$tenantId = tenancy()->initialized ? tenant()->getTenantKey() : 'central';
Cache::rememberForever("setting:{$tenantId}:{$key}", ...)
```
Apply the same fix in `Setting::set()`.

---

### C4. API routes lack tenant scoping for categories and existing-asins
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Http/Controllers/Api/ProductImportController.php`
**Lines:** 20-57

The `categories()` and `existingAsins()` methods return ALL categories and ALL ASINs across all tenants. API routes do not go through `InitializeTenancyIfApplicable` middleware, so `BelongsToTenant` scoping is not active. The `BatchImportController` correctly passes `tenant_id` explicitly when creating products, but the Chrome Extension sees categories from every tenant.

**Fix:** Either:
1. Accept a `tenant_id` query parameter and scope manually, or
2. Apply tenancy middleware to API routes, or
3. Since this is an admin-only internal API (protected by extension token), document this as intentional behavior and ensure the Filament admin understands the cross-tenant category list.

---

### C5. No Form Request classes anywhere in the project
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Http/Requests/` (directory does not exist)

Both API controllers use inline `$request->validate()`. The project has zero Form Request classes. For the `BatchImportController` with its nested array validation and the `ProductImportController` with its complex validation rules, Form Requests would improve testability, reusability, and separation of concerns.

**Fix:** Create `BatchImportRequest` and `ProductImportRequest` Form Request classes.

---

### C6. Duplicate SSRF allowed-hosts lists
**Files:**
- `/Users/mg/projects/power_to_decide/pw2d/app/Http/Controllers/Api/ProductImportController.php` (lines 219-224)
- `/Users/mg/projects/power_to_decide/pw2d/app/Jobs/ProcessPendingProduct.php` (lines 216-221)

The exact same `$allowedHosts` / `$allowedImageHosts` array is duplicated in two places. If a new CDN domain is added, both must be updated or one will silently reject images.

**Fix:** Extract to a config value (`config('services.amazon.allowed_image_hosts')`) or a constant on a shared class.

---

### C7. Register page is publicly accessible with no protection
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Livewire/Auth/Register.php`

The Register component allows anyone to create a user account. While `canAccessPanel()` in `User.php` restricts Filament to `@pw2d.com` emails, the registration form itself has no email domain restriction. Anyone can register an account and pollute the users table. There are also no routes defined in `web.php` for login/register/profile, suggesting these may be orphaned or auto-discovered -- either way, registration should be restricted or disabled.

**Fix:** Either add email domain validation to the register form, disable public registration entirely, or verify these auth components are actually routed and needed.

---

## Warnings (recommended improvements)

### W1. DRY violation: Gemini API call pattern duplicated 5 times
**Files with direct Gemini HTTP calls:**
- `app/Jobs/ProcessPendingProduct.php` (line 114)
- `app/Jobs/RescanProductFeatures.php` (line 91)
- `app/Http/Controllers/Api/ProductImportController.php` (line 115)
- `app/Livewire/ProductCompare.php` (line 387)
- `app/Livewire/GlobalSearch.php` (line 141)

Each builds the URL, adds the API key, sets temperature/maxOutputTokens, strips markdown fences from the response, and parses JSON. The response-parsing pattern (`preg_replace('/^```json\s*|\s*```$/m', '', ...)` + `json_decode`) is copy-pasted everywhere.

**Recommendation:** Create a `GeminiService` class that encapsulates:
- URL construction with model and API key
- Common generation config
- Response parsing (fence stripping + JSON decode)
- Error handling (rate limits, truncation)

---

### W2. DRY violation: Sample prompts fallback logic duplicated 3 times
**Files:**
- `app/Livewire/ProductCompare.php` (lines 629-656)
- `app/Livewire/ComparisonHeader.php` (lines 70-96)
- `app/Livewire/Home.php` (lines 30-52)

All three implement the same 3-priority pattern: category's own prompts -> child category aggregation -> generated fallbacks. The `NormalizesPrompts` trait only handles the JSON parsing, not the full fallback chain.

**Recommendation:** Extend the `NormalizesPrompts` trait (or create a `SamplePromptsResolver` helper) that encapsulates the full 3-priority fallback logic.

---

### W3. `estimated_price` logic duplicated
**Files:**
- `app/Models/Product.php` (lines 120-137, `estimatedPrice` Attribute)
- `app/Livewire/ProductCompare.php` (lines 144-146, inline in `scoredProducts()`)

The rounding logic (`$price < 100 ? round($price / 5) * 5 : round($price / 10) * 10`) is duplicated in the Livewire component because `scoredProducts()` uses plain `stdClass` objects instead of Eloquent models.

**Recommendation:** Extract the rounding to a static helper method on `Product` or a utility class, and call it from both places.

---

### W4. ProductCompare is a large component (~680 lines)
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Livewire/ProductCompare.php`

This component handles: scoring, filtering, pagination, product modal state, AI concierge chat, H2H comparison, SEO schema generation, and preset application. While Livewire components can't be split as easily as controllers, the `render()` method alone is ~180 lines of SEO/schema logic.

**Recommendation:** Extract the schema/SEO generation into a dedicated helper class or a Blade partial with `@php` blocks. Consider extracting the AI concierge logic into a separate Livewire component that communicates via events.

---

### W5. No return type declarations on several Livewire methods
**Files:**
- `app/Livewire/ComparisonHeader.php` -- `mount()`, `applyPreset()`, `updatedWeights()`, `updatedPriceWeight()`, `updatedAmazonRatingWeight()`, `submitAiPrompt()`, `render()` all lack return types
- `app/Livewire/Home.php` -- `render()` lacks return type
- `app/Livewire/ProductCompare.php` -- `render()`, `handleWeightsUpdated()`, `analyzeUserNeeds()` lack return types

**Recommendation:** Add explicit return type declarations per PHP 8.3 coding standards.

---

### W6. Preset model lacks `HasFactory` trait
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Models/Preset.php`

The `Preset` model does not use `HasFactory`, and there is no `PresetFactory`. The `SearchLog` model also lacks `HasFactory`. This makes testing harder, since factories are required per the testing rules.

**Recommendation:** Add factories for `Preset`, `SearchLog`, and `ProductFeatureValue`.

---

### W7. Duplicate API route for product import
**File:** `/Users/mg/projects/power_to_decide/pw2d/routes/api.php` (lines 20-21)

```php
Route::post('/product-import', [ProductImportController::class, 'import']);
Route::post('/import-product', [ProductImportController::class, 'import']);
```

Two routes point to the same controller method. This is confusing and increases attack surface.

**Recommendation:** Deprecate one route (likely `/import-product`) and remove it after verifying the Chrome Extension no longer uses it.

---

### W8. `ProductScoringService::calculateMatchScore()` appears to be dead code
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Services/ProductScoringService.php` (lines 98-166)

The `calculateMatchScore()` method scores a single product. The `scoreAllProducts()` method (lines 19-85) does the same thing for all products in bulk and is what `ProductCompare` actually calls. The single-product method uses the slower `Collection::where()` pattern and is not referenced anywhere in the codebase.

**Recommendation:** Verify there are no callers, then remove `calculateMatchScore()`, `normalizeFeatureValue()`, `calculateFeatureRange()`, and `updateFeatureRange()` (which is already marked deprecated).

---

### W9. Relationship methods missing return type hints on Preset model
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Models/Preset.php`

The `category()`, `features()`, and `presetFeatures()` methods lack explicit return type declarations (e.g., `: BelongsTo`, `: BelongsToMany`, `: HasMany`).

**Recommendation:** Add return types for consistency with the other models.

---

### W10. Tenant model implements TenantWithDatabase but uses single-DB
**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Models/Tenant.php` (lines 13-15)

```php
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;
```

The project explicitly uses single-database tenancy, yet the Tenant model implements `TenantWithDatabase` and uses `HasDatabase`. This is misleading and could trigger database creation listeners if misconfigured.

**Recommendation:** Verify whether `stancl/tenancy` requires this interface/trait for single-DB mode. If not, remove `TenantWithDatabase` and `HasDatabase` to avoid confusion and prevent accidental multi-DB operations.

---

### W11. Auth Livewire components may be orphaned
**Files:**
- `app/Livewire/Auth/Login.php`
- `app/Livewire/Auth/Register.php`
- `app/Livewire/Auth/Profile.php`

No routes are defined in `web.php` for these components. If they rely on Livewire auto-routing or Folio, this should be documented. If they are unused, they should be removed to reduce attack surface.

---

### W12. Missing test coverage for critical flows
**Test directory:** `/Users/mg/projects/power_to_decide/pw2d/tests/`

Existing tests cover Livewire components and model behavior, but there are no tests for:
- `BatchImportController` (the primary Chrome Extension endpoint)
- `ProductImportController` (categories, existingAsins, import)
- `ProcessPendingProduct` job
- `RescanProductFeatures` job
- `GlobalSearch` Livewire component
- `SitemapController`
- `Setting::get()` / `Setting::set()` caching behavior
- Tenant scoping / multi-tenancy isolation

---

## Praise (what was done well)

### P1. Multi-tenancy migration is exemplary
The `2026_03_21_120000_add_tenant_id_to_core_tables.php` migration is well-structured: composite indexes lead with `tenant_id`, unique constraints are properly tenant-scoped, foreign keys cascade on delete, and the `down()` method fully reverses every change. All core models correctly use `BelongsToTenant` and include `tenant_id` in `$fillable`.

### P2. ProductScoringService is well-optimized
The `scoreAllProducts()` method uses O(1) hash lookups with a pre-built feature-value map instead of O(N) collection scans per product. The caching strategy in `ProductCompare::scoredProducts()` (caching raw arrays instead of Eloquent models to avoid serialization overhead) shows thoughtful performance engineering.

### P3. Security is generally strong
- SSRF protection on image downloads with explicit CDN domain allowlists
- `hash_equals()` for timing-safe token comparison in `VerifyExtensionToken`
- `$fillable` arrays on all models
- No `dd()` / `var_dump()` debug helpers left in code
- No raw SQL with unbound variables (the single `orderByRaw` in `GlobalSearch.php` correctly uses a bound parameter)
- Rate limiting on API import routes (separate limits for read vs. write)
- Fail-secure behavior when extension token is not configured

### P4. BatchImportController is clean and well-structured
Unlike `ProductImportController`, the `BatchImportController` follows the thin-controller pattern well: validates input, creates stubs, dispatches queue jobs, and returns. The batch-update optimization (collecting rows then updating in a loop) avoids N queries for the create path.

### P5. Dynamic branding architecture
The CSS variable injection for tenant colors (`--color-primary`, etc.) mapped to Tailwind tokens (`bg-tenant-primary`) is a clean, maintainable approach to multi-tenant branding.

### P6. NormalizesPrompts trait shows good DRY instincts
Extracting the JSON/array normalization into a reusable trait used by three different components is the right approach.

### P7. AI Bouncer pipeline is robust
The quality gate in `ProcessPendingProduct` with clear ignore rules (accessories, white-label, model-number-as-brand), brand normalization, and name normalization shows mature product thinking. The retry logic with status tracking (`pending_ai` -> `null` or `failed`) is well-implemented.

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 7 |
| Warning  | 12 |
| Praise   | 7 |

The most impactful improvements would be:
1. **Extract a `GeminiService`** to eliminate the 5x duplicated API call pattern
2. **Deprecate or refactor `ProductImportController::import()`** -- it duplicates `ProcessPendingProduct` in a controller
3. **Fix `Setting` cache keys** to be tenant-aware before deploying to multi-tenant production
4. **Add Form Request classes** for API validation
5. **Add test coverage** for API controllers, jobs, and tenant isolation
