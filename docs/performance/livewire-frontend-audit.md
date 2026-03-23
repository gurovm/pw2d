# Performance Audit: Livewire, Frontend & Assets
**Date:** 2026-03-22

## Summary
> 1. **Missing `loading="lazy"` and image dimensions on most images** -- category images in home/subcategory grids, similar-products images, and the product modal hero image all lack lazy loading and explicit width/height, causing layout shift (CLS).
> 2. **`selectedProduct` computed property re-queries on every render** -- not persisted, and accessed multiple times in `render()` method.
> 3. **`availableBrands` uses `persist: true` but is never invalidated** -- when a new product is imported, the persisted cache still holds stale brand data.

## Critical Issues

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **`selectedProduct` computed lacks `persist: true`** | `app/Livewire/ProductCompare.php:87-93` | Every call to `$this->selectedProduct` in `render()` (lines 503-515) re-runs the query. It is accessed ~8 times in the modal blade template via `$this->selectedProduct->...`. Livewire memoizes within a single request, but the DB query still runs once per render cycle. | Add `#[Computed(persist: true)]` and manually unset when `selectedProductSlug` changes. Or call it once at the start of render and pass to the view. |

## High Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **Category images missing width/height/lazy** | `resources/views/livewire/home.blade.php:75` | `<img>` tags for category cards have no `width`, `height`, or `loading="lazy"`. Causes CLS on homepage. | Add `width="400" height="300" loading="lazy"` to all category card images. |
| **Subcategory images missing width/height/lazy** | `resources/views/livewire/product-compare.blade.php:31` | Same issue on parent category pages. | Add `width="400" height="300" loading="lazy"`. |
| **Similar-products images missing width/height/lazy** | `resources/views/components/similar-products.blade.php:15` | Images in the modal's "Similar Products" section have no dimensions. | Add `width="200" height="200" loading="lazy"`. |
| **Product modal hero image missing `loading="lazy"`** | `resources/views/livewire/product-compare.blade.php:557-559` | The modal hero image is 400x400 with dimensions set, but no lazy loading. Since the modal is below-fold (opened by user click), it should lazy-load. | Add `loading="lazy"`. |
| **`availableBrands` persist never invalidated** | `app/Livewire/ProductCompare.php:70-85` | `#[Computed(persist: true)]` stores the brands in the component dehydration. If a new brand/product is added, stale data persists until the user hard-refreshes. | Either remove `persist: true` or add a manual `unset($this->availableBrands)` in methods that could trigger data changes. Since this is read-only for visitors, the risk is low -- new brands appear after session expires. Acceptable trade-off, but document it. |
| **Third-party scripts loaded render-blocking** | `resources/views/components/layouts/app.blade.php:47-78,81-93` | PostHog and Google Analytics scripts are inline in `<head>` without `defer` or `async` on the inline blocks. The PostHog snippet does use async for the external script, but the init code runs synchronously. | Move analytics init to `DOMContentLoaded` or place scripts before `</body>`. The GA `gtag.js` already uses `async`. |
| **Google Fonts loaded render-blocking** | `resources/views/components/layouts/app.blade.php:96-97` | `<link>` to fonts.bunny.net is render-blocking. | Add `rel="preload" as="style" onload="this.rel='stylesheet'"` pattern, or inline critical font-face declarations and load the full stylesheet asynchronously. |

## Medium Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **No `wire:poll` misuse found** | All blade files | Good -- no polling detected anywhere. | N/A |
| **ComparisonHeader passes full Feature collection as public prop** | `app/Livewire/ComparisonHeader.php:16-17` | The `$features` collection (Eloquent models) is serialized into every Livewire response payload. For categories with 10+ features, this adds ~2-5KB per request. | Consider passing only the data needed (id, name, unit) as a plain array instead of full Eloquent models. |
| **ProductCompare stores full category model as public prop** | `app/Livewire/ProductCompare.php:24` | The entire Category model (including `buying_guide` JSON, `description`, etc.) is serialized in every Livewire payload. | Store only `$categoryId` and `$categorySlug` as public props; load the full model in computed or at render time. |
| **Custom CSS in product modal inline `<style>` tag** | `resources/views/livewire/product-compare.blade.php:605-617` | Scrollbar styles are defined inside a `<style>` tag within the component markup. This gets re-injected on every Livewire DOM diff. | Move to `app.css` as a global utility class. |
| **Vite config minimal -- no chunk splitting** | `vite.config.js` | Single JS bundle. As the app grows, the entire JS payload is downloaded on every page. | Add `build.rollupOptions.output.manualChunks` to split vendor libraries (Alpine, Livewire) into a separate chunk for better caching. |

## Low Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **No `fetchpriority="high"` on LCP image (category hero)** | `resources/views/livewire/product-compare.blade.php:60-62` | The category hero image (line 60) is likely the LCP element on category pages but lacks `fetchpriority="high"`. | Add `fetchpriority="high"` to the category hero image. |
| **`Storage::url()` vs `asset('storage/...')` inconsistency** | Multiple blade files | `home.blade.php:75` uses `Storage::url()`, `product-compare.blade.php:60` uses `asset('storage/...')`. Both work but are inconsistent. | Standardize on `Storage::url()` for all storage-disk images. |

## Image Optimization Status

| Check | Status | Notes |
|-------|--------|-------|
| WebP conversion | GOOD | `ImageOptimizer::toWebp()` runs on AI-imported images |
| Product grid images have width/height | GOOD | `width="400" height="400"` on line 394 |
| Product grid images have loading="lazy" | GOOD | Present on line 394 |
| Category card images have dimensions | MISSING | Lines 75 (home), 31 (product-compare) |
| Similar products images have dimensions | MISSING | Line 15 (similar-products) |
| Navigation logo has fetchpriority | GOOD | Lines 10, 18 |
| Category hero has fetchpriority | MISSING | Line 60-62 |
