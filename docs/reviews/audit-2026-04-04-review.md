# Code Quality Review -- 2026-04-04

**Reviewer:** Code Reviewer Agent
**Status:** Needs changes

## Files Reviewed
- `app/Console/Commands/AiAssignCategories.php`
- `app/Services/AiService.php` (the `assignCategories()` method, lines 399-454)
- `app/Jobs/RescanProductFeatures.php` (dispatch target)

---

## Findings

### Critical

**C1: No tenant scoping -- command queries ALL tenants (lines 27-29, 39-46)**

Both `Product::whereNull('category_id')` (line 27) and `Category::doesntHave('children')` (line 39) rely on the `BelongsToTenant` global scope. However, `TenantScope::apply()` is a no-op when `tenancy()->initialized` is false (see `vendor/stancl/tenancy/src/Database/TenantScope.php:16`). Artisan commands do not initialize tenancy.

Result: the command fetches uncategorized products from ALL tenants, mixes them with leaf categories from ALL tenants, and then sends cross-tenant data to the AI. A product from tenant A could be assigned a category belonging to tenant B. The subsequent `update()` on line 79 would write that foreign `category_id`, corrupting the data.

Fix: Add a required `{--tenant= : Tenant ID to scope the operation}` option. Resolve the tenant via `Tenant::findOrFail()` and call `tenancy()->initialize($tenant)` before querying, or use `withoutGlobalScopes()` and add explicit `->where('tenant_id', $tenantId)` to every query. The former approach is simpler and ensures consistency with the `creating` hook in `BelongsToTenant`.

Note: The sibling command `AiSweepCategory` has the identical issue. It should be fixed at the same time.

---

**C2: No error handling around the AI call (line 65)**

`$aiService->assignCategories()` delegates to `GeminiService::generate()`, which throws `\Exception` on API errors, rate limits, and response truncation. If any chunk fails, the exception propagates uncaught and the command crashes mid-run, leaving some products assigned and others untouched with no indication of which chunk failed.

Fix: Wrap the AI call in a try/catch per chunk. Log the error, report which product IDs were in the failing chunk, and continue to the next chunk. Return `self::FAILURE` at the end if any chunk failed.

```php
try {
    $results = $aiService->assignCategories($chunk, $leafCategories);
} catch (\Exception $e) {
    $this->error("AI call failed for chunk: {$e->getMessage()}");
    return; // skip this chunk, continue to next
}
```

---

### High

**H1: `category_id` from AI response is not type-validated before dispatch (line 80)**

`RescanProductFeatures::dispatch($item['id'], $item['category_id'])` passes values decoded from JSON. The job constructor expects `int, int` (strict typed). If the AI returns `category_id` as a string or float (rare but possible with LLMs), PHP 8.3 strict mode will throw a `TypeError` at dispatch time. While `AiService::assignCategories()` validates that the ID is in `$validCategoryIds` (line 443), it does not cast the value to `int`.

Fix: Cast explicitly in the command or in the service:
```php
RescanProductFeatures::dispatch((int) $item['id'], (int) $item['category_id']);
```

---

**H2: Unbounded `get()` call on products (line 27-29)**

`Product::whereNull('category_id')->where('is_ignored', false)->get()` loads ALL uncategorized, non-ignored products into memory at once. Per project standards (`standards.md`): "chunk() or cursor() for large datasets -- never get() on unbounded sets." If hundreds of products are uncategorized, this unnecessarily loads them all upfront.

The downstream `.chunk(10)` on line 61 operates on the already-loaded Collection (in-memory chunking), NOT database-level chunking. This means all products are fetched in a single query.

Fix: Use `Product::whereNull('category_id')->where('is_ignored', false)->chunkById(10, function ($chunk) { ... })` for true database-level chunking. Alternatively, since the total count display on line 36 requires a count first, fetch the count separately and then chunk:
```php
$count = Product::whereNull('category_id')->where('is_ignored', false)->count();
$this->info("Found {$count} uncategorized product(s).");

Product::whereNull('category_id')
    ->where('is_ignored', false)
    ->select(['id', 'name'])
    ->chunkById(10, function ($chunk) use (...) {
        // AI call per chunk
    });
```

---

### Medium

**M1: Summary line has grammatical awkwardness (line 103-104)**

When `$isDryRun` is false, `$action` is an empty string, producing: `"5 product(s)  assigned to categories."` (double space). When `$isDryRun` is true, `$action` is `'would be'`, producing `"5 product(s) would be assigned"` which reads correctly.

Fix:
```php
$action = $isDryRun ? 'would be assigned' : 'assigned';
$this->info("{$prefix}{$assigned} product(s) {$action} to categories.");
```

---

**M2: Missing `--tenant` documentation / signature consistency**

The command does not follow the same option conventions as the existing maintenance commands documented in `docs/project_context.md` Section 7. It should be listed in that table once stabilized. (This is blocked by the C1 fix, which will add the `--tenant` option.)

---

### Low

**L1: The `$products->chunk(10)` size is reasonable but not documented**

The chunk size of 10 is appropriate for AI batch calls (balances token cost vs. API calls). A brief comment explaining the rationale would aid future maintainers: `// Chunk of 10 to stay within AI prompt token limits`.

---

**L2: Unused import potential**

The `use App\Models\Category` and `use App\Models\Product` imports are both used. The `use App\Jobs\RescanProductFeatures` import is used. No dead imports found. This is a positive note, not a finding.

---

## Summary

The command has **two critical issues** that must be fixed before merging:

1. **Cross-tenant data corruption risk** -- Without tenant initialization, the command mixes products and categories across all tenants. This is the highest-priority fix.
2. **No error handling on AI calls** -- An API failure mid-run causes an unhandled crash with partial state changes.

There are also two high-severity issues: the unbounded `get()` call violates project standards, and the AI-returned IDs are not type-cast before being passed to a strictly-typed job constructor.

Positive observations:
- The command properly delegates AI logic to `AiService` (no direct `GeminiService` or HTTP calls).
- `AiService::assignCategories()` validates both product IDs and category IDs against known-good sets, preventing hallucinated ID injection.
- The `--dry-run` flag is well-implemented with clear visual output.
- The `--ignore-unmatched` flag is a thoughtful feature for batch cleanup.
- `declare(strict_types=1)` is present.
- The code is clean, well-structured, and easy to follow.
