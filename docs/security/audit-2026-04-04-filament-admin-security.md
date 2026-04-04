# Security Audit: Filament Admin Panel
**Date:** 2026-04-04
**Scope:** Admin panel access control, tenant switching, cross-tenant data exposure, bulk actions, Import via AI, Settings page, IDOR in resource URLs
**Auditor:** Security Auditor Agent

---

## Critical (fix immediately)

| # | Issue | Location | Detail | Fix |
|---|-------|----------|--------|-----|
| C1 | **Retry Failed bypasses tenant scoping -- processes ALL tenants' failed products** | `app/Filament/Resources/ProductResource/Pages/ListProducts.php:28,43` | `Product::withoutGlobalScopes()->where('status', 'failed')` removes the `BelongsToTenant` global scope. The count and the retry action iterate over every tenant's failed products, not just the currently selected tenant. An admin viewing Tenant A will unknowingly requeue Tenant B's failed products, and the count in the button label is inflated. While the admin panel is trusted, this breaks data isolation and could cause unintended AI API spend on another tenant's products. | Replace `withoutGlobalScopes()` with a tenant-scoped query: `Product::where('status', 'failed')`. Since stancl's `BelongsToTenant` adds the tenant scope automatically when tenancy is initialized (which it is via the `TenantSet` event), this is sufficient. Full fix: |

```php
// ListProducts.php -- getHeaderActions()

// Line 28: Replace
$failedCount = Product::withoutGlobalScopes()->where('status', 'failed')->count();
// With
$failedCount = Product::where('status', 'failed')->count();

// Lines 43-46: Replace
Product::withoutGlobalScopes()
    ->where('status', 'failed')
    ->whereNotNull('category_id')
    ->each(function (Product $product) use (&$count) {
// With
Product::where('status', 'failed')
    ->whereNotNull('category_id')
    ->each(function (Product $product) use (&$count) {
```

| # | Issue | Location | Detail | Fix |
|---|-------|----------|--------|-----|
| C2 | **ProductStatsWidget bypasses tenant scoping -- shows cross-tenant stats** | `app/Filament/Widgets/ProductStatsWidget.php:17-20` | Every `Product::count()` and `Product::where(...)` call in the widget executes without `BelongsToTenant` scoping verification. While `BelongsToTenant` adds a global scope automatically, the widget also calls `DB::table('jobs')->count()` which has no tenant scoping at all, showing the global queue depth to any admin regardless of selected tenant. The product counts should be correct because tenancy IS initialized via `TenantSet`, but this should be verified. The `jobs` table count is cross-tenant by nature. | Add a note/label that the queue stat is global, OR filter products explicitly. Verify tenant scoping is active by spot-checking counts. The `jobs` table issue is informational -- it cannot be scoped to a tenant without adding `tenant_id` to the jobs table. |

---

## High (fix before release)

| # | Issue | Location | Detail | Fix |
|---|-------|----------|--------|-----|
| H1 | **EditCategory calls GeminiService directly, bypassing AiService** | `app/Filament/Resources/CategoryResource/Pages/EditCategory.php:45-46` | The `callGeminiText()` helper resolves `GeminiService` directly from the container: `app(\App\Services\GeminiService::class)->generate(...)`. This violates the architectural rule "All AI calls MUST go through AiService". Additionally, `callGeminiImage()` (line 68-77) makes a raw HTTP call to the Gemini API, constructing the request manually with `Http::timeout(120)->withHeaders(['x-goog-api-key' => $apiKey])`. This means if GeminiService gets rate-limiting, logging, or error-handling improvements, this code path won't benefit. | Refactor `callGeminiText` to call `AiService` domain methods, and wrap `callGeminiImage` in a new `AiService::generateCategoryImage()` method. At minimum, do not construct raw HTTP calls with the API key inline. |
| H2 | **Exception messages exposed to admin UI** | `app/Filament/Resources/CategoryResource/Pages/EditCategory.php:227,269,322,380,465` and `app/Filament/Resources/ProductResource/Pages/ListProducts.php:189` | Multiple `catch` blocks display `$e->getMessage()` directly in Filament notifications via `->body($e->getMessage())`. If the Gemini API returns error details, database connection errors occur, or internal exceptions fire, the raw message (which may contain API keys in URL strings, SQL queries, or internal paths) is shown to the admin. | Log the full exception, but show a generic message to the UI: |

```php
// Instead of:
->body($e->getMessage())

// Use:
->body('An error occurred. Check the logs for details.')

// And ensure the exception is logged:
\Log::error('AI generation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
```

| # | Issue | Location | Detail | Fix |
|---|-------|----------|--------|-----|
| H3 | **Import via AI -- no size limit on raw_text input** | `app/Filament/Resources/ProductResource/Pages/ListProducts.php:72-74` | The `raw_text` textarea has no `maxLength()` constraint. A user (even an admin) could paste an extremely large text block (megabytes), which is then sent verbatim to the Gemini API as `$rawText` in the prompt string. This could cause: (1) excessive API token costs, (2) API request timeouts or failures, (3) memory pressure on the PHP process. | Add `->maxLength(50000)` to the textarea (50K chars is generous for a product page): |

```php
Forms\Components\Textarea::make('raw_text')
    ->label('Raw Product Text')
    ->required()
    ->rows(15)
    ->maxLength(50000)
    ->helperText('Paste the entire product page content (max ~50,000 characters)'),
```

| H4 | **Import via AI logs sensitive parsed data at INFO level** | `app/Filament/Resources/ProductResource/Pages/ListProducts.php:132-170` | Three `\Log::info()` calls log parsed AI data, feature names, and values at INFO level. In production, INFO logs are typically persisted, and this creates unnecessary noise plus potential data leakage in shared log aggregation systems. | Change to `\Log::debug()` or remove entirely. Debug logging is usually disabled in production. |

---

## Medium (fix soon)

| # | Issue | Location | Detail | Fix |
|---|-------|----------|--------|-----|
| M1 | **No resource-level policies on Product, Category, Store, AiMatchingDecision resources** | All Filament resource files | Only `UserPolicy` exists. There are no policies for `Product`, `Category`, `Store`, `AiMatchingDecision`, `Tenant`, `Setting`. While the admin panel itself is gated by `canAccessPanel()` (email must end with `@pw2d.com`), Filament resources respect Laravel policies for granular authorization (create, update, delete, viewAny). Without policies, any authenticated admin can perform any operation on any resource. If the team grows or roles are introduced, there is no authorization layer to build on. | For now this is acceptable given the small admin team. Before adding more users, create policies: |

```bash
php artisan make:policy ProductPolicy --model=Product
php artisan make:policy CategoryPolicy --model=Category
# etc.
```

| M2 | **TenantResource is correctly unscoped but lacks delete protection** | `app/Filament/Resources/TenantResource.php:26` | `$isScopedToTenant = false` is correctly set since this resource manages tenants themselves. However, the delete action has no confirmation guard beyond Filament's default modal. Deleting a tenant would cascade-delete all its categories, products, offers, etc. (depending on FK constraints). There is no soft-delete or "are you really sure?" double-confirmation. | Add `requiresConfirmation()` with a warning description to the delete action. Consider adding a check that prevents deletion if the tenant has products: |

```php
Tables\Actions\DeleteAction::make()
    ->requiresConfirmation()
    ->modalDescription('This will permanently delete the tenant and ALL associated data (categories, products, offers, settings). This cannot be undone.')
    ->before(function (Tenant $record) {
        if ($record->products()->count() > 0) {
            Notification::make()->title('Cannot delete: tenant has products')->danger()->send();
            $this->halt();
        }
    }),
```

| M3 | **Buying guide HTML allows `<a>` tags -- potential stored XSS vector** | `resources/views/livewire/product-compare.blade.php:176` | `strip_tags($data['content'], '<p><br><ul><ol><li><strong><em><h3><h4><a>')` allows `<a>` tags in the buying guide output. While the content is admin-generated via AI, the Filament RichEditor also allows manual editing. An `<a>` tag with `href="javascript:alert(1)"` would pass through `strip_tags`. The `<a>` tag also allows `onclick` and other event handler attributes. | Either remove `<a>` from the allowed tags list, or use a proper HTML sanitizer like `HTMLPurifier` that strips dangerous attributes while keeping safe `<a href="https://...">` links: |

```php
// Quick fix: remove <a> from allowed tags
{!! strip_tags($data['content'], '<p><br><ul><ol><li><strong><em>') !!}

// Better fix: use a proper sanitizer
{!! \Mews\Purifier\Facades\Purifier::clean($data['content']) !!}
```

| M4 | **Hero headline uses `{!! !!}` with `strip_tags` allowing `<span>` and `<em>` on tenant-controlled data** | `resources/views/livewire/home.blade.php:8` | `{!! strip_tags(tenant('hero_headline') ?? '...', '<span><br><em><strong>') !!}` renders tenant branding HTML unescaped. While `strip_tags` is applied and only specific tags are allowed, `<span>` and `<em>` can carry `style`, `onmouseover`, and other event handler attributes. The content is admin-controlled via `TenantResource`, so the risk is low (admin-to-admin XSS), but it is still a defense-in-depth gap. | Same as M3 -- either use a proper HTML purifier or escape attribute contents. |

| M5 | **`callGeminiImage` reads `config('services.gemini.api_key')` directly** | `app/Filament/Resources/CategoryResource/Pages/EditCategory.php:63-77` | The Gemini API key is read from config and used in a raw HTTP header. If the HTTP call fails, the error response logged via `$response->body()` at line 80 could contain the API key in the URL or headers, especially if the error is a redirect or debug dump. The exception message at line 80 includes `$response->body()` which is then shown in the admin notification (see H2). | Wrap the image generation in `AiService` (see H1). In the interim, redact the response body before including it in exceptions. |

| M6 | **Feature.firstOrCreate and Preset.firstOrCreate do not explicitly set tenant_id** | `app/Filament/Resources/CategoryResource/Pages/EditCategory.php:312,442,132` | `Feature::firstOrCreate(['category_id' => ..., 'name' => ...])` relies on `BelongsToTenant` to auto-set `tenant_id` via the global scope and the creating event. While this works when tenancy is initialized, it is fragile. If the code ever runs in a context where tenancy is not initialized (e.g., a queued job, a command, or a test), the `tenant_id` would be null. | Explicitly pass `tenant_id` as a defensive measure: |

```php
Feature::firstOrCreate([
    'category_id' => $record->id,
    'name'        => $featureData['name'],
    'tenant_id'   => tenant('id'),  // explicit safety net
], [
    'unit' => $featureData['unit'] ?? null,
]);
```

---

## Low / Informational

| # | Issue | Location | Detail |
|---|-------|----------|--------|
| L1 | **`canAccessTenant()` returns `true` unconditionally** | `app/Models/User.php:51-53` | Every authenticated admin can access every tenant. This is by design for the current small team, but should be revisited if role-based access is ever added. |
| L2 | **`canAccessPanel()` gates on email domain only** | `app/Models/User.php:37-39` | Access is granted to any user with `@pw2d.com` email. There is no MFA, IP allowlist, or role check. Acceptable for current scale but worth hardening for production with real traffic. |
| L3 | **`DB::table('jobs')->count()` in ProductStatsWidget is a raw DB query** | `app/Filament/Widgets/ProductStatsWidget.php:21` | This bypasses Eloquent entirely. It is safe (read-only, no user input), but shows global queue depth across all tenants. Consider labeling it as "Global Queue" in the UI. |
| L4 | **OffersRelationManager correctly sets tenant_id on create** | `app/Filament/Resources/ProductResource/RelationManagers/OffersRelationManager.php:93` | `$data['tenant_id'] = tenant('id')` is explicitly set in `mutateFormDataUsing`. This is good defensive coding. |
| L5 | **`forceFill` used for `sample_prompts` despite it being in `$fillable`** | `app/Filament/Resources/CategoryResource/Pages/EditCategory.php:164` | `sample_prompts` is already in `Category::$fillable`. The `forceFill` call is unnecessary but not a security issue. The comment on line 159 is misleading. |
| L6 | **No `$guarded` override found on any model** | All models in `app/Models/` | Every model uses explicit `$fillable` arrays. The Tenant model extends stancl's BaseTenant which uses `$guarded = []` with VirtualColumn -- this is by design and acceptable. |
| L7 | **FileUpload on TenantResource logo has no `acceptedFileTypes` constraint** | `app/Filament/Resources/TenantResource.php:76-80` | The `->image()` method provides basic validation, but explicit `acceptedFileTypes` would be more robust (as done on `ProductResource` and `CategoryResource`). |
| L8 | **Settings page has no additional authorization check** | `app/Filament/Pages/Settings.php` | Any admin who can access the panel can change settings (GA ID, PostHog key, image source). This is acceptable for the current team size but should be gated if roles are introduced. |
| L9 | **`Product::create()` in Import via AI correctly sets explicit tenant_id** | `app/Filament/Resources/ProductResource/Pages/ListProducts.php:120-129` | The import action sets `'tenant_id' => tenant('id')` explicitly. This is good practice. |

---

## Passed Checks

- **Admin panel gated by `canAccessPanel()`** -- `User::canAccessPanel()` checks email domain `@pw2d.com`. Filament's `Authenticate` middleware is in the `authMiddleware` stack.
- **CSRF protection active** -- `VerifyCsrfToken::class` is in the middleware stack of `AdminPanelProvider`.
- **Filament native tenancy configured correctly** -- `->tenant(Tenant::class)` on the panel, with `TenantSet` event bridging to stancl's `tenancy()->initialize()`.
- **All core models use `BelongsToTenant`** -- Product, Category, Brand, Feature, Preset, Store, ProductOffer, AiMatchingDecision, Setting all have the trait.
- **All models have explicit `$fillable`** -- No `$guarded = []` except stancl's base Tenant model (by design).
- **FileUpload validation** -- Product and Category image uploads have `->image()`, `->acceptedFileTypes()`, and `->maxSize()` constraints.
- **Filament relationship dropdowns use scoped queries** -- `->relationship('brand', 'name')`, `->relationship('category', 'name')` in forms automatically use the model's global scopes, which include `BelongsToTenant`.
- **`Category::pluck()` and `Store::pluck()` in Filament actions** -- These models use `BelongsToTenant`, so `pluck()` inherits the global scope and only returns current tenant's records.
- **Settings page uses Filament form validation** -- `->required()`, `->maxLength()` constraints on all fields.
- **No raw SQL with unbound user input** -- `whereRaw` in ProblemProducts uses parameterized `?` binding for the regex pattern.
- **TenantResource correctly uses `$isScopedToTenant = false`** -- Appropriate since it manages tenants themselves, not tenant-scoped data.
- **OffersRelationManager explicitly injects `tenant_id`** -- Good defensive coding practice.
- **RelationManagers are inherently scoped** -- `FeaturesRelationManager`, `PresetsRelationManager`, `OffersRelationManager`, and `FeatureValuesRelationManager` all operate through the owner record's relationship, preventing cross-tenant access.
- **No IDOR in resource URLs** -- Filament resources use `{record}` route binding scoped through the tenant relationship. A user cannot access `/admin/{tenant-a}/products/{product-from-tenant-b}/edit` because Filament's tenant middleware validates the record belongs to the current tenant context.
- **Bulk actions properly scoped** -- `aiRescanBulk`, `markIgnored`, and `DeleteBulkAction` all operate on the Collection passed by Filament's table, which is already tenant-scoped.
- **Import via AI uses `AiService` correctly** -- The `extractProductFromText` call goes through `AiService`, not raw `GeminiService`.
- **Tenant color sanitization exists** -- `Tenant::sanitizeColor()` validates CSS color values with a strict regex pattern before injection into CSS variables.

---

## Summary

| Severity | Count | Key Themes |
|----------|-------|------------|
| Critical | 2 | Cross-tenant data exposure via `withoutGlobalScopes()` in Retry Failed; cross-tenant stats in widget |
| High | 4 | GeminiService direct calls bypassing AiService; exception message leakage; unbounded AI input; verbose logging |
| Medium | 6 | Missing resource policies; tenant delete protection; stored XSS via `<a>` tags in buying guide; API key in raw HTTP calls; implicit tenant_id reliance |
| Low | 9 | Design decisions acceptable for current scale (universal tenant access, email-only auth gate, etc.) |

**Priority recommendation:** Fix C1 immediately -- it is a direct tenant isolation violation that causes real operational impact (wrong tenant's products get requeued). H2 and H3 should be addressed before the next release. The remaining issues are defense-in-depth improvements.
