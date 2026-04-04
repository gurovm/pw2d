# Spec 012: Fix AI Matching — Brand Normalization & Dedup Pipeline

## Problem

Products are heavily duplicated because the `matchProduct()` heuristic uses **exact brand name matching**, but brand names are non-deterministically normalized by the AI. "De'Longhi" and "DeLonghi" are treated as different brands, so the heuristic finds zero products and caches a permanent negative decision — the AI is never even asked.

### Root Cause Chain

1. **`evaluateProduct()`** tells Gemini to normalize brands ("strip non-ASCII", "capitalize correctly") but the rules are ambiguous — Gemini may return "De'Longhi" on one call and "DeLonghi" on the next.
2. **`Brand::firstOrCreate()`** in `ProcessPendingProduct` line 156 creates whichever spelling the AI returned first.
3. **`matchProduct()` heuristic** (line 251) uses `where('name', $brand)` — an exact, case-sensitive SQL match against `brands.name`.
4. When the second import comes in with a different spelling, the heuristic finds 0 products → caches `is_match=false` → AI is never called → duplicate created.
5. **Negative cache entries never expire** — the decision is permanent.

### Impact (Category 17 data)

- 11 duplicate pairs out of 46 products
- 7 miscategorized semi-automatics (separate issue — not fixed by this spec)

## Files to Modify

### 1. `app/Services/AiService.php` — Fix heuristic brand matching

**Change the heuristic query** (line 249-255) to use a normalized comparison function instead of exact match.

Add a private static helper to the class:

```php
/**
 * Normalize a brand name for comparison: lowercase, strip apostrophes/quotes/accents, collapse whitespace.
 */
private static function normalizeBrandForComparison(string $brand): string
{
    // Transliterate accents (RØDE → RODE), strip apostrophes/quotes, lowercase
    $brand = transliterator_transliterate('Any-Latin; Latin-ASCII', $brand);
    $brand = preg_replace("/[''`\"]/u", '', $brand);
    $brand = preg_replace('/\s+/', ' ', trim($brand));
    return mb_strtolower($brand);
}
```

Replace the heuristic query:

```php
// OLD:
->whereHas('brand', fn($q) => $q->where('name', $brand))

// NEW:
->whereHas('brand', fn($q) => $q->whereRaw(
    "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(name, '''', ''), '`', ''), '\"', ''), ''', '')) = ?",
    [static::normalizeBrandForComparison($brand)]
))
```

This ensures "De'Longhi", "DeLonghi", "De Longhi", "de'longhi" all match the same brand records.

### 2. `app/Services/AiService.php` — Expand AI product list to all matched brands

After the heuristic finds products via fuzzy brand match, the AI call sends `$existingProducts->pluck('name')`. This is already correct — the change above will now include products from all brand spelling variants (e.g., both "DeLonghi" and "De'Longhi" brand records).

No code change needed here — it flows naturally from fix #1.

### 3. `app/Jobs/ProcessPendingProduct.php` — Normalize brand before `Brand::firstOrCreate()`

**Before creating the brand** (line 156-159), apply the same normalization to ensure consistent storage. This prevents future split brands.

Add a `normalizeBrandName()` method (or reuse from AiService) that enforces:
- Strip leading/trailing whitespace
- Collapse multiple spaces
- Consistent apostrophe handling: if the brand name contains an apostrophe-like char, standardize to the ASCII apostrophe `'`
- Standard title case

```php
// Before Brand::firstOrCreate, normalize:
$normalizedBrand = self::normalizeBrandName($parsed['brand']);

// Check if a brand already exists with a fuzzy match
$existingBrand = Brand::withoutGlobalScopes()
    ->where('tenant_id', $product->tenant_id)
    ->get(['id', 'name'])
    ->first(fn ($b) => AiService::normalizeBrandForComparison($b->name) 
        === AiService::normalizeBrandForComparison($parsed['brand']));

$brand = $existingBrand ?? Brand::create([
    'name' => $parsed['brand'],
    'tenant_id' => $product->tenant_id,
]);
```

This way, if "De'Longhi" brand already exists and AI returns "DeLonghi", we reuse the existing brand record instead of creating a new one.

**Make `normalizeBrandForComparison()` public static** so ProcessPendingProduct can use it.

### 4. `app/Jobs/ProcessPendingProduct.php` — Fix negative cache invalidation

The current invalidation (lines 174-178) uses `LIKE '%{brand}%'` on `scraped_raw_name`. This is fragile — it relies on the brand name appearing in the raw title.

Replace with: delete all negative decisions in this tenant where the raw name fuzzy-matches the brand:

```php
// After processing, invalidate stale negative decisions for ANY spelling of this brand
$brandVariants = Brand::withoutGlobalScopes()
    ->where('tenant_id', $product->tenant_id)
    ->get(['name'])
    ->filter(fn ($b) => AiService::normalizeBrandForComparison($b->name)
        === AiService::normalizeBrandForComparison($parsed['brand']))
    ->pluck('name');

AiMatchingDecision::withoutGlobalScopes()
    ->where('tenant_id', $product->tenant_id)
    ->where('is_match', false)
    ->where(function ($q) use ($brandVariants) {
        foreach ($brandVariants as $variant) {
            $q->orWhere('scraped_raw_name', 'LIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $variant) . '%');
        }
    })
    ->delete();
```

### 5. `app/Services/AiService.php` — Tighten brand normalization prompt

In `evaluateProduct()` (line 60-66), add an explicit rule about apostrophes:

```
- Apostrophe handling: KEEP the common English spelling. "De'Longhi" stays "De'Longhi" (the apostrophe is standard). 
  "RØDE" → "Rode" (accent removed). Only strip non-ASCII characters that are stylistic, not part of the standard name.
- NEVER return different spellings for the same brand across calls. Use the Wikipedia article title as the canonical form.
```

This reduces (but doesn't eliminate) AI inconsistency. The fuzzy matching in the heuristic is the real safety net.

## Column order of changes

1. Make `normalizeBrandForComparison()` public static on AiService
2. Fix heuristic query in `matchProduct()` to use fuzzy brand comparison
3. Fix `ProcessPendingProduct` to reuse existing brand by fuzzy match
4. Fix negative cache invalidation to cover all brand spelling variants
5. Tighten the brand normalization prompt

## No migration needed

All changes are in application logic — no schema changes.

## Testing

The builder should write tests covering:
- `matchProduct()` finds products when brand has different apostrophe/case ("De'Longhi" vs "DeLonghi")
- `matchProduct()` finds products when brand has accent differences ("RØDE" vs "Rode")
- `ProcessPendingProduct` reuses existing Brand record despite different AI spelling
- Negative cache invalidation clears decisions for all brand variants
