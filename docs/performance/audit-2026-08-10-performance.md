# Performance Audit: Spec 029 Phase A + Spec 030 (uncommitted)
**Date:** 2026-08-10
**Scope:** Offer ingestion/rescan hot path, tier-recalc + freshness-audit jobs, observer containment query, rescan-list endpoint, Filament badge, new migrations.
**Scale context:** ~1,100 products / ~1,100 offers across 2 tenants, 11 landing pages, DB queue with 2 workers. Rescan walk (029 Phase C): ~1,070 sequential POSTs over ~90 min (~0.2 req/s).

## Summary
> 1. **Tier-recalc job storm:** every `ingest-offer` refresh POST dispatches `RecalculateCategoryPriceTiers` unconditionally — a 1,070-POST rescan walk queues ~1,070 jobs (~10K redundant queries) on the DB queue, interleaving FIFO with `ProcessPendingProduct` AI jobs. Add `ShouldBeUnique` + skip when price unchanged.
> 2. **Freshness-audit job duplication:** `AuditLandingPageFreshnessJob` has no uniqueness; during a walk, every flag event on the same page's picks queues another full audit, and each audit re-runs `SelectLandingPagePicks` over the entire category. Add `ShouldBeUnique` keyed on the page id.
> 3. **`SelectLandingPagePicks` omits `store_id` from its offers column list**, so the `best_offer` commission/priority tiebreak silently sees `store = null` — and can disagree with `AuditLandingPageFreshness` (which loads `offers.store`), risking flapping `selection_drift` verdicts on price ties.

No Critical findings — nothing falls over at today's scale. The two High items are waste/queue-contention problems that become visible exactly during the Phase C walk this code was built for.

---

## Per-POST cost of the rescan hot path (measured by reading `OfferIngestionService::processIncomingOffer`, refresh branch)

| # | Query | Notes |
|---|-------|-------|
| 1 | `SELECT stores` (firstOrCreate) | hit path, 1 query |
| 2 | `SELECT product_offers WHERE store_id = ? AND url = ?` | **`url` is TEXT, unindexed** — scans all of the store's offers (~650 for Amazon) per POST |
| 3 | `UPDATE product_offers` (price/title/image/stock) | |
| 4 | `SELECT products` (`Product::find`) | |
| 5 | `UPDATE products` (reviews_count/rating) | 0–1 |
| 6 | `UPDATE product_offers` again (ListingHealthService::apply) | second write to the same row per POST |
| 7 | `SELECT landing_pages LIKE` / condition-warn query | only on flag/ignore transitions — contained |
| 8 | `INSERT jobs` (AuditLandingPageFreshnessJob) | 0–n, flag path only |
| 9 | `INSERT jobs` (RecalculateCategoryPriceTiers) | **every refresh POST, unconditionally** |

≈ 7–9 queries/POST. Fine at 0.2 req/s and within the 120/min rate limit. The problem is what the dispatches fan out into (below).

## High Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **H1. Tier-recalc dispatched per POST, no dedupe.** The job docblock claims "at most once per ingestion request" — true, but for `ingest-offer` one request = one offer, so a 1,070-POST walk queues ~1,070 jobs. Each job = `Category::find` + `chunkById(200)` with offers eager-loaded (~9–10 SELECTs + up-to-N UPDATEs for a 652-product category) → ~6K redundant queries for the big category alone, ~10K over the walk. On the `database` queue these interleave FIFO with `ProcessPendingProduct` (AI, seconds each) across only 2 workers, plus jobs-table insert/lock/delete churn against the same MySQL the ingestion writes hit. Also dispatched even when `scraped_price` didn't change. | `app/Services/OfferIngestionService.php:112` (refresh), `:214` (matched), `app/Http/Controllers/Api/ProductImportController.php:173`; job: `app/Jobs/RecalculateCategoryPriceTiers.php` | ~1,070 jobs / walk where ~9–12 (one per category per window) suffice; queue contention delays AI processing of newly created products | (1) `implements ShouldQueue, ShouldBeUnique` with `uniqueId(): string { return $this->tenantId . ':' . $this->categoryId; }` and `public int $uniqueFor = 600;` (2) guard dispatch with `if ($existingOffer->wasChanged('scraped_price'))` (same in ProductImportController); (3) optionally `::dispatch(...)->delay(now()->addMinutes(2))` for trailing-edge coalescing so the recalc runs once after a category's walk segment, not during it. `BatchImportController.php:178` already debounces per request — keep as is. |
| **H2. Freshness-audit job has no uniqueness — duplicate full audits per page.** Each renewed flip (observer) or `high_price` flag (`ListingHealthService:78`) queues one job per matching page. Walking a landing-page category that flags, say, 5 picks queues 5 audits of the *same* page within minutes. Each audit is heavy relative to its answer: picks-products load (`with('offers.store')`, 3 queries) + `Category::find` + **a full `SelectLandingPagePicks` run** (features + all eligible category products with featureValues + offers = ~6 queries + in-memory scoring + O(n²) name-dup guard over up to 652 products) + page UPDATE ≈ 10 queries + O(category) CPU/memory, repeated redundantly. | `app/Jobs/AuditLandingPageFreshnessJob.php` (dispatchForProduct), `app/Actions/AuditLandingPageFreshness.php:113-131` (hasSelectionDrift → SelectLandingPagePicks) | Redundant category-wide loads/scoring on the queue during exactly the burst window; result of run N is overwritten identically by run N+1 | `implements ShouldBeUnique` with `uniqueId(): string { return (string) $this->landingPageId; }`, `public int $uniqueFor = 600;`. Freshness is not minute-sensitive — the nightly `pw2d:landing-pages:audit` catches any event suppressed at the tail of the window. (`WithoutOverlapping` middleware alone only prevents concurrency, not the queue-up — `ShouldBeUnique` is the right tool.) |

## Medium Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **M1. URL-dedup lookup unindexed on `url`.** `WHERE store_id = ? AND url = ?` uses only the `store_id` FK index, then scans that store's offers row-by-row comparing a TEXT column — the first query of *every* ingest POST. Fine at ~650 Amazon offers; linear growth on the hottest path. | `app/Services/OfferIngestionService.php:74-76`; schema: `2026_03_26_000001_create_product_offers_table.php` (url is `text`, never indexed) | ~650-row scan per POST today; grows with catalog | Prefix index (see Index Recommendations) or a `url_hash` CHAR(40) column set on write with a `(store_id, url_hash)` unique/index. Prefix index is the zero-code-change option. |
| **M2. `SelectLandingPagePicks` offers eager-load omits `store_id`.** Column list is `offers:id,product_id,scraped_price,image_url,raw_title,listing_flags` — so in `best_offer`, `$o->store` is always `null` (BelongsTo with null FK returns null *without* a query — no N+1, but the commission/priority tiebreak is dead, and `hasHighPriceFlag()` may check a different offer than `AuditLandingPageFreshness` does, since the audit loads `offers.store` fully). On a price tie, selection and audit can disagree → flapping `selection_drift`/`pick_ineligible`. | `app/Actions/SelectLandingPagePicks.php:46,225` vs `app/Actions/AuditLandingPageFreshness.php:41-44` | Wrong/inconsistent best-offer resolution; false staleness churn; perf-adjacent correctness | Add `store_id` to the column list and eager-load `offers.store` (2–5 store rows total — negligible cost). |
| **M3. Filament picks-Repeater runs `Product::find` per pick per render.** `itemLabel` does `Product::withoutGlobalScopes()->find($state['product_id'])` — 7 queries per edit-form render, re-executed on Livewire interactions with the form. | `app/Filament/Resources/LandingPageResource.php:136-142` | 7+ repeated point queries per admin edit-page render | Best: write `product_name` into the picks JSON at generation time (`GenerateLandingPage::$finalPicks`) and read it in `itemLabel` — zero queries, and pick labels survive product deletion. Alternative: preload names once via a static/`once()` `whereIn` map. |

## Low Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| L1. Offer written twice per POST (caller update + `ListingHealthService::apply` update). | `OfferIngestionService.php:82-87` + `ListingHealthService.php:52-90` | 1 extra UPDATE per POST | Merge health fields into the caller's update payload, or accept (clean separation has value). |
| L2. `rescan-list` orders by `COALESCE(health_checked_at, updated_at)` — filesort, and an index on `health_checked_at` **cannot** help through COALESCE. No pagination, but bounded by category size (≤652 today). No eager-load explosion (no relations touched — verified). | `OfferIngestionController.php:54-67` | Filesort over ~650 rows ≈ negligible | Do nothing now. If a category exceeds ~5–10K offers, add `->limit()` + cursor, or backfill `health_checked_at = updated_at` once and order by the plain (then indexable) column. |
| L3. `condition` standalone index is written on every offer save but no query filters on `condition` anywhere yet (verified by grep — all reads go through loaded models). | migration `2026_08_10_000001` | Dead index weight (tiny) | Keep — Spec 029 Phase C's "review the flagged-condition list in Filament" will want it. Just noting it's speculative today. |
| L4. Observer containment query — **confirmed contained.** `wasChanged()` is empty after inserts (Laravel's `performInsert` never syncs changes), so batch imports creating hundreds of products never fire the landing-page LIKE scan; it fires only on genuine `is_ignored`→true flips, `category_id` changes, and deletes. The LIKE is a full scan of `landing_pages` (11 rows). | `app/Observers/ProductObserver.php`, `AuditLandingPageFreshnessJob::dispatchForProduct` | ~0 today | Revisit only if landing_pages approaches thousands of rows — then a `landing_page_product` pivot (page_id, product_id, indexed) replaces JSON LIKE containment. |
| L5. Nightly audit total cost: 11 pages × (~10 queries + 1 category-wide eligible-product load each) ≈ 130–150 queries + 11 in-memory scoring passes at 03:30. | `AuditLandingPagesCommand` | Fine; single-digit seconds | Nothing now. If page count × category size grows 10x, reuse one `SelectLandingPagePicks` result per category across pages (currently 1:1 anyway). |
| L6. `BatchImportController` existing-products lookup wraps `url` in `SUBSTRING_INDEX` inside `whereIn` — non-sargable, scans the store's offers. Once per request (not per product) — fine. | `BatchImportController.php:46-50` | ~650-row scan per batch request | Would be fixed for free by the M1 `url_hash`/ASIN-column approach if ever revisited. |
| L7. Nav badge: cached 120s per tenant, count over 11 rows — fine. `tenant('id')` is null on the central panel → shared `landing-pages-stale-badge:` key across central contexts; harmless but consider `?? 'central'`. | `LandingPageResource.php:49-71` | ~0 | Optional key suffix fix. |

## Caching Recommendations

| Data | Current | Recommended TTL | Expected Gain |
|------|---------|-----------------|---------------|
| Stale-published badge count | `Cache::remember`, 120s, tenant-keyed | Keep 120s | Already right-sized |
| Tier-recalc suppression | none (every POST dispatches) | `ShouldBeUnique` `uniqueFor: 600` (effectively a 10-min debounce) | ~1,070 → ~9–12 jobs per rescan walk |
| Freshness-audit suppression | none | `ShouldBeUnique` `uniqueFor: 600` per landing page | 1 audit per page per burst instead of 1 per flagged pick |
| Pick product names in Filament repeater | `find()` per item per render | Persist `product_name` in picks JSON (∞ — written at generation) | 7+ queries per admin form render → 0 |

## Index Recommendations

```sql
-- M1: URL-dedup lookup (hottest ingest query). Prefix covers the discriminating part
-- of Amazon /dp/ and store-product URLs. Laravel migration:
--   $table->index(['store_id', DB::raw('url(120)')]);  -- or raw statement:
ALTER TABLE product_offers ADD INDEX product_offers_store_url_prefix (store_id, url(120));

-- Explicitly NOT recommended:
--  * health_checked_at index — the rescan-list ORDER BY wraps it in COALESCE(), which
--    defeats any index; the sort is over <1K rows per category.
--  * Any index for landing_pages.stale_reasons / picks JSON or est_price_snapshot —
--    est_price_snapshot is never queried at the DB level (read only from decoded JSON),
--    picks containment is a LIKE over 11 rows, and the badge count query touches 11 rows.
--    No generated columns warranted at this scale.
--  * products — existing idx_products_category_id / idx_products_tenant_category_ignored
--    already serve the rescan-list whereHas and pick-eligibility filters.
```

## Verdict on the parent's priority questions

1. **Per-POST cost:** 7–9 queries; acceptable. **Tier-recalc fires per POST** on the refresh path (`ingest-offer` = one offer per request, so "once per request" ≠ debounced). A 1,070-POST walk queues ~1,070 `RecalculateCategoryPriceTiers` jobs; `AuditLandingPageFreshnessJob` count = (# flag events on pick products) × (pages containing them), duplicated per event. Both need `ShouldBeUnique` (H1/H2).
2. **Observer containment:** full scan of 11 rows — fine, and it does **not** fire on creates (`wasChanged()` empty after insert) or on irrelevant updates. Contained by design.
3. **Audit action:** no N+1 (`with('offers.store')` covers `best_offer`/`estimated_price`); the real cost is `hasSelectionDrift` re-running `SelectLandingPagePicks` (full category load + scoring) per audit — the reason H2's dedupe matters. Nightly run ≈ 130–150 queries total across 11 pages: fine.
4. **rescan-list:** bounded per category, no eager-load explosion, filesort on COALESCE is negligible and un-indexable — no change needed now; paginate if categories grow past ~5–10K offers.
5. **Filament badge:** cached 120s, 11-row count — fine. The repeater `itemLabel` per-pick `find()` is the actual admin-page cost (M3).
6. **Indexes:** add `(store_id, url(120))` prefix index (M1); keep the speculative `condition` index; skip `health_checked_at`; nothing in JSON (`listing_flags`, `est_price_snapshot`, `stale_reasons`, `picks`) is DB-queried in a way that needs generated columns at this scale.
