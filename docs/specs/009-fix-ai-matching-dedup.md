# Spec 009: Fix AI Product Matching & Deduplication

**Status:** Draft
**Priority:** Critical
**Created:** 2026-03-29

## Problem Statement

The AI matching service (`AiService::matchProduct()`) has never successfully merged two offers into one product. The database has **55 duplicate product groups** (62 wasted rows) where identical products from different stores or SERP pages exist as separate records instead of being consolidated.

**Evidence:**
- 1,562 products with exactly 1,562 offers — zero products have multi-store offers
- Zero `ai_matching_decisions` created after the migration seed (all 1,372 records are self-referential, bulk-seeded on 2026-03-27)
- Exact-name duplicates confirmed: Sennheiser E 835 (4 copies), DJI Mic (4 copies), ECM Synchronika II from Clive Coffee + Whole Latte Love, etc.

## Root Cause Analysis

### Bug 1: Permanent Negative Cache (Cache Poisoning)

**Location:** `AiService::matchProduct()` lines 247-264

When `ProcessPendingProduct` calls `matchProduct()` at line 102, the current product still has `status = 'pending_ai'`. The heuristic query at line 248-253 filters `->whereNull('status')`, making the product invisible to itself.

For the **first product of a brand**, the query returns zero results → a `is_match=false` decision is permanently cached. When a **second product** with the same raw title arrives later, the cache returns the stale `false` — the AI is never consulted.

**The cache has no TTL or invalidation mechanism.** A negative decision written when a brand had zero products remains forever, even after the brand has hundreds.

### Bug 2: Pre-Migration Duplicates Never Deduped

The `MigrateToOffers` command seeded `ai_matching_decisions` as self-referential entries: each product's normalized name → itself, always `is_match=true`. It did NOT cross-check products with identical names in the same brand+category. The 48+ duplicate groups from before March 27 were baked in.

### Bug 3: OfferIngestionService Brand Gap

`OfferIngestionService::processIncomingOffer()` (line 76) skips matching entirely when `brand` is empty. Non-Amazon scrapers may not always provide brand. The safety net (`ProcessPendingProduct` calling `matchProduct` after AI evaluation) exists but is defeated by Bug 1.

## Proposed Solution

### Fix 1: Invalidate Stale Negative Decisions

After `ProcessPendingProduct` finalizes a product (line 137-146, sets `status=null`), invalidate all negative decisions for that brand+tenant so future imports re-evaluate.

```php
// After product update (line 146) in ProcessPendingProduct:
AiMatchingDecision::withoutGlobalScopes()
    ->where('tenant_id', $product->tenant_id)
    ->where('is_match', false)
    ->whereNull('existing_product_id')
    ->whereIn('scraped_raw_name', function ($q) use ($brand, $product) {
        // Only invalidate decisions whose raw name could plausibly match this brand
        // Simple approach: delete ALL negative decisions for titles that share
        // the first word (brand name) with the newly finalized product
    })
    ->delete();
```

**Simpler alternative:** Delete ALL negative decisions for the tenant when a new product is finalized. Negative decisions are cheap to regenerate (the heuristic catches most cases without an AI call), and this guarantees no stale cache.

```php
// After line 146 in ProcessPendingProduct:
AiMatchingDecision::withoutGlobalScopes()
    ->where('tenant_id', $product->tenant_id)
    ->where('is_match', false)
    ->delete();
```

### Fix 2: Exclude Self from matchProduct Query

In `AiService::matchProduct()`, accept an optional `$excludeProductId` parameter so `ProcessPendingProduct` can exclude the product being processed. This prevents the "first of brand" false negative.

```php
public function matchProduct(
    string $scrapedRawTitle,
    string $brand,
    ?string $tenantId = null,
    ?int $excludeProductId = null,  // NEW
): ?int
```

Update the heuristic query (line 248):
```php
$existingProducts = Product::withoutGlobalScopes()
    ->where('tenant_id', $tenantId)
    ->whereHas('brand', fn($q) => $q->where('name', $brand))
    ->whereNull('status')
    ->where('is_ignored', false)
    ->when($excludeProductId, fn($q) => $q->where('id', '!=', $excludeProductId))
    ->get(['id', 'name']);
```

Update `ProcessPendingProduct` line 102:
```php
$matchedProductId = $aiService->matchProduct(
    $originalName, $parsed['brand'], $product->tenant_id, $product->id
);
```

### Fix 3: Backfill Dedup Command

Create `pw2d:merge-duplicates` artisan command that:

1. Finds all duplicate groups: products with identical `(name, brand_id, category_id)` where `is_ignored=0` and `status IS NULL`.
2. For each group, picks the **oldest product** as canonical (lowest ID — most likely to have been scored first).
3. Merges all offers from duplicate products into the canonical product. Respects the `unique(product_id, store_id)` constraint — if canonical already has an offer from the same store, keeps the one with the lower price.
4. Deletes duplicate product stubs (cascade handles `product_feature_values`).
5. Updates `ai_matching_decisions` to point to the canonical product.
6. Supports `--dry-run` flag.
7. Logs every merge for auditability.

**Expected impact:** Merge 62 duplicate products into their canonical records, creating multi-store offers for the first time.

### Fix 4: Add Heuristic Pre-Check in OfferIngestionService

Before dispatching to the queue, add a simple DB check for exact name matches (case-insensitive) in the same category. This catches obvious duplicates without an AI call, even when brand is empty:

```php
// In OfferIngestionService, after the AI matchProduct attempt (line 89):
if (!$matchedProductId) {
    // Heuristic: exact normalized title match in same category
    $heuristic = Product::withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->where('category_id', $data['category_id'])
        ->whereNull('status')
        ->where('is_ignored', false)
        ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['raw_title'])])
        ->first();

    if ($heuristic) {
        $matchedProductId = $heuristic->id;
    }
}
```

## Files to Create/Modify

| File | Action | Description |
|------|--------|-------------|
| `app/Services/AiService.php` | Modify | Add `$excludeProductId` param to `matchProduct()` |
| `app/Jobs/ProcessPendingProduct.php` | Modify | Pass `$product->id` to `matchProduct()`, invalidate stale negative decisions after finalization |
| `app/Services/OfferIngestionService.php` | Modify | Add heuristic exact-name pre-check |
| `app/Console/Commands/MergeDuplicateProducts.php` | Create | One-time backfill command |
| `tests/Unit/AiMatchProductTest.php` | Modify | Add tests for cache invalidation, self-exclusion, heuristic pre-check |
| `tests/Feature/MergeDuplicatesTest.php` | Create | Test the backfill command |

## Migration

No schema changes required. The fix is purely in application logic + one-time data cleanup.

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Backfill merges wrong products | `--dry-run` flag, only merge exact name+brand+category matches |
| Cache invalidation too aggressive | Only deletes `is_match=false` decisions — positive matches (the majority) are untouched |
| Heuristic false positive on common names | Heuristic requires same category + same tenant + exact case-insensitive name — very low false positive risk |
| Offer unique constraint violation during merge | Check for existing offer from same store before transferring; keep lower-priced offer |

## Test Plan

- [ ] Unit test: `matchProduct()` with `$excludeProductId` correctly excludes self
- [ ] Unit test: negative decisions are deleted when a new product is finalized
- [ ] Unit test: stale cache returns null, then after invalidation, correctly matches
- [ ] Feature test: `MergeDuplicateProducts` merges exact duplicates and preserves offers
- [ ] Feature test: `MergeDuplicateProducts --dry-run` reports but doesn't modify
- [ ] Feature test: `OfferIngestionService` heuristic catches exact-name duplicate without brand
- [ ] Integration test: full pipeline — import product A from store 1, import A from store 2 → single product with 2 offers
