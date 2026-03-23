# Performance Audit: Jobs, Memory & Queue
**Date:** 2026-03-22

## Summary
> 1. **ProcessPendingProduct and RescanProductFeatures both use `SerializesModels` but only pass IDs** -- the trait is unnecessary and adds serialization overhead.
> 2. **BatchImportController runs N individual UPDATE queries for refreshed products** -- should be a single bulk operation.
> 3. **No backoff strategy defined on queue jobs** -- failed retries fire immediately, hammering the Gemini API.

## High Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **No backoff on queue jobs** | `app/Jobs/ProcessPendingProduct.php:22-23`, `app/Jobs/RescanProductFeatures.php:31-32` | Both jobs have `$tries = 3` but no `$backoff` property. When Gemini returns 429 (rate limit), the retry fires immediately, likely hitting the same rate limit. | Add `public array $backoff = [10, 60, 300];` for exponential backoff (10s, 60s, 5min). |
| **SerializesModels trait on ID-only jobs** | `app/Jobs/ProcessPendingProduct.php:21`, `app/Jobs/RescanProductFeatures.php:29` | Both jobs only accept `int $productId` and `int $categoryId` -- no Eloquent models. `SerializesModels` adds overhead scanning for model properties to serialize. | Remove `SerializesModels` trait from both jobs since they only use primitive IDs. |
| **BatchImportController: N updates in a loop** | `app/Http/Controllers/Api/BatchImportController.php:101-110` | For 100 refreshed products, 100 individual `DB::table()->where()->update()` queries. | Use a single bulk update with `CASE WHEN`: `DB::statement("UPDATE products SET scraped_price = CASE id WHEN ... END WHERE id IN (...)")`. Or collect and use `upsert()` if the columns allow it. |

## Medium Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **ProcessPendingProduct downloads images synchronously** | `app/Jobs/ProcessPendingProduct.php:177` | Image download + WebP conversion happens inside the same job as AI scoring. If the image CDN is slow (15s timeout), the 60s job timeout is tight. | Consider dispatching image download as a separate chained job with its own timeout. This also enables retry independence. |
| **Filament importViaAI: stale code path** | `app/Filament/Resources/ProductResource/Pages/ListProducts.php:163-171` | Line 171 calls `$product->categories()->attach()` (pivot table), but the schema uses `category_id` FK, not a pivot. This is dead/broken code from an older schema. | Fix to use `$product->update(['category_id' => $data['category_id']])` or remove the stale import action. |
| **ProcessPendingProduct: multiple product updates** | `app/Jobs/ProcessPendingProduct.php:150-159, 261` | `$product->update()` runs on line 150 (main data), then again on line 261 (image_path). Two UPDATE queries when one would suffice. | Collect all changes and call `update()` once at the end. |

## Low Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **Job timeout could be tight** | Both jobs: `$timeout = 60` | Gemini API call (30s timeout) + image download (15s timeout) + WebP conversion leaves only 15s margin. | Increase to `$timeout = 90` or split image into a chained job. |
| **No unique job ID to prevent duplicates** | `ProcessPendingProduct` | If the same product is imported twice before the first job processes, two identical jobs run. The job handles this gracefully (logs a warning), but wastes an API call. | Implement `ShouldBeUnique` with `uniqueId()` returning `"process-product-{$this->productId}"`. |

## Queue Configuration Review

| Setting | Current | Recommendation |
|---------|---------|----------------|
| Driver | Database | Fine for current scale. Switch to Redis at 1000+ jobs/hour. |
| Workers | 2 (Supervisor) | Adequate for current volume. |
| Retry backoff | None (immediate) | Add `$backoff = [10, 60, 300]` |
| Max tries | 3 | Fine. |
| Timeout | 60s | Consider 90s for ProcessPendingProduct. |
| Unique jobs | Not implemented | Add `ShouldBeUnique` to ProcessPendingProduct. |
