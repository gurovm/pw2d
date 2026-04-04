# Spec 013: Enhance MergeDuplicateProducts Command

## Goal

Enhance the existing `pw2d:merge-duplicates` command to catch near-duplicate products that the current exact-match logic misses.

## Current State

`app/Console/Commands/MergeDuplicateProducts.php` groups by exact `(name, brand_id, category_id)`. This catches 7 of 11 duplicate pairs in category 17 but misses:

1. **Brand spelling variants**: "De'Longhi" (brand_id 222) vs "DeLonghi" (brand_id 228) — different `brand_id`
2. **Name variations**: "...LatteCrema ECAM35075SI" vs "...LatteCrema ECAM35075SI Silver"
3. **Title reformulations**: "Eletta Explore Cold Brew & Milk Frother" vs "Eletta Explore Espresso Machine with Cold Brew"

## File to Modify

`app/Console/Commands/MergeDuplicateProducts.php`

## Changes

### 1. Add `--category` option to signature

```php
protected $signature = 'pw2d:merge-duplicates
                        {tenant : The tenant ID}
                        {--category= : Limit to a specific category slug}
                        {--dry-run : Preview without modifying}';
```

Apply the filter in the query if present:
```php
->when($this->option('category'), fn ($q, $slug) => $q->whereHas('category', fn ($cq) => $cq->where('slug', $slug)))
```

### 2. Enhance grouping: fuzzy brand matching

After the current exact-match phase (which remains as-is), add a **Phase 2** that finds products with the same normalized name but different brand_id where the brands are equivalent.

Phase 2 logic:
1. Load remaining products (not yet merged)
2. For each product, compute a grouping key: `normalizeBrandForComparison(brand.name) . '|' . mb_strtolower(name)`
3. Groups with 2+ products where NOT all `brand_id` values are the same → these are brand-spelling duplicates
4. Merge using the same `mergeDuplicate()` logic

```php
// Phase 2: Brand-spelling duplicates (same normalized name, different brand_id)
$remaining = Product::withoutGlobalScopes()
    ->where('tenant_id', $tenant->id)
    ->where('is_ignored', false)
    ->whereNull('status')
    ->whereNotNull('category_id')
    ->when($categorySlug, fn ($q) => $q->whereHas('category', fn ($cq) => $cq->where('slug', $categorySlug)))
    ->with('brand:id,name')
    ->get(['id', 'name', 'brand_id', 'category_id']);

$fuzzyGroups = $remaining->groupBy(function ($p) {
    $normalizedBrand = \App\Services\AiService::normalizeBrandForComparison($p->brand?->name ?? '');
    return $normalizedBrand . '|' . mb_strtolower($p->name) . '|' . $p->category_id;
})->filter(fn ($group) => $group->count() > 1);
```

For each group, the keeper is the product with the lowest `id`. Merge the rest using `mergeDuplicate()`.

When merging a brand-variant duplicate, also **reassign the duplicate's brand_id to the keeper's brand_id** before deleting, so the offers end up on the correct brand.

### 3. Transfer feature values in `mergeDuplicate()`

Currently the method deletes the duplicate without transferring feature values. Add before `forceDelete()`:

```php
// Transfer feature values the canonical doesn't have
$canonicalFeatureIds = \App\Models\ProductFeatureValue::where('product_id', $canonicalId)
    ->pluck('feature_id');

\App\Models\ProductFeatureValue::where('product_id', $duplicate->id)
    ->whereNotIn('feature_id', $canonicalFeatureIds)
    ->update(['product_id' => $canonicalId]);

// Delete remaining (overlapping) feature values
\App\Models\ProductFeatureValue::where('product_id', $duplicate->id)->delete();
```

### 4. Recalculate price_tier after merges

After all merges complete, recalculate `price_tier` for each keeper that received new offers:

```php
// Recalculate price_tier for affected products
foreach ($affectedCanonicalIds as $id) {
    $product = Product::with('category')->find($id);
    if (!$product?->category) continue;
    
    $bestPrice = $product->offers()->whereNotNull('scraped_price')->min('scraped_price');
    if ($bestPrice !== null) {
        $newTier = $product->category->priceTierFor((float) $bestPrice);
        if ($newTier !== null) {
            $product->update(['price_tier' => $newTier]);
        }
    }
}
```

### 5. Console output

```
Phase 1: Exact duplicates (name + brand_id + category_id)
  ✓ Merge #3501 into #3420 "De'Longhi Dinamica ECAM35025SB"
  ...
  7 exact duplicates found.

Phase 2: Brand-spelling duplicates (same name, different brand spelling)
  ✓ Merge #3487 "DeLonghi ECAM22110B Black" into #3350 "De'Longhi ECAM22110B Black"
  1 brand-spelling duplicate found.

[DRY RUN] No changes made. Remove --dry-run to execute.
```

## What This Won't Catch

The remaining ~3 near-match pairs have genuinely different names:
- "LatteCrema ECAM35075SI" vs "LatteCrema ECAM35075SI Silver"
- "Eletta Explore Cold Brew & Milk Frother" vs "Eletta Explore Espresso Machine with Cold Brew"
- "Magnifica XS" vs "ECAM22110SB Magnifica XS"

These require AI or manual intervention. They are edge cases that the spec-012 fix (fuzzy brand heuristic in matchProduct) will prevent going forward. For existing ones, admin can merge manually via Filament.

## No migration needed
