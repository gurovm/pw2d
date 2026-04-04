# Review: AI Pipeline (Full Stack)

**Date:** 2026-04-04
**Reviewer:** Code Reviewer Agent
**Status:** Needs changes

**Scope:** `AiService`, `GeminiService`, `ImageOptimizer`, `ProcessPendingProduct`, `RescanProductFeatures`, `AiSweepCategory`, `AiAssignCategories`, `MergeDuplicateProducts`, `RecalculatePriceTiers`, `RegenerateWebpImages`

---

## Critical Issues (must fix)

### C1: GeminiService does not handle thinking-model multi-part responses

**File:** `app/Services/GeminiService.php`, line 74
**Risk:** Silent data corruption / JSON parse failures

When `thinkingConfig.thinkingBudget > 0` is passed (used by `evaluateProduct` with budget 128 and `matchProduct` with budget 128), Gemini returns a multi-part response where `parts[0]` is the thinking content (`{"thought": true, "text": "..."}`) and `parts[1]` is the actual output. The current code unconditionally reads `parts[0]['text']`, which grabs the model's internal reasoning instead of the JSON answer.

```php
// Current (broken for thinking responses):
$raw = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

// Fix: iterate parts and take the last non-thought part:
$parts = $result['candidates'][0]['content']['parts'] ?? [];
$raw = '';
foreach ($parts as $part) {
    if (empty($part['thought'])) {
        $raw = $part['text'] ?? '';
    }
}
```

This bug may be latent if Gemini sometimes collapses thinking into a single part at very low budgets, but it is architecturally incorrect and will break unpredictably.

### C2: MergeDuplicateProducts has cross-tenant merge risk

**File:** `app/Console/Commands/MergeDuplicateProducts.php`, lines 25-32
**Risk:** Cross-tenant data corruption

The GROUP BY clause identifies duplicates by `(name, brand_id, category_id)` using `withoutGlobalScopes()` but does not include `tenant_id`. If two tenants happen to share a brand with the same name (brand IDs are auto-incremented, so collisions are unlikely but not impossible with shared brand names), products from different tenants could be merged together.

Additionally, the command has no `{tenant}` argument and no `tenancy()->initialize()` call, unlike the other AI commands which were fixed in C8 of the previous audit. It should either:
- (a) Require a `{tenant}` argument and initialize tenancy (consistent with `AiSweepCategory` and `AiAssignCategories`), or
- (b) Add `tenant_id` to the GROUP BY and filter duplicates within each tenant.

Option (a) is the safer, more consistent approach.

### C3: Aggressive negative AiMatchingDecision purge on every product

**File:** `app/Jobs/ProcessPendingProduct.php`, lines 172-176
**Risk:** Performance degradation, wasted AI API calls, potential rate limiting

After every successful product evaluation, the job deletes ALL negative matching decisions for the entire tenant:

```php
AiMatchingDecision::withoutGlobalScopes()
    ->where('tenant_id', $product->tenant_id)
    ->where('is_match', false)
    ->delete();
```

If 50 products are queued simultaneously, this runs 50 times. More critically, it defeats the purpose of the negative cache: titles that were correctly identified as "no match" are purged, forcing re-evaluation by AI on the next import cycle. This burns API tokens needlessly.

**Recommended fix:** Only invalidate negative decisions for the same brand as the newly processed product, since a new product only changes the matching landscape for its own brand:

```php
AiMatchingDecision::withoutGlobalScopes()
    ->where('tenant_id', $product->tenant_id)
    ->where('is_match', false)
    ->whereHas('product', fn($q) => $q->where('brand_id', $brand->id))
    ->delete();
```

Or even more surgical: delete only decisions whose `scraped_raw_name` contains the brand name.

---

## Suggestions (recommended improvements)

### S1: Extract duplicated feature-score parsing logic

**Files:** `ProcessPendingProduct.php` lines 178-191, `RescanProductFeatures.php` lines 85-98

The exact same block of code (parse AI feature response, handle array-or-scalar format, `updateOrCreate` on `featureValues`) is copy-pasted between the two jobs. Extract this into a shared method on `AiService` or a dedicated `FeatureScoreWriter` utility:

```php
// Proposed: AiService::saveFeatureScores(Product $product, Collection $features, array $parsedFeatures): void
```

This would also centralize the `$score > 0` guard, making it easier to adjust scoring policy in one place.

### S2: Extract duplicated price-note builder

**Files:** `ProcessPendingProduct.php` lines 61-68, `RescanProductFeatures.php` lines 65-72

The `$budgetMax` / `$midrangeMax` / `$priceNote` block is duplicated verbatim. Extract to a helper method on `Category` or a shared trait:

```php
// Category::priceNoteFor(?int $priceTier): string
```

### S3: RescanProductFeatures silently swallows terminal failures

**File:** `app/Jobs/RescanProductFeatures.php`, lines 111-113

When `$this->attempts() >= $this->tries`, the exception is caught and swallowed without updating the product's status. In `ProcessPendingProduct`, the equivalent situation sets `status = 'failed'`. The rescan job should similarly mark the product or log at a higher severity so failed rescans are visible in the admin panel.

### S4: Add `declare(strict_types=1)` to remaining files

Three of the ten reviewed files lack strict type declarations:
- `app/Jobs/ProcessPendingProduct.php`
- `app/Jobs/RescanProductFeatures.php`
- `app/Console/Commands/RecalculatePriceTiers.php`

The other seven files in this review scope already use strict types. PHP 8.3 best practice and project standards call for strict types everywhere.

### S5: Remove dead variable in ImageOptimizer::reoptimizeWebp

**File:** `app/Services/ImageOptimizer.php`, line 66

`$originalSize = filesize($webpPath)` is assigned but never read. Remove it, or use it to decide whether to skip re-encoding if the file is already below a threshold.

### S6: RecalculatePriceTiers and RegenerateWebpImages lack tenant awareness

**Files:** `app/Console/Commands/RecalculatePriceTiers.php`, `app/Console/Commands/RegenerateWebpImages.php`

Neither command accepts a `{tenant}` argument or initializes tenancy. Because stancl's `TenantScope` is a no-op when tenancy is not initialized, these commands process all tenants' data globally. This is probably intentional for maintenance tools, but it differs from the pattern established by `AiSweepCategory` and `AiAssignCategories` (which require explicit tenant context). Consider adding an optional `{--tenant=}` argument for consistency and safety, or add a comment documenting the intentional global scope.

### S7: formatBytes in RegenerateWebpImages lacks GB unit

**File:** `app/Console/Commands/RegenerateWebpImages.php`, line 145

The `$units` array is `['B', 'KB', 'MB']`. If `$bytes` >= 1 GB, the log index would be 3, causing an `Undefined array key` warning. Add `'GB'` to the array for safety.

### S8: RecalculatePriceTiers eager-loads all products with offers into memory

**File:** `app/Console/Commands/RecalculatePriceTiers.php`, line 20

`Category::with(['products.offers'])->get()` loads every product and every offer into memory at once. For a growing catalog, consider chunking products per category rather than eager-loading the entire graph. This is acceptable today but will degrade as the product count scales.

### S9: GeminiService should use typed exceptions

**File:** `app/Services/GeminiService.php`, lines 60-65, 71

The service throws generic `\Exception` for rate limits, API errors, and truncation. Consider defining named exceptions (`GeminiRateLimitException`, `GeminiTruncationException`) so callers can handle retryable errors differently from permanent failures. The job retry logic in `ProcessPendingProduct` would benefit from distinguishing rate limits (always retry) from other API errors (may not be worth retrying).

### S10: GeminiService test suite lacks a thinking-response test case

**File:** `tests/Unit/GeminiServiceTest.php`

All test cases mock a single-part response. Add a test case that simulates a multi-part response with a `thought` part to verify the fix from C1.

---

## Praise (what was done well)

1. **Clean two-layer AI architecture.** The `AiService` / `GeminiService` separation is excellent. All domain-specific prompt logic is centralized in `AiService`, and `GeminiService` is a pure transport layer. No controller or job calls the Gemini API directly, which is exactly what the project rules mandate.

2. **Robust SSRF protection.** The image download in `ProcessPendingProduct` uses a config-driven allowlist, auto-allows store domains, and checks known CDN suffixes. Content-type validation prevents non-image downloads.

3. **Smart AI matching pipeline.** The three-step dedup flow (cache, heuristic, AI call) in `matchProduct()` is well-designed. The heuristic short-circuit (no products for this brand = no match) avoids unnecessary AI calls and the `AiMatchingDecision` cache prevents redundant evaluations.

4. **Defensive error handling in jobs.** Both queue jobs have proper `$tries`, `$timeout`, and `$backoff` arrays. The `ProcessPendingProduct` job correctly marks products as `failed` after exhausting retries, and image download failures are isolated so they never abort the core AI processing.

5. **AiSweepCategory and AiAssignCategories are well-implemented.** Both use `Isolatable`, accept a tenant argument, support `--dry-run`, chunk their queries, handle per-chunk AI failures gracefully, and provide clear console output. The AI prompt validation (filtering returned IDs against the actual batch) prevents hallucinated IDs from corrupting data.

6. **ImageOptimizer is clean and minimal.** Static methods, clear error messages, proper process timeouts, and temp file cleanup. The WebP-to-WebP re-optimization path via dwebp decode is a pragmatic solution.

7. **MergeDuplicateProducts uses DB transactions.** The per-group merge is wrapped in `DB::transaction()`, preventing partial merges that could leave orphaned offers.

8. **Prompt engineering quality is high.** The AI prompts are well-structured with explicit stage separators, clear rules, worked examples, and strict output format specifications. The "when in doubt, score it" directive in the quality gate prevents over-aggressive filtering.
