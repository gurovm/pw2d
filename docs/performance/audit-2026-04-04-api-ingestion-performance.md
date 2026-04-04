# Performance Audit: API & Ingestion Pipeline
**Date:** 2026-04-04
**Auditor:** Performance Auditor Agent
**Scope:** BatchImportController, ProductImportController, OfferIngestionController, OfferIngestionService, InitializeTenancyIfApplicable, InitializeTenancyFromPayload, routes/api.php

## Summary
> **Top 3 things to fix (in priority order):**
> 1. **BatchImportController uses per-row INSERT + per-row UPDATE in a loop** -- 50-product import fires up to 150 individual queries. Bulk insert/upsert would cut this to 3-5 queries.
> 2. **`existingAsins` endpoint loads all offer URLs into PHP memory** then parses them -- no pagination, no ASIN column, forces `TEXT` column scan.
> 3. **`product_offers.url` is `TEXT` type with no index** -- the `OfferIngestionService` duplicate-URL check (`WHERE store_id = ? AND url = ?`) does a full table scan on every ingested offer.

---

## Critical Issues

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| C1 | **SUBSTRING_INDEX on TEXT column in WHERE clause** | `BatchImportController.php:43` | The ASIN-matching query uses `SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/dp/', -1), '?', 1)` inside `whereIn()`. This prevents index usage -- MySQL must compute the expression for every row and cannot use any index. With hundreds of offers, this is a full scan on every batch import. | Add a dedicated `asin` VARCHAR(20) column to `product_offers` (populated on Amazon offer creation). Then query `WHERE asin IN (...)` directly. Alternatively, store a normalized `url_hash` column. |
| C2 | **No transaction wrapping on batch import** | `BatchImportController.php:59-125` | The loop creates Products and ProductOffers one at a time without a transaction. If the request fails mid-batch (timeout, memory), you get partial imports with orphaned stubs and no way to roll back. The database also commits after every INSERT, adding significant overhead. | Wrap the entire loop in `DB::transaction(function () { ... })`. |
| C3 | **Per-row INSERT in a loop (no bulk insert)** | `BatchImportController.php:94-114` | For each new product, `Product::create()` + `ProductOffer::create()` fires 2 INSERTs. A 100-product batch fires up to 200 INSERTs. Each INSERT is a round trip to MySQL. | Collect new products/offers into arrays, then use `Product::insert($batch)` and `ProductOffer::insert($batch)`. Dispatch jobs after the insert. This requires generating IDs upfront (UUIDs or `insertGetId` in a batch). |
| C4 | **`product_offers.url` is TEXT with no index** | `OfferIngestionService.php:54-55`, `ProductImportController.php:81-83` | Both `OfferIngestionService::processIncomingOffer()` and `ProductImportController::import()` query `WHERE store_id = ? AND url = ?`. The `url` column is `TEXT` type, which MySQL cannot index without a prefix. Every offer ingestion does a full scan of the store's offers. | Change `url` to `VARCHAR(2000)` or add a `url_hash` CHAR(64) column (SHA-256 of the URL) with a composite index `(store_id, url_hash)`. Query by hash for exact matches. |

## High Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| H1 | **`existingAsins` loads all URLs into memory** | `ProductImportController.php:46-52` | `pluck('url')` fetches ALL offer URLs for the Amazon store (potentially thousands), then maps each through `parse_url` + `basename` in PHP. No pagination, no limit. This grows linearly with the number of Amazon offers across all categories. | If the `asin` column from C1 is added, this becomes `pluck('asin')`. Otherwise, use `DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(url, '/dp/', -1), '/', 1) as asin")` in a `select()` to extract server-side. Also add a `->where('tenant_id', tenant('id'))` scope -- currently the BelongsToTenant global scope handles this, but the query starts from `ProductOffer::where('store_id', ...)` which may bypass the scope if `withoutGlobalScopes()` is called anywhere in the chain. |
| H2 | **Per-row UPDATE in batch refresh loop** | `BatchImportController.php:70-83` | For existing products, the loop fires 2 UPDATEs per product (one for the offer, one for the product). A batch of 100 products where 80 exist fires 160 UPDATE queries. | Collect updates into arrays keyed by product ID and use `upsert()` or a single bulk UPDATE statement. At minimum, combine the two updates: `ProductOffer::upsert(...)` and `Product::upsert(...)`. |
| H3 | **`InitializeTenancyFromPayload` queries DB on every API request** | `InitializeTenancyFromPayload.php:31` | `Tenant::find($tenantId)` hits the database on every Chrome Extension API call. Unlike `DomainTenantResolver` (which has `$shouldCache = true`), this middleware has no caching. With rapid-fire extension requests (120/min for offer ingestion), this is 120 unnecessary queries/min. | Cache the tenant lookup: `Cache::remember("tenant:{$tenantId}", 3600, fn () => Tenant::find($tenantId))`. Invalidate on tenant update. |
| H4 | **Redundant `Category::with('features')->findOrFail()` in OfferIngestionService** | `OfferIngestionService.php:139` | When creating a new product, the service loads the category with features. But this data was already validated in the controller (`'category_id' => 'required|exists:categories,id'`). The features check (`$category->features->isNotEmpty()`) is the only reason to load the relation. This query fires on every "new product" ingestion. | Pass the `Category` model from the controller (already partially loaded during validation). Or cache categories per tenant: `Cache::remember("tenant:{$tenantId}:category:{$id}", 600, ...)`. Categories and their features rarely change. |
| H5 | **Heuristic fallback uses `whereRaw('LOWER(name) = ?')` without index** | `OfferIngestionService.php:98` | `LOWER(name)` prevents index usage on the `name` column. MySQL must scan all products in the tenant+category, compute `LOWER()` on each, then compare. | Store a `name_normalized` column (lowercase, trimmed), or use a case-insensitive collation on the `name` column (MySQL's default `utf8mb4_0900_ai_ci` is already case-insensitive). If the collation is CI, simply use `->where('name', $data['raw_title'])` without `LOWER()`. |
| H6 | **Product existence check after match uses separate query** | `OfferIngestionService.php:107` | After AI matching returns a `$matchedProductId`, the code does `Product::withoutGlobalScopes()->where('id', $matchedProductId)->exists()` -- a separate SELECT just to verify the row exists. Then on line 124, it does `Product::with('offers')->find($matchedProductId)` -- another query for the same product. | Combine into one: load the product once with `Product::withoutGlobalScopes()->with('offers')->find($matchedProductId)`. If null, the product does not exist. |
| H7 | **`Store::firstOrCreate` on every request** | `BatchImportController.php:33-36`, `OfferIngestionService.php:48-51` | Every batch import and every offer ingestion calls `Store::firstOrCreate()`. After the first request, this is always a SELECT (the store exists). The query is fast but unnecessary on high-throughput endpoints. | Cache stores per tenant: `Cache::remember("tenant:{$tenantId}:store:{$slug}", 3600, fn () => Store::firstOrCreate(...))`. Or resolve the store once in the middleware/controller and pass it down. |

## Medium Priority

| # | Issue | Location | Impact | Fix |
|---|-------|----------|--------|-----|
| M1 | **`ProcessPendingProduct` dispatched inside loop without batching** | `BatchImportController.php:116` | Each new product dispatches a job individually. For 50 new products, this is 50 INSERT INTO `jobs` queries. | Use `Bus::batch()` or collect job instances and dispatch after the loop with `Bus::chain()`. Or use `Queue::bulk()` to insert all jobs in one query. |
| M2 | **Rate limit on batch import is misleading** | `routes/api.php:28-32` | `throttle:30,1` applies to the batch-import route, but one request imports up to 100 products. The rate limit prevents only 30 requests/minute, meaning up to 3000 products/minute can be imported. Meanwhile, the single product-import has the same 30/min limit but imports only 1 product per request. | Consider applying the rate limit based on the total number of products in the payload, not per request. Or document the intent -- if 30 batch requests/min is acceptable, this is fine. |
| M3 | **`categories()` endpoint loads all categories without caching** | `ProductImportController.php:21-33` | Every call to `GET /api/categories` queries the DB. Categories rarely change. | Add `Cache::remember("tenant:{$tenantId}:categories_with_count", 600, ...)` with cache invalidation on category create/update/delete. |
| M4 | **`ProcessPendingProduct::downloadAndStoreImage` queries all active stores** | `ProcessPendingProduct.php:237-239` | The SSRF check does `Store::withoutGlobalScopes()->where('is_active', true)->get(['slug'])` on every image download. This queries the stores table for every single product processed. | Cache the store slugs: `Cache::remember('active_store_slugs', 3600, fn () => Store::withoutGlobalScopes()->where('is_active', true)->pluck('slug'))`. |
| M5 | **Validation rule `exists:categories,id` fires a DB query** | `OfferIngestionController.php:26` | On every offer ingestion (up to 120/min), Laravel's `exists` validation rule queries the `categories` table. Combined with the `Store::firstOrCreate` and the `Tenant::find` in middleware, that is 3 queries before any business logic runs. | Accept this as the cost of validation, or cache category IDs per tenant and use a custom `Rule::in($cachedIds)` validator. Low priority since the query is indexed. |

## Caching Recommendations

| Data | Current | Recommended | Expected Gain |
|------|---------|-------------|---------------|
| Tenant resolution (API middleware) | No caching (`Tenant::find()` on every request) | `Cache::remember("tenant:{$id}", 3600, ...)` | Eliminates 1 query per API request. At 120 req/min, saves ~7200 queries/hour. |
| Store resolution | `firstOrCreate()` on every request | `Cache::remember("tenant:{$tid}:store:{$slug}", 3600, ...)` | Eliminates 1 query per API request. |
| Categories list (Chrome Extension) | Fresh query every call | `Cache::remember("tenant:{$tid}:categories_with_count", 600, ...)` | Eliminates repeated category queries from extension polling. |
| Active store slugs (SSRF check) | `Store::get()` on every image download | `Cache::remember('active_store_slugs', 3600, ...)` | Eliminates 1 query per queued job. |
| Existing ASINs | Fresh query every call | `Cache::remember("tenant:{$tid}:existing_asins:{$catId}", 120, ...)` with invalidation on new offer creation | Avoids repeated full-table URL scans during rapid extension usage. Short TTL since new imports are frequent. |

## Index Recommendations

```sql
-- Migration: add_performance_indexes_to_ingestion_tables

-- C1/H1: Add ASIN column to product_offers for direct lookup (Amazon offers only)
-- This eliminates SUBSTRING_INDEX expressions and enables indexing
ALTER TABLE product_offers ADD COLUMN asin VARCHAR(20) NULL AFTER url;
CREATE INDEX idx_product_offers_store_asin ON product_offers (store_id, asin);

-- C4: Add url_hash for exact-match URL dedup (works with TEXT column)
ALTER TABLE product_offers ADD COLUMN url_hash CHAR(64) NULL AFTER url;
CREATE INDEX idx_product_offers_store_url_hash ON product_offers (store_id, url_hash);
-- Backfill: UPDATE product_offers SET url_hash = SHA2(url, 256);
-- Application code: set url_hash = hash('sha256', $url) on create/update.

-- H5: Composite index for heuristic name matching in OfferIngestionService
-- (only needed if collation is case-sensitive; check with SHOW CREATE TABLE products)
CREATE INDEX idx_products_tenant_category_status_name
    ON products (tenant_id, category_id, status, is_ignored, name(100));

-- M4: Already exists: stores (tenant_id, is_active) -- sufficient for the SSRF query.
-- No additional index needed, but caching the result is recommended.
```

## Architectural Observations

### 1. Batch Import Should Use Bulk Operations
The `BatchImportController` is the highest-throughput endpoint (up to 100 products per request), yet it processes each product sequentially with individual queries. A refactored version should:

1. Fetch all existing ASINs for the category in one query (using the proposed `asin` column).
2. Partition incoming products into "refresh" and "create" sets.
3. Use `Product::upsert()` for the refresh set (1 query).
4. Use `Product::insert()` + `ProductOffer::insert()` for the create set (2 queries).
5. Dispatch all jobs in one `Queue::bulk()` call.
6. Wrap the entire operation in `DB::transaction()`.

This reduces the query count from O(N) to O(1) -- from ~200 queries for a 100-product batch down to ~5.

### 2. The `url` Column Design Is a Persistent Problem
Three separate code paths query `product_offers.url` for exact matches:
- `OfferIngestionService` (offer dedup)
- `ProductImportController` (single import dedup)
- `BatchImportController` (ASIN extraction via SUBSTRING_INDEX)

All three suffer because `url` is `TEXT` (not indexable in MySQL without a prefix). The `url_hash` approach (CHAR(64) SHA-256 column + index) solves this cleanly for exact-match queries. The `asin` column solves the Amazon-specific ASIN extraction pattern.

### 3. API Middleware Stack Has 3 DB Queries Before Business Logic
For every Chrome Extension API request, the middleware stack fires:
1. `Tenant::find($tenantId)` -- InitializeTenancyFromPayload
2. Rate limit check -- `throttle` middleware (uses cache, not DB -- OK)
3. `exists:categories,id` -- validation (where applicable)
4. `Store::firstOrCreate()` -- controller/service

Of these, #1 and #4 are easily cacheable. At 120 requests/min, this saves ~240 queries/min.

### 4. Queue Dispatch Efficiency
The batch import dispatches `ProcessPendingProduct` for each new product inside the loop. Since the database queue driver is used (INSERT INTO `jobs`), each dispatch is a separate INSERT. `Queue::bulk()` can batch these into fewer queries.

---

## Estimated Impact Summary

| Fix | Effort | Query Reduction | Risk |
|-----|--------|-----------------|------|
| Wrap batch import in transaction | Low | Same count, but 2-5x faster due to single commit | Low |
| Cache tenant lookup in API middleware | Low | -1 query per API request | Low |
| Cache store resolution | Low | -1 query per API request | Low |
| Add `url_hash` column + index | Medium | O(N) scan -> O(1) lookup per offer dedup | Low |
| Add `asin` column to product_offers | Medium | Eliminates SUBSTRING_INDEX expression | Low |
| Bulk insert for new products in batch import | Medium | -100-200 queries per batch | Medium (must handle ID generation for job dispatch) |
| Bulk update for refreshed products | Medium | -80-160 queries per batch | Medium |
| Cache categories endpoint | Low | -1 query per extension poll | Low |
