# Performance Audit: AI Pipeline
**Date:** 2026-04-04
**Scope:** AiService, GeminiService, ProcessPendingProduct, RescanProductFeatures, AiSweepCategory, AiAssignCategories, RecalculatePriceTiers, SyncOfferPrices

## Summary
> 1. **ProcessPendingProduct deletes ALL negative matching decisions for the entire tenant on every successful product processing** -- a mass DELETE that grows linearly with import volume and causes write amplification.
> 2. **RecalculatePriceTiers eager-loads ALL products with ALL their offers into memory** via `Category::with(['products.offers'])` -- a full table scan with no pagination or chunking.
> 3. **GeminiService has zero transport-level retry logic for 429 rate limits** -- it throws an exception immediately, burning a queue attempt on a transient condition.

---

## Critical Issues

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| C1 | **Mass DELETE of all negative matching decisions per tenant** | `ProcessPendingProduct.php:173-176` | Every successfully processed product deletes ALL `is_match=false` rows for the entire tenant. With 500 products imported, this executes ~500 DELETE statements, each potentially removing hundreds of rows. Write amplification, lock contention on `ai_matching_decisions`, and loss of valid negative cache entries that will need to be re-evaluated via AI calls. | Only delete negative decisions for the same brand. Add `->whereHas('product', ...)` or store brand on the decision row. Alternatively, keep negative decisions and only invalidate when the product name actually matches an existing cached title pattern. |
| C2 | **RecalculatePriceTiers loads all products+offers into memory** | `RecalculatePriceTiers.php:20-28` | `Category::with(['products.offers'])->get()` loads every category, every product, and every offer into memory in a single query set. For a tenant with 2000 products and 3 offers each, this is ~8000 model hydrations at once. `best_price` accessor then iterates the offers collection per product. | Use `Category::with('features')->chunk()` and for each category, query products with a raw `MIN(scraped_price)` join instead of the `best_price` accessor. Or use `Product::whereHas('offers')->chunkById()` per category. |
| C3 | **No transport-level retry on Gemini 429s** | `GeminiService.php:58-65` | A 429 (rate limit) throws an exception immediately. The job's `$backoff` array handles retry, but it burns one of the 3 `$tries` on a transient condition. With 2 queue workers, concurrent AI jobs hit rate limits frequently during batch imports. | Use `Http::retry(3, fn($attempt) => $attempt * 2000)->timeout($timeout)` with a `when` clause that only retries on 429. This handles transient rate limits at the transport layer without consuming job-level retries. |

## High Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| H1 | **Store SSRF check queries all active stores on every image download** | `ProcessPendingProduct.php:237-239` | `Store::withoutGlobalScopes()->where('is_active', true)->get(['slug'])` executes a full table scan on every image download to check if the host is a known store domain. During batch imports of 50 products, this fires 50 identical queries. | Cache the allowed host list: `Cache::remember('allowed_image_hosts', 3600, fn() => Store::withoutGlobalScopes()->where('is_active', true)->pluck('slug')->toArray())`. |
| H2 | **SyncOfferPrices makes synchronous HTTP requests in a single-threaded loop** | `SyncOfferPrices.php:56-98` | Each offer is scraped sequentially with a 10s timeout. For 500 offers, worst case is 5000 seconds (83 minutes). No concurrency, no async. | Use Laravel's `Http::pool()` to batch 5-10 concurrent requests. Or dispatch individual `SyncSingleOfferPrice` jobs to leverage queue parallelism. |
| H3 | **No `ShouldBeUnique` on ProcessPendingProduct** | `ProcessPendingProduct.php:21` | If the same product is imported twice quickly (duplicate ASIN in batch, rapid extension clicks), two identical jobs can run concurrently. Both will call `evaluateProduct()` (expensive AI call), and the second will fail or produce a race condition on the merge logic. | Add `implements ShouldBeUnique` and `public function uniqueId(): string { return (string) $this->productId; }`. |
| H4 | **`evaluateProduct` uses `maxOutputTokens: 8192` with `thinkingBudget: 128`** | `AiService.php:85-88` | The 8192 output token budget is ~4x what the response actually needs (the JSON response is typically ~500 tokens). Gemini 2.5 Pro charges per output token; excess budget does not cost money if unused, but increases the risk of verbose/runaway responses and MAX_TOKENS failures on edge cases. | Reduce `maxOutputTokens` to 2048. The structured JSON response (name, brand, summary, ~8 features) fits comfortably in 1000-1500 tokens. |
| H5 | **`matchProduct` loads all processed products for a brand into memory** | `AiService.php:249-255` | `->get(['id', 'name'])` loads all processed products for the brand. For popular brands (e.g., Breville with 40+ products), this is fine. But the product list is then serialized to JSON in the prompt, which means prompt token cost scales linearly with brand catalog size. | This is acceptable for now (brands rarely exceed 50 products). Monitor for brands with 100+ products and consider limiting to the 50 most recent. |
| H6 | **SyncOfferPrices `chunk()` with `limit()` does not work correctly** | `SyncOfferPrices.php:54-56` | `$query->limit($limit)->chunk(50, ...)` -- Laravel's `chunk()` ignores `limit()` on the outer query because `chunk()` rewrites the query with its own `LIMIT` and `OFFSET`. The manual `$processed >= $limit` check inside the callback is the actual limit enforcement, but all records matching the base query are still iterated by `chunk()` until the callback returns `false`. | Use `->take($limit)->get()` for small limits, or use `chunkById()` with the manual break, which is the current approach. Alternatively, use `->cursor()->take($limit)` to stream exactly `$limit` rows. The current code works correctly but the `.limit()` is misleading dead code -- remove it for clarity. |

## Medium Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| M1 | **`RescanProductFeatures` eager-loads `offers` but only uses `best_price`** | `RescanProductFeatures.php:42` | `Product::with('offers')->find()` loads all offers just to compute `best_price` (which calls `$this->offers->min('scraped_price')`). For a product with 5 stores, this is negligible. But it could be a single `MIN()` subquery. | Low impact per job. Consider `Product::withMin('offers', 'scraped_price')->find()` if Laravel 11 supports it, otherwise leave as-is. |
| M2 | **AiSweepCategory does not skip already-rejected products** | `AiSweepCategory.php:60-64` | The query fetches all non-ignored, processed products but does not exclude products that already have an `AiCategoryRejection` for this category. If re-run, it will re-send already-rejected products to the AI, wasting tokens. | Add `->whereDoesntHave('categoryRejections', fn($q) => $q->where('category_id', $category->id))` to the query. |
| M3 | **`evaluateProduct` prompt is ~1200 tokens of static instructions** | `AiService.php:37-83` | The prompt template is rebuilt as a string concatenation on every call. Not a performance issue per se, but the prompt text is identical across calls -- only the product-specific variables change. | Extract the static prompt template to a Blade view or a cached string. Minor optimization but improves readability. |
| M4 | **`best_price` and `best_offer` accessors iterate offers collection twice** | `Product.php:97-121` | Code that accesses both `$product->best_price` and `$product->best_offer` iterates the offers collection twice. Each is a separate `Attribute::make()` with no caching between them. | Consider combining into a single computed that returns both, or use `once()` / memoization. Low impact unless called in tight loops. |
| M5 | **SyncOfferPrices price comparison uses strict `===` on decimal cast values** | `SyncOfferPrices.php:67` | `$result['price'] === $offer->scraped_price` compares a float from regex parsing against a string from the `decimal:2` cast. This may cause false "changed" results, triggering unnecessary UPDATE queries and price tier recalculations. | Cast both sides: `(float) $result['price'] === (float) $offer->scraped_price` or use `bccomp()`. |
| M6 | **Image download timeout of 15s inside a job with 60s total timeout** | `ProcessPendingProduct.php:248` | The HTTP timeout for image download is 15s, and the job timeout is 60s. With the AI call taking ~10-20s and offer merge logic, a slow image download could push the job past its 60s timeout, killing the entire job after the AI work is already done. | Increase job timeout to 120s (AI call + image download + DB writes), or make image download a separate non-fatal operation with a shorter timeout (5-10s). The current try/catch is good but the job-level timeout could still kill it. |

## Caching Recommendations

| Data | Current TTL | Recommended TTL | Expected Gain |
|------|-------------|-----------------|---------------|
| Allowed image hosts (Store slugs for SSRF check) | None (queried every time) | 3600s | Eliminates ~N queries per batch import (N = number of products with images) |
| Leaf categories for `AiAssignCategories` | None (queried per command run) | N/A (command runs infrequently) | Negligible -- command is admin-only |
| `AiMatchingDecision` cache lookups | DB query per call | Already using DB as cache -- adequate | The DB index on `(tenant_id, scraped_raw_name)` makes lookups fast. No change needed. |
| Brand product list for `matchProduct` | None (queried per call) | 60s per `(tenant_id, brand)` pair | Saves ~1 query per product in a batch import when multiple products share a brand. Low priority. |

## Index Recommendations

```sql
-- 1. product_offers needs an index on product_id for the frequent
--    JOIN/subquery from Product->offers (used by best_price, best_offer,
--    RecalculatePriceTiers, SyncOfferPrices).
--    The FK constraint may have created an implicit index on MySQL,
--    but verify with SHOW INDEX FROM product_offers.
--    If missing:
ALTER TABLE product_offers ADD INDEX idx_product_offers_product_id (product_id);

-- 2. ai_matching_decisions: the mass DELETE in ProcessPendingProduct
--    filters on (tenant_id, is_match). Current index is (tenant_id, scraped_raw_name).
--    If the mass DELETE is kept (see C1), add:
ALTER TABLE ai_matching_decisions ADD INDEX idx_ai_matching_tenant_match (tenant_id, is_match);

-- 3. products: AiSweepCategory and AiAssignCategories both filter on
--    (category_id, is_ignored, status). The existing composite index
--    idx_products_tenant_category_ignored covers (tenant_id, category_id, is_ignored)
--    but not status. For the sweep query pattern, extend:
ALTER TABLE products ADD INDEX idx_products_category_active (category_id, is_ignored, status);

-- 4. product_offers: SyncOfferPrices filters with whereHas('product', ...) 
--    which needs product_id + the product's is_ignored/status.
--    The FK index on product_id is sufficient for the JOIN.
--    No additional index needed.
```

## API Cost Optimization

| Method | Current Token Budget | Actual Usage (est.) | Recommended | Annual Savings (est.) |
|--------|---------------------|--------------------|--------------|-----------------------|
| `evaluateProduct` | `maxOutputTokens: 8192` | ~800-1500 tokens | 2048 | Negligible (Gemini does not charge for unused budget) but reduces MAX_TOKENS risk |
| `rescanFeatures` | `maxOutputTokens: 1500` | ~400-800 tokens | 1500 (fine) | -- |
| `matchProduct` | `maxOutputTokens: 1024` | ~50-100 tokens | 256 | Negligible |
| `sweepCategoryPollution` | `maxOutputTokens: 4096`, chunks of 25 | ~200-1000 tokens | 2048 | Negligible |
| `assignCategories` | `maxOutputTokens: 4096`, chunks of 10 | ~200-500 tokens | 2048 | Negligible |

The real API cost savings come from:
1. **Fixing C1** (mass DELETE of negative matching decisions) -- each deleted decision must be re-evaluated by AI on the next import, costing ~0.002-0.005 USD per re-evaluation.
2. **Fixing M2** (AiSweepCategory not skipping already-rejected products) -- re-sweeping sends the same products to AI again.

## Job Configuration Review

| Job | tries | timeout | backoff | Assessment |
|-----|-------|---------|---------|------------|
| `ProcessPendingProduct` | 3 | 60s | [10, 60, 300] | Timeout too tight -- AI call (10-20s) + matching (5-15s) + image download (up to 15s) + DB writes can exceed 60s. Recommend 120s. |
| `RescanProductFeatures` | 3 | 60s | [10, 60, 300] | Adequate -- single AI call + DB writes, no image download. |

## Concurrency & Scaling Concerns

With 2 queue workers in production:
- A batch import of 50 products dispatches 50 `ProcessPendingProduct` jobs.
- Each job makes 1-2 Gemini API calls (evaluate + match).
- Gemini 2.5 Pro has a default rate limit of ~15 RPM for the free tier, ~60 RPM for paid.
- Two workers processing concurrently will hit rate limits within the first minute.
- The `$backoff = [10, 60, 300]` handles this but wastes job attempts on 429s (see C3).
- With the transport-level retry fix (C3), a single 429 would be retried after 2s at the HTTP layer instead of burning a job attempt and waiting 10s+.

## Actionable Fix Priority

1. **C3** -- Add HTTP-level retry for 429s in GeminiService (15 min, high ROI)
2. **C1** -- Scope the negative matching decision delete to the brand (30 min, prevents AI cost waste)
3. **H1** -- Cache allowed image hosts (5 min, eliminates repeated queries)
4. **H3** -- Add ShouldBeUnique to ProcessPendingProduct (5 min, prevents duplicate AI calls)
5. **C2** -- Refactor RecalculatePriceTiers to use chunking (30 min, prevents OOM on large tenants)
6. **M2** -- Filter already-rejected products in AiSweepCategory (5 min, saves AI tokens on re-runs)
7. **H2** -- Parallelize SyncOfferPrices with Http::pool() (1 hr, 10-20x speedup)
8. **M6** -- Increase ProcessPendingProduct timeout to 120s (2 min, prevents job kills after AI work completes)
