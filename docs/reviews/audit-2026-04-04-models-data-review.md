# Review: Models & Data Layer
**Date:** 2026-04-04
**Status:** Approved with comments

**Scope:** All Eloquent models, FeatureObserver, CategoryObserver, ProductScoringService, cache helper.

---

## Critical Issues (must fix)

### C1: Observers reference non-existent columns on Feature model

Both `FeatureObserver::propagateToDescendants()` and `CategoryObserver::copyParentFeatures()` reference `slug`, `data_type`, and `weight` properties on the Feature model. None of these columns exist in the `features` migration or in Feature's `$fillable`. Creating a Feature with these fields will silently discard them (mass assignment protection) or throw a column-not-found error on insert.

**Files:** `/Users/mg/projects/power_to_decide/pw2d/app/Observers/FeatureObserver.php` (lines 42-45), `/Users/mg/projects/power_to_decide/pw2d/app/Observers/CategoryObserver.php` (lines 37-40)

**Fix:** Either (a) remove these observers entirely if subcategory feature propagation is not actively used, or (b) update them to reference only columns that exist on the `features` table: `name`, `unit`, `is_higher_better`, `min_value`, `max_value`, `sort_order`, `tenant_id`, `category_id`.

### C2: Observers do not set `tenant_id` on propagated Features

When `FeatureObserver` or `CategoryObserver` create new Feature records for descendant/child categories, they do not pass `tenant_id`. The `BelongsToTenant` trait on Feature will auto-set it from the current tenancy context, but if these observers fire in a context where tenancy is not initialized (e.g., a seeder, tinker session, or queued job without tenant initialization), the created features will have `tenant_id = null` and become invisible to tenant-scoped queries.

**Files:** Same as C1.

**Fix:** Explicitly pass `'tenant_id' => $feature->tenant_id` (or `$parentFeature->tenant_id`) in the `Feature::create()` calls.

### C3: `bestOffer` accessor does not filter out null-price offers

`Product::bestOffer` sorts offers by `scraped_price` ascending, but null prices sort before non-null values in PHP comparisons. An offer with `scraped_price = null` (e.g., newly ingested, price not yet scraped) will be returned as the "best" offer, leading to a null `affiliate_url` or broken display.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Models/Product.php` (lines 112-118)

**Fix:** Filter out null-price offers before sorting:
```php
return $this->offers
    ->filter(fn ($o) => $o->scraped_price !== null)
    ->sortBy([...])
    ->first();
```

### C4: N+1 on `visibleProducts` and `selectedProduct` -- missing `offers.store` eager load

`ProductCompare::visibleProducts()` (line 207) and `selectedProduct()` (line 91) load products with `['brand', 'featureValues.feature']` but not `offers.store`. The Blade view accesses `$product->image_url` and `$product->affiliate_url`, both of which trigger the `offers` relationship (and `bestOffer` further accesses `store`). Each rendered product card fires 1-2 extra queries.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Livewire/ProductCompare.php` (lines 91, 181, 207)

**Fix:** Add `'offers.store'` to the `with()` array in all three query locations:
```php
Product::with(['brand', 'featureValues.feature', 'offers.store'])
```

---

## Suggestions (recommended improvements)

### S1: Strict type comparison in ProductScoringService

`$totalWeight === 0` on line 80 uses strict identity. Since `$totalWeight` accumulates float values (`$amazonRatingWeight`, `$priceWeight`), it can be `0.0` (float), which is not strictly equal to `0` (int). Use `$totalWeight == 0` or `(float) $totalWeight === 0.0` to handle both.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Services/ProductScoringService.php` (line 80)

### S2: Inconsistent `declare(strict_types=1)` across models

Only 5 of 14 models use `declare(strict_types=1)`: `AiCategoryRejection`, `AiMatchingDecision`, `ProductOffer`, `Store`, `Tenant`. The project standard mandates strict types for PHP 8.3. Remaining models (`Product`, `Category`, `Feature`, `Preset`, `Brand`, `Setting`, `SearchLog`, `User`, `ProductFeatureValue`, `FeaturePreset`) should be updated for consistency.

### S3: `Storage::url()` vs `Storage::disk('public')->url()` inconsistency

`Product::imageUrl` accessor (line 165) uses `Storage::url($this->image_path)` (default `local` disk), while the `booted()` cleanup (line 21) and the image download job use `Storage::disk('public')`. This works by convention (the `local` disk's `url` falls through to `/storage/`), but explicitly using `Storage::disk('public')->url(...)` would be more robust.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Models/Product.php` (line 165)

### S4: `getAllDescendants()` triggers recursive N+1 queries

`Category::getAllDescendants()` lazy-loads `children` at each level of recursion, issuing one query per tree node. It is only called from `FeatureObserver::created`, so it is not on a hot path. If subcategory trees deepen, consider either eager-loading children recursively or using a single query with a self-join/CTE.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Models/Category.php` (lines 101-111)

### S5: `Product::booted()` image cleanup bypassed by cascade deletes

The `deleting` event on Product cleans up stored images, but if a product is deleted via DB-level cascade (e.g., tenant deletion) or raw query, the event never fires and orphan images accumulate on disk. Consider a periodic orphan-image cleanup command as a safety net.

### S6: `database-schema.md` references outdated column names

The schema doc mentions `product_offers.store_name` (migrated to `store_id` in March 2026) and `search_logs.results` / `search_logs.summary` (actual columns are `results_count` and `response_summary`). Updating the docs would prevent confusion for future contributors.

### S7: `FeaturePreset` pivot model has `$incrementing = true` but standard pivots do not need it

`FeaturePreset` sets `$incrementing = true` and the migration has `$table->id()`, which is fine, but the pivot table has an auto-increment `id` that is never used as a lookup key. This is harmless but unconventional for a pivot.

### S8: Missing return type on `FeaturePreset` relationships

`FeaturePreset::feature()` and `FeaturePreset::preset()` lack `BelongsTo` return type hints.

**File:** `/Users/mg/projects/power_to_decide/pw2d/app/Models/FeaturePreset.php` (lines 15, 20)

---

## Praise (what was done well)

- **Consistent `BelongsToTenant` usage:** All scoped models correctly use the trait and include `tenant_id` in `$fillable`. The project context's scoped-model list matches the codebase exactly.

- **Well-structured Product accessors:** The `bestPrice`, `bestOffer`, `affiliateUrl`, `estimatedPrice`, and `imageUrl` accessor chain is clean, follows a clear resolution priority, and keeps vendor-specific data firmly in `ProductOffer`.

- **`tenant_cache_key` helper is simple and effective:** Prevents cross-tenant cache pollution with a clean, globally available function. `Setting::get`/`set` use it correctly with `rememberForever` + explicit bust.

- **`ProductScoringService` is well-optimized:** O(1) hash-map lookups instead of repeated `Collection::where()` scans. Pre-computed feature ranges. Clean separation from the Livewire component. The serialization optimization (stdClass objects from cache) in `ProductCompare` is a smart performance trade.

- **Proper `$casts` on all models:** Boolean, float, decimal, integer, and array casts are applied correctly. No missing casts on critical columns.

- **Tenant model's `sanitizeColor()` prevents CSS injection:** The regex-based validation for hex/rgb/hsl colors is a solid defense-in-depth measure.

- **Clean separation of concerns:** Models are lean data containers with relationships and accessors. No business logic in models. Scoring lives in a dedicated service. Cache logic is helper-extracted.

- **Thorough `$fillable` definitions:** All models define `$fillable` explicitly. No unguarded models or `$guarded = []` shortcuts.
