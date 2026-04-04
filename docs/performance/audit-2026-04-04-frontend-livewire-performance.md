# Performance Audit: Frontend & Livewire
**Date:** 2026-04-04
**Auditor:** Performance Auditor Agent (Opus 4.6)
**Scope:** Livewire components, Blade templates, ProductScoringService, SeoSchema, image optimization

## Summary
> **Top 3 things to fix (ordered by impact):**
>
> 1. **N+1 queries in product grid and product modal:** `visibleProducts` eager-loads `brand` and `featureValues.feature` but NOT `offers.store`. The template then accesses `$product->image_url` and `$product->affiliate_url`, which lazy-load `offers` and `offers.store` for each of the 12 visible products. Estimated cost: **~36-48 extra queries per page render.**
>
> 2. **Price slider uses `wire:model.live` without debounce:** The max-price range input sends a Livewire round-trip on every mouse-movement pixel, each triggering full `scoredProducts` + `visibleProducts` recomputation (cache invalidation due to price change in cache key). Estimated cost: **20-50 redundant server requests per drag.**
>
> 3. **SimilarProducts component: N+1 + `ORDER BY RAND()` + no eager-loading:** Loads 4 products without any `with()`, then the template accesses `brand`, `image_url` (offers), and `affiliate_url` (offers.store). Also uses `inRandomOrder()` on MySQL which is `ORDER BY RAND()`. Estimated cost: **~12-16 extra queries per product modal open.**

---

## Critical Issues

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| C1 | **N+1: `visibleProducts` missing `offers.store` eager-load** | `app/Livewire/ProductCompare.php:207` | Template accesses `$product->image_url` and `$product->affiliate_url` for each of 12 products. Each triggers lazy-load of `offers` (1 query) and then `store` per offer (1+ queries). **~36-48 extra queries per render.** | Add `'offers.store'` to the `with()` call: `Product::whereIn('id', $topIds)->with(['brand', 'featureValues.feature', 'offers.store'])` |
| C2 | **N+1: `selectedProduct` missing `offers.store` eager-load** | `app/Livewire/ProductCompare.php:91` | The product modal template accesses `affiliate_url` (x3 via desktop CTA, mobile CTA, and the `openProduct` dispatch) and `image_url`. Each lazy-loads `offers` + `store`. **~6-10 extra queries per modal open.** | Change to: `Product::with(['brand', 'featureValues.feature', 'offers.store'])` |
| C3 | **N+1: `SimilarProducts` loads products without ANY eager-loading** | `app/View/Components/SimilarProducts.php:24-45` | Template accesses `->brand?->name`, `->image_url`, `->affiliate_url` for 4 products. **~12-16 extra queries per modal** (mitigated by 7-day cache, but first request per product is expensive). | Add `->with(['brand', 'offers.store'])` to both queries. |
| C4 | **Price slider `wire:model.live` without debounce** | `resources/views/livewire/product-compare.blade.php:322` | `wire:model.live` on a range slider fires on every `input` event (continuous during drag). Each request invalidates cache (price is in cache key), recomputes `scoredProducts`, and re-queries `visibleProducts`. **20-50 server round-trips per drag.** | Change to `wire:model.live.debounce.300ms="selectedPrice"` or, better, use Alpine to track the value locally and only fire on `@change`. |

## High Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| H1 | **DB query in Blade template on every render** | `resources/views/livewire/comparison-header.blade.php:15` | `\App\Models\Category::find($categoryId)->name` executes a DB query inside the template on every single Livewire re-render. | Pass category name as a prop from parent, or resolve it in `mount()` and store as a public property `$categoryName`. |
| H2 | **Preset query in `SeoSchema::forLeafCategory` runs inside `render()`** | `app/Support/SeoSchema.php:161-163` | Every render calls `Preset::where('category_id', ...)->get()` and then filters in PHP by slug. This is a DB query on every slider change. | Accept the presets collection as a parameter (already loaded in ComparisonHeader), or cache the preset lookup. Alternatively, add a `slug` column to presets and query directly. |
| H3 | **ComparisonHeader re-queries Category already loaded by parent** | `app/Livewire/ComparisonHeader.php:67` | `Category::with('children')->find($this->categoryId)` re-loads the category + children that `ProductCompare::mount()` already has. Wastes 1-2 queries. | Pass `$category` and `$samplePrompts` as props from the parent component. |
| H4 | **`scoredProducts` computed property not persisted** | `app/Livewire/ProductCompare.php:107` | `#[Computed]` without `persist: true` means the scoring runs on every Livewire request. While the raw DB data is cached (90s), the `scoreAllProducts()` CPU work (collection mapping, normalization, sorting) re-runs on every slider change. For 200+ products this is meaningful. | This is intentional (weights change), but consider using `persist: true` with explicit cache invalidation only when weights/filters change. Alternatively, debounce the weight-update events so scoring runs less often. |
| H5 | **`ORDER BY RAND()` in SimilarProducts** | `app/View/Components/SimilarProducts.php:29,43` | `inRandomOrder()` translates to `ORDER BY RAND()` on MySQL, which requires a full table scan for the category. Mitigated by 7-day cache, but first request per product is slow. | Since results are cached for 7 days, this is acceptable. However, if the category has 500+ products, consider using a random offset with `LIMIT` instead: `->offset(rand(0, max(0, $count - 4)))->limit(4)`. |
| H6 | **`$this->scoredProducts->count()` called in `analyzeUserNeeds`** | `app/Livewire/ProductCompare.php:401` | After the AI concierge sets new weights, this line accesses the computed property to log the count. Since weights just changed, this triggers a full recompute of `scoredProducts`. The render cycle will trigger it again moments later. | Defer the count logging or use a separate lightweight count query. |

## Medium Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| M1 | **Category images missing `width`, `height`, and `loading="lazy"`** | `resources/views/livewire/home.blade.php:75`, `product-compare.blade.php:31` | Category card images and subcategory images have no dimensions, causing CLS. No `loading="lazy"` means below-fold images block initial paint. | Add `width="400" height="300" loading="lazy"` to category card images. |
| M2 | **Similar product images missing `width`, `height`, and `loading="lazy"`** | `resources/views/components/similar-products.blade.php:15` | Product images in the "Similar Products" section have no dimensions or lazy loading. These are always below the fold. | Add `width="200" height="200" loading="lazy"`. |
| M3 | **Category hero image missing `fetchpriority="high"`** | `resources/views/livewire/product-compare.blade.php:60-62` | The category hero image is the LCP candidate but has no `fetchpriority="high"` and no explicit dimensions. | Add `fetchpriority="high" width="800" height="600"` to the category hero `<img>` tag. |
| M4 | **Product modal image missing `fetchpriority="high"`** | `resources/views/livewire/product-compare.blade.php:557-560` | When a product modal opens, the large product image is the new LCP but lacks `fetchpriority`. | Add `fetchpriority="high"` to the modal product image. |
| M5 | **Search result images missing dimensions** | `resources/views/livewire/global-search-results.blade.php:74` | Small product thumbnails in search results have no `width`/`height`, causing micro-CLS in the dropdown. | Add `width="32" height="32" loading="lazy"`. |
| M6 | **AutoAnimate loaded from CDN instead of bundled** | `resources/views/livewire/product-compare.blade.php:366` | `import('https://cdn.jsdelivr.net/npm/@formkit/auto-animate')` is a dynamic CDN import on every category page. Adds a DNS lookup + download to a third-party host. | Install via npm (`npm install @formkit/auto-animate`) and import in `app.js` or locally in the component. |
| M7 | **`<style>` blocks repeated inside Livewire templates** | `product-compare.blade.php:211-220`, `product-compare.blade.php:605-618` | Inline `<style>` blocks for `.scrollbar-hide` and `.custom-scrollbar` are rendered server-side on every Livewire update, adding bytes to the wire payload. | Move to `app.css` (Tailwind layer). |
| M8 | **`visibleProducts` in H2H mode queries full products redundantly** | `app/Livewire/ProductCompare.php:180-190` | In comparison mode, `visibleProducts` loads the same products that were already loaded by `scoredProducts` (just different columns). The full Eloquent models with brand/features are loaded again. | Minor -- only 2-4 products in H2H mode. Acceptable. |

## Low Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| L1 | **Brand filter dropdown serialized inline in Blade** | `product-compare.blade.php:237` | `$this->availableBrands->map(...)` is called inline in the Blade template. While `availableBrands` is `persist: true`, the JSON serialization happens on every render. | Move the JSON serialization to a computed property and pass as a view variable. |
| L2 | **Multiple `$this->selectedProduct` accessor calls in modal template** | `product-compare.blade.php:556-800` | The modal section calls `$this->selectedProduct` ~15 times. Each call resolves the computed property (though Livewire caches it within the same request cycle). | Assign to a local variable: `@php $product = $this->selectedProduct; @endphp` for clarity and to make the caching intent explicit. |
| L3 | **PostHog inline script in every page** | `resources/views/components/layouts/app.blade.php:59-91` | The PostHog bootstrap script is render-blocking and executes synchronously. | Add `defer` to the PostHog script tag, or load it via a separate async bundle. |

---

## Caching Recommendations

| Data | Current Strategy | Recommended TTL | Expected Gain |
|------|-----------------|-----------------|---------------|
| Raw scored product data (DB query) | `Cache::remember()` 90s, tenant-scoped | 90s (adequate) | Already optimized. Cache key correctly includes tenant, category, brand, price. |
| `availableBrands` | `#[Computed(persist: true)]` | Adequate for Livewire lifecycle | Already optimized. |
| `scoredProducts` scoring computation | No cache (intentional -- weights change) | N/A | Consider debouncing weight updates instead of caching. |
| Preset lookup in SeoSchema | No cache, queried every render | Cache::remember 3600s or pass as prop | Saves 1 query per render. |
| Category name in ComparisonHeader blade | DB query every render | Store as public prop in mount() | Saves 1 query per render. |
| Category + children in ComparisonHeader | DB query in mount() | Pass from parent as prop | Saves 2 queries on mount. |
| SimilarProducts | 7-day cache per product | Adequate | Already optimized. |
| Home page categories | 3600s tenant-scoped cache | Adequate | Already optimized. |
| GlobalSearch categories+presets | 3600s tenant-scoped cache | Adequate | Already optimized. |
| Settings (PostHog, GA, GSC) | `Cache::rememberForever` | Adequate | Already optimized. |

---

## Concrete Fixes

### Fix C1 + C2: Add `offers.store` to eager-loading

**File:** `app/Livewire/ProductCompare.php`

In `visibleProducts()`, change both `Product::whereIn()` calls (lines 180 and 207):
```php
// Before:
$fullProducts = Product::whereIn('id', $topIds)
    ->with(['brand', 'featureValues.feature'])
    ->get()
    ->keyBy('id');

// After:
$fullProducts = Product::whereIn('id', $topIds)
    ->with(['brand', 'featureValues.feature', 'offers.store'])
    ->get()
    ->keyBy('id');
```

In `selectedProduct()` computed (line 91):
```php
// Before:
Product::with(['brand', 'featureValues.feature'])->where('slug', ...)->first()

// After:
Product::with(['brand', 'featureValues.feature', 'offers.store'])->where('slug', ...)->first()
```

**Estimated savings:** ~40-55 queries eliminated per page render + modal open.

### Fix C3: Eager-load in SimilarProducts

**File:** `app/View/Components/SimilarProducts.php`

Add `->with(['brand', 'offers.store'])` to both `Product::where(...)` chains:
```php
$sameTier = Product::where('category_id', $product->category_id)
    ->where('id', '!=', $product->id)
    ->where('price_tier', $product->price_tier)
    ->whereNull('status')
    ->where('is_ignored', false)
    ->with(['brand', 'offers.store'])  // ADD THIS
    ->inRandomOrder()
    ->limit(4)
    ->get();
```

Same for the `$fill` query.

**Estimated savings:** ~12-16 queries eliminated per modal open (on cache miss).

### Fix C4: Debounce price slider

**File:** `resources/views/livewire/product-compare.blade.php` line 322

Option A (simple): Add debounce to wire:model:
```html
wire:model.live.debounce.300ms="selectedPrice"
```

Option B (better UX): Use Alpine for instant visual feedback, fire Livewire only on release:
```html
<div x-data="{ localPrice: @entangle('selectedPrice') }">
    <input type="range" x-model.number="localPrice"
           @change="$wire.set('selectedPrice', localPrice)"
           min="0" max="{{ $maxPrice }}" step="5">
    <span x-text="'$' + localPrice.toLocaleString()"></span>
</div>
```

**Estimated savings:** Eliminates 20-50 redundant server requests per price drag.

### Fix H1: Remove DB query from comparison-header blade

**File:** `app/Livewire/ComparisonHeader.php`

Add a public property:
```php
public string $categoryName = '';
```

In `mount()`, after the category query that already exists:
```php
$this->categoryName = $category?->name ?? 'Unknown Category';
```

Then in `resources/views/livewire/comparison-header.blade.php` line 15, replace:
```php
@php $categoryName = \App\Models\Category::find($categoryId)->name ?? 'Unknown Category'; @endphp
```
with:
```php
@php $categoryName = $categoryName; @endphp
```
Or simply use `$categoryName` directly in the template.

### Fix H2: Pass presets to SeoSchema instead of querying

**File:** `app/Support/SeoSchema.php`

Accept a presets collection as a parameter in `forCategoryPage()`:
```php
public static function forCategoryPage(
    Category $category,
    Collection $subcategories,
    ?string $selectedProductSlug,
    ?Product $selectedProduct,
    ?string $activePresetSlug,
    Collection $visibleProducts,
    ?Collection $presets = null,  // ADD
): array
```

Then in `forLeafCategory()`, instead of querying Presets, filter from the passed collection.

---

## Image Attribute Summary

| Image | Location | Missing Attributes |
|-------|----------|--------------------|
| Category cards (home) | `home.blade.php:75` | `width`, `height`, `loading="lazy"` |
| Subcategory cards | `product-compare.blade.php:31` | `width`, `height`, `loading="lazy"` |
| Category hero | `product-compare.blade.php:60-62` | `width`, `height`, `fetchpriority="high"` |
| Product grid cards | `product-compare.blade.php:394` | Has `width`, `height`, `loading="lazy"` -- **OK** |
| Product modal | `product-compare.blade.php:557-560` | Has `width`, `height` -- add `fetchpriority="high"` |
| Similar products | `similar-products.blade.php:15` | `width`, `height`, `loading="lazy"` |
| Search result thumbnails | `global-search-results.blade.php:74` | `width`, `height`, `loading="lazy"` |
| Nav logo | `navigation.blade.php:9-18` | Has `width`, `height`, `fetchpriority="high"` -- **OK** |

---

## Wire Payload Optimization

The `ProductCompare` component has several large public properties that are serialized on every Livewire request:

- `$category` (full Eloquent model) -- serialized and sent on every update
- `$features` (collection of Feature models) -- serialized on every update
- `$subcategories` (collection of Category models with counts) -- serialized on every update
- `$chatHistory` (grows unbounded) -- serialized on every update

**Recommendation:** For `$category`, consider storing only the ID and slug as primitives, and loading the full model in a computed property. For `$chatHistory`, cap at 10 entries.

---

## Architecture Note: Scoring Efficiency

The `ProductScoringService` itself is well-optimized:
- Uses O(1) hash map lookups instead of Collection `where()` scans
- Pre-computes feature ranges in a single pass
- Operates on plain stdClass objects, not full Eloquent models

The bottleneck is not the scoring algorithm but the frequency at which it runs (every Livewire request) and the N+1 queries that follow in `visibleProducts`. The highest-ROI fix is adding `offers.store` to eager-loading (C1/C2/C3), followed by debouncing the price slider (C4).
