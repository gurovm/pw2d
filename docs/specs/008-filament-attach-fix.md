# Spec 008: Fix Dead `attach()` Call in Filament

**Priority:** MEDIUM (bug — crashes if used)
**Audit refs:** Performance agent bug finding (ListProducts.php:171)

---

## Problem

In `app/Filament/Resources/ProductResource/Pages/ListProducts.php:171`, the "Import via AI" Filament action calls:

```php
$product->categories()->attach($data['category_id']);
```

This references a many-to-many pivot table (`category_product`) that **no longer exists**. The schema was refactored to a direct `category_id` FK on the `products` table. This code will throw a SQL error if an admin triggers the action.

Additionally, the `Brand::firstOrCreate()` on line 157-159 is missing `tenant_id` scoping:

```php
$brand = Brand::firstOrCreate(
    ['name' => $parsed['brand']],
    ['name' => $parsed['brand']]
);
```

## Changes Required

### 1. Remove the `attach()` call and set `category_id` directly

**File:** `app/Filament/Resources/ProductResource/Pages/ListProducts.php`

```php
// BEFORE (lines 163-171)
$product = Product::create([
    'name' => $parsed['name'],
    'brand_id' => $brand->id,
    'amazon_rating' => 0,
    'amazon_reviews_count' => 0,
]);
$product->categories()->attach($data['category_id']);

// AFTER
$product = Product::create([
    'tenant_id'            => $category->tenant_id,
    'category_id'          => $data['category_id'],
    'name'                 => $parsed['name'],
    'brand_id'             => $brand->id,
    'slug'                 => Str::slug($parsed['name'] . '-' . Str::random(5)),
    'amazon_rating'        => 0,
    'amazon_reviews_count' => 0,
    'status'               => null,
]);
```

### 2. Add `tenant_id` to `Brand::firstOrCreate()`

```php
// BEFORE
$brand = Brand::firstOrCreate(
    ['name' => $parsed['brand']],
    ['name' => $parsed['brand']]
);

// AFTER
$brand = Brand::firstOrCreate(
    ['name' => $parsed['brand'], 'tenant_id' => tenant('id')],
    ['name' => $parsed['brand'], 'tenant_id' => tenant('id')]
);
```

### 3. Consider replacing this entire action

This Filament action duplicates the AI import logic (yet another copy of the Gemini call). After Spec 005 (GeminiService) and Spec 006 (import refactor) are done, this action should be rewritten to use `GeminiService` or simply dispatch a `ProcessPendingProduct` job.

For now, the minimum fix is correcting the `attach()` bug and adding tenant scoping.

## Files Modified

| File | Action |
|------|--------|
| `app/Filament/Resources/ProductResource/Pages/ListProducts.php` | Fix `attach()`, add tenant scoping |

## Testing

- **Feature:** Filament "Import via AI" action creates a product with correct `category_id` and `tenant_id`.
- **Feature:** Brand is created with correct `tenant_id`.
- **Negative:** Confirm no reference to `categories()` many-to-many relationship remains.
