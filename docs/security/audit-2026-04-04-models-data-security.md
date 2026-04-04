# Security Audit: Models & Data Layer
**Date:** 2026-04-04
**Auditor:** Security Auditor Agent (Claude Opus 4.6)
**Scope:** All Eloquent models, observers, cache helpers, mass assignment, tenant isolation, and data layer security.

---

## Critical (fix immediately)

| # | Issue | Location | Description | Fix |
|---|-------|----------|-------------|-----|
| C1 | Cross-tenant `category_id` validation bypass | `app/Http/Requests/BatchImportRequest.php:19`, `app/Http/Requests/ProductImportRequest.php:19`, `app/Http/Controllers/Api/OfferIngestionController.php:23` | The `exists:categories,id` validation rule uses Laravel's `DatabasePresenceVerifier`, which runs a raw `DB::table('categories')` query -- **not** Eloquent. This bypasses the `TenantScope` global scope entirely. A caller providing tenant A's token and tenant B's `category_id` would pass validation. The subsequent `Category::findOrFail()` (Eloquent, scoped) would 404, preventing actual data injection, but the validation layer fails its contract and leaks information about the existence of category IDs across tenants. | Replace `exists:categories,id` with a tenant-scoped Exists rule in all three locations. |

**Fix for C1 -- all three files:**

`app/Http/Requests/BatchImportRequest.php`:
```php
use Illuminate\Validation\Rule;

public function rules(): array
{
    return [
        'category_id' => [
            'required',
            Rule::exists('categories', 'id')->where('tenant_id', tenant('id')),
        ],
        // ... rest unchanged
    ];
}
```

`app/Http/Requests/ProductImportRequest.php`:
```php
use Illuminate\Validation\Rule;

public function rules(): array
{
    return [
        'category_id' => [
            'required',
            Rule::exists('categories', 'id')->where('tenant_id', tenant('id')),
        ],
        // ... rest unchanged
    ];
}
```

`app/Http/Controllers/Api/OfferIngestionController.php` (inline validation):
```php
use Illuminate\Validation\Rule;

$validated = $request->validate([
    // ...
    'category_id' => [
        'required',
        Rule::exists('categories', 'id')->where('tenant_id', tenant('id')),
    ],
    // ...
]);
```

---

## High (fix before release)

| # | Issue | Location | Description | Fix |
|---|-------|----------|-------------|-----|
| H1 | `AiCategoryRejection` model missing `BelongsToTenant` trait and `tenant_id` column | `app/Models/AiCategoryRejection.php`, `database/migrations/2026_03_28_100001_create_ai_category_rejections_table.php` | This model has no tenant scoping at all. While it is currently only accessed via `product_id` FK lookups (which are inherently tenant-scoped), any future query like `AiCategoryRejection::where('category_id', ...)` would return records across all tenants. Every other data model in the system has `BelongsToTenant`. This gap violates the project's tenant isolation invariant. | Add `tenant_id` column via migration and `BelongsToTenant` trait to the model. |
| H2 | `ProductFeatureValue` model missing `BelongsToTenant` trait | `app/Models/ProductFeatureValue.php` | Same pattern as H1. The `product_feature_values` table has no `tenant_id` column and no `BelongsToTenant` trait. Always accessed through tenant-scoped relationships today, but violates the defense-in-depth principle. A direct `ProductFeatureValue::where('feature_id', $id)` would cross tenant boundaries. | Add `tenant_id` column + `BelongsToTenant` trait, or document as intentionally scoped via parent FK only (less safe). |
| H3 | Observers create `Feature` records with nonexistent fields, risking silent failures or data corruption | `app/Observers/FeatureObserver.php:39-45`, `app/Observers/CategoryObserver.php:34-40` | Both observers pass `slug`, `data_type`, and `weight` to `Feature::create()`. The `features` table has no such columns, and the `Feature` model's `$fillable` does not include them. Mass assignment protection silently drops these fields. The created Feature records will have `NULL` values for required fields like `is_higher_better` (defaults to `true` via migration), but the `tenant_id` will also be missing from the `Feature::create()` call -- it relies solely on the `BelongsToTenant` auto-set behavior. If tenancy is not initialized when the observer fires, the feature would be created with `tenant_id = NULL`. | Fix the observers to pass correct field names, or remove them if no longer needed. |

**Fix for H1:**

New migration:
```php
Schema::table('ai_category_rejections', function (Blueprint $table) {
    $table->string('tenant_id')->after('id');
    $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
    $table->index('tenant_id');
});

// Backfill from product's tenant_id
DB::statement('UPDATE ai_category_rejections acr
    JOIN products p ON acr.product_id = p.id
    SET acr.tenant_id = p.tenant_id');
```

Model update (`app/Models/AiCategoryRejection.php`):
```php
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class AiCategoryRejection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'category_id',
        'rejection_reason',
    ];
    // ...
}
```

**Fix for H3 (FeatureObserver):**

Either remove both observers if the feature propagation is no longer needed, or fix the field names:

```php
// app/Observers/FeatureObserver.php - propagateToDescendants()
Feature::create([
    'tenant_id'        => $feature->tenant_id,
    'category_id'      => $descendant->id,
    'name'             => $feature->name,
    'unit'             => $feature->unit,
    'is_higher_better' => $feature->is_higher_better,
    'sort_order'       => $feature->sort_order,
]);

// app/Observers/CategoryObserver.php - copyParentFeatures()
Feature::create([
    'tenant_id'        => $category->tenant_id,
    'category_id'      => $category->id,
    'name'             => $parentFeature->name,
    'unit'             => $parentFeature->unit,
    'is_higher_better' => $parentFeature->is_higher_better,
    'sort_order'       => $parentFeature->sort_order,
]);
```

Note: Explicitly passing `tenant_id` is safer than relying on the `BelongsToTenant` auto-set, especially if observers fire in contexts where tenancy may not be initialized.

---

## Medium (fix soon)

| # | Issue | Location | Description | Fix |
|---|-------|----------|-------------|-----|
| M1 | `Setting::get()` uses `Cache::rememberForever` -- stale settings survive indefinitely | `app/Models/Setting.php:19` | Settings are cached forever. The cache is only busted by `Setting::set()`. If a setting is modified directly in the database (via tinker, migration, manual SQL), the stale cached value persists until the cache store is flushed. In a multi-tenant system, a stale analytics tracking ID or PostHog key from one debugging session could persist in production. | Replace `rememberForever` with a long but bounded TTL (e.g., 3600 seconds), or add a cache-busting admin action. |
| M2 | `Setting::set()` match key does not include `tenant_id` | `app/Models/Setting.php:32-34` | The `updateOrCreate` call uses `['key' => $key]` as the match criteria. The `TenantScope` global scope adds `WHERE tenant_id = ?` automatically when tenancy is initialized. If `Setting::set()` is ever called outside tenancy context (e.g., from an artisan command, a queue worker that hasn't initialized tenancy, or a migration), it could match and overwrite any tenant's setting with that key. | Add explicit `tenant_id` to the match criteria for defense-in-depth. |
| M3 | `FeaturePreset` pivot model has no `BelongsToTenant` | `app/Models/FeaturePreset.php` | The `feature_preset` pivot table has no `tenant_id`. It connects features and presets which are both tenant-scoped, so the FK relationships provide indirect scoping. However, a direct query on the pivot table (`FeaturePreset::where(...)`) would cross tenant boundaries. | Low urgency since the pivot is always accessed via relationships, but adding `tenant_id` would be consistent. |
| M4 | `User::canAccessTenant()` always returns `true` | `app/Models/User.php:51` | Any authenticated admin user can access any tenant's data in Filament. This is by design (single admin team), but if the admin panel is ever extended to per-tenant staff, this becomes a privilege escalation vector. | Document this as intentional. If per-tenant staff is added, implement proper tenant membership checks. |

**Fix for M1:**
```php
// app/Models/Setting.php
public static function get(string $key, $default = null)
{
    return Cache::remember(tenant_cache_key("setting:{$key}"), 3600, function () use ($key, $default) {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    });
}
```

**Fix for M2:**
```php
// app/Models/Setting.php
public static function set(string $key, $value)
{
    Cache::forget(tenant_cache_key("setting:{$key}"));

    $tenantId = tenant('id');

    return static::updateOrCreate(
        ['key' => $key, 'tenant_id' => $tenantId],
        ['value' => $value]
    );
}
```

---

## Low / Informational

- **L1: `Tenant` model uses `$guarded = []`** (`vendor/stancl/tenancy/src/Database/Models/Tenant.php:35`). This is by design in stancl/tenancy (the VirtualColumn trait stores arbitrary data in the JSON `data` column). The `Tenant` model is only modified from the Filament admin panel, never from user input. Acceptable risk.

- **L2: `FeatureObserver` creates recursive observer calls.** When `Feature::create()` is called inside the observer for a descendant, the `created` event fires again, triggering another traversal. The `$exists` check prevents infinite loops, but the recursive calls create unnecessary work for deep category trees. Not a security issue but a performance concern.

- **L3: `tenant_cache_key()` returns `"tcentral:{$key}"` when no tenant is active.** This is correct behavior for central domain requests. However, any code calling `Setting::get()` from central domain context would read from the central cache key but query an unscoped DB (returning any tenant's setting). The current codebase only calls `Setting::get()` from the Filament Settings page (which has tenancy initialized). Flagged as informational.

- **L4: `Product` model has `affiliate_url` in `$fillable`.** This field is also an accessor (overriding the DB value with best-offer logic when the DB value is null). Including it in `$fillable` means an attacker who controls a Product create/update call could inject a custom affiliate URL, bypassing the computed logic. However, Product creation only occurs from admin panel or API controllers (which are token-protected and don't accept `affiliate_url` in validated input). Acceptable risk.

- **L5: No `declare(strict_types=1)` on several models.** `Product.php`, `Category.php`, `Feature.php`, `Preset.php`, `Brand.php`, `User.php`, `Setting.php`, `ProductFeatureValue.php` lack strict types. While not a direct security vulnerability, strict types prevent subtle type coercion bugs. Informational.

- **L6: `buying_guide` content rendered with `{!! strip_tags(..., '<p><br>...') !!}`.** The allowed tag list includes `<a>`, which permits `href="javascript:..."` XSS if an admin inserts it via the RichEditor. Since only authenticated admins with `@pw2d.com` emails can set this content, the risk is very low (admin self-XSS). If the RichEditor ever becomes user-facing, this must be changed to use a proper HTML sanitizer (e.g., `HTMLPurifier`).

- **L7: `hero_headline` rendered with `{!! strip_tags(..., '<span><br><em><strong>') !!}`.** Same pattern as L6. Admin-only content, allowed tags are safe (no `<a>`, `<script>`, etc.). Acceptable.

---

## Passed Checks

- **Mass assignment: All models define `$fillable` explicitly.** No model uses `$guarded = []` (the vendor `Tenant` base model does, but that is expected and documented in stancl/tenancy).

- **`User::$hidden` properly configured.** `password` and `remember_token` are hidden from serialization.

- **`User::canAccessPanel()` gates admin access.** Only `@pw2d.com` email addresses can log into the Filament panel.

- **`User::password` cast to `hashed`.** Passwords are automatically hashed via Laravel's `hashed` cast.

- **`BelongsToTenant` trait present on all core tenant-scoped models.** `Product`, `ProductOffer`, `Category`, `Feature`, `Preset`, `Brand`, `Store`, `Setting`, `AiMatchingDecision`, `SearchLog` -- all have the trait.

- **`TenantScope` correctly applied.** The stancl `TenantScope` adds `WHERE tenant_id = ?` to all Eloquent queries when tenancy is initialized. The scope does nothing when tenancy is not initialized (central domain), which is the correct behavior.

- **`BelongsToTenant` auto-sets `tenant_id` on create.** The trait's `creating` callback automatically sets `tenant_id` from the active tenant if not explicitly provided. This ensures new records are always scoped.

- **API controllers explicitly pass `tenant_id`.** `BatchImportController` and `ProductImportController` derive `tenant_id` from the resolved `$category->tenant_id` (which is Eloquent-scoped). `OfferIngestionService` uses `tenant('id')`. No controller relies solely on auto-set.

- **`Tenant::sanitizeColor()` regex validates CSS color values.** Only allows `#hex`, `rgb()`, and `hsl()` formats. Prevents CSS injection via tenant branding fields. Properly used in the layout Blade template.

- **`tenant_cache_key()` prevents cross-tenant cache pollution.** Cache keys are prefixed with `t{tenantId}:`, ensuring tenant A's cached settings never collide with tenant B's.

- **Raw SQL uses parameterized binding.** `whereRaw('LOWER(name) = ?', [...])`, `whereRaw('LOWER(name) REGEXP ?', [$regex])`, `orderByRaw('category_id = ? DESC', [...])` -- all use `?` placeholders with bound values. No SQL injection risk.

- **`DB::raw()` in `BatchImportController` is safe.** The `SUBSTRING_INDEX` expression wraps column references, not user input. The `whereIn` values are parameter-bound.

- **No `$guarded = []` in application models.** Only stancl's vendor models use empty guarded, which is their documented design.

- **`InitializeTenancyFromPayload` middleware validates tenant existence.** Unknown tenant IDs get a 404 response. The middleware initializes stancl tenancy before the controller runs.

- **`VerifyExtensionToken` middleware uses `hash_equals()`.** Timing-safe comparison prevents token enumeration via timing attacks. Fails securely if no token is configured.

---

## Summary

| Severity | Count | Key Risk |
|----------|-------|----------|
| Critical | 1 | Cross-tenant category_id validation bypass (C1) |
| High | 3 | Missing BelongsToTenant on join models (H1, H2), broken observers (H3) |
| Medium | 4 | Setting cache forever (M1), unscoped updateOrCreate (M2), unscoped pivot (M3), open tenant access (M4) |
| Low | 7 | Informational items, no immediate action needed |
| Passed | 15 | Core security checks verified |

The highest-priority fix is **C1** -- adding tenant-scoped `exists` rules to the three API validation locations. This is a one-line change per file and eliminates the cross-tenant information leak at the validation layer.
