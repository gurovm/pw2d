# Spec 010: Audit Fixes for AI Console Commands

**Date:** 2026-04-04
**Source:** Combined audit reports (review, security, performance) on `AiAssignCategories.php`
**Scope:** `AiAssignCategories`, `AiSweepCategory`, `AiService::assignCategories()`, new migration

---

## Problem

The `AiAssignCategories` command (and sibling `AiSweepCategory`) have critical tenant isolation failures, no error handling, unbounded queries, and several medium-severity issues identified across three parallel audits.

## Changes

### 1. Tenant Scoping (Critical -- both commands)

**Both commands** must require a `{tenant}` argument and initialize tenancy before any queries.

```php
// Add to signature:
{tenant : The tenant ID (e.g., "coffee-decide")}

// At top of handle(), before any queries:
$tenant = \App\Models\Tenant::find($this->argument('tenant'));
if (!$tenant) {
    $this->error("Tenant not found: {$this->argument('tenant')}");
    return self::FAILURE;
}
tenancy()->initialize($tenant);
```

### 2. Error Handling (Critical -- both commands)

Wrap per-chunk AI calls in try/catch. Log the error, report which chunk failed, continue to next chunk. Track failure count and return `self::FAILURE` if any chunk failed.

```php
try {
    $results = $aiService->assignCategories($chunk, $leafCategories);
} catch (\Exception $e) {
    $this->error("  AI call failed: {$e->getMessage()}");
    $failedChunks++;
    return; // skip chunk, continue to next
}
```

### 3. Database-Level Chunking (High -- AiAssignCategories)

Replace `->get()->chunk(10)` (in-memory) with true database-level chunking:

```php
$count = Product::whereNull('category_id')->where('is_ignored', false)->count();
// Display count...

Product::whereNull('category_id')
    ->where('is_ignored', false)
    ->select(['id', 'name'])
    ->chunkById(10, function ($chunk) use (...) {
        // AI call per chunk
    });
```

**AiSweepCategory** has the same pattern with `->get()->chunk(25)` -- apply the same fix.

### 4. Isolatable Interface (Medium -- both commands)

Prevent concurrent runs that waste AI tokens and risk conflicting assignments:

```php
use Illuminate\Contracts\Console\Isolatable;

class AiAssignCategories extends Command implements Isolatable
```

Same for `AiSweepCategory`.

### 5. Integer Casting (High -- AiAssignCategories)

Cast AI-returned IDs to `int` before database operations and job dispatch:

```php
Product::where('id', (int) $item['id'])->update(['category_id' => (int) $item['category_id']]);
RescanProductFeatures::dispatch((int) $item['id'], (int) $item['category_id']);
// Also for is_ignored update:
Product::where('id', (int) $item['id'])->update(['is_ignored' => true]);
```

### 6. Strict `in_array()` in AiService (Medium)

In `AiService::assignCategories()`, lines 440 and 443, add strict third parameter and cast category_id to int:

```php
// Line 440:
->filter(fn($item) => isset($item['id'], $item['reason']) && in_array((int) $item['id'], $validProductIds, true))

// Line 441-444:
->map(function ($item) use ($validCategoryIds) {
    $categoryId = isset($item['category_id']) ? (int) $item['category_id'] : null;
    if ($categoryId !== null && !in_array($categoryId, $validCategoryIds, true)) {
        $categoryId = null;
    }
    return [
        'id'          => (int) $item['id'],
        'category_id' => $categoryId,
        'reason'      => $item['reason'],
    ];
})
```

### 7. Composite Index Migration (High)

New migration to optimize the uncategorized products query:

```php
Schema::table('products', function (Blueprint $table) {
    $table->index(
        ['tenant_id', 'category_id', 'is_ignored'],
        'idx_products_tenant_category_ignored'
    );
});
```

### 8. Summary Line Grammar Fix (Low -- AiAssignCategories)

Fix double-space when `$isDryRun` is false:

```php
$verb = $isDryRun ? 'would be assigned' : 'assigned';
$this->info("{$prefix}{$assigned} product(s) {$verb} to categories.");
// Same pattern for ignored count
```

### 9. Category Name Lookup Map (Low -- AiAssignCategories)

Build once before the loop instead of `collect($leafCategories)->firstWhere()` per iteration:

```php
$categoryNameMap = collect($leafCategories)->pluck('name', 'id')->toArray();
// Inside loop:
$categoryName = $categoryNameMap[$item['category_id']] ?? '?';
```

### 10. Queue Staggering (High -- AiAssignCategories)

Stagger `RescanProductFeatures` dispatches to avoid flooding 2 workers + Gemini rate limits:

```php
RescanProductFeatures::dispatch((int) $item['id'], (int) $item['category_id'])
    ->delay(now()->addSeconds($assigned * 5));
```

## Files to Modify

| File | Changes |
|------|---------|
| `app/Console/Commands/AiAssignCategories.php` | Sections 1, 2, 3, 4, 5, 8, 9, 10 |
| `app/Console/Commands/AiSweepCategory.php` | Sections 1, 2, 3, 4 |
| `app/Services/AiService.php` | Section 6 (lines 440-453) |
| `database/migrations/2026_04_04_000001_add_uncategorized_index_to_products.php` | Section 7 (new file) |

## Not In Scope

- `RescanProductFeatures` tenant context propagation -- the job uses raw `find()` by ID which works without tenant scope. The cross-tenant risk is eliminated by fixing the commands (Section 1).
- Tests -- tracked separately as W12 in todo.md.
