# Security Audit -- 2026-04-04

## Files Audited
- `app/Console/Commands/AiAssignCategories.php` (uncommitted changes)
- `app/Services/AiService.php` (supporting -- `assignCategories()` method)
- `app/Jobs/RescanProductFeatures.php` (dispatched by the command)
- `app/Models/Product.php`, `app/Models/Category.php` (data models)
- `vendor/stancl/tenancy/src/Database/TenantScope.php` (global scope behavior)

## Findings

### Critical

#### C1. Cross-Tenant Data Mixing -- No Tenant Scoping in CLI Context

**Severity:** Critical
**Location:** `app/Console/Commands/AiAssignCategories.php`, lines 27-29 and 39-46

**Description:**
The `BelongsToTenant` trait adds a `TenantScope` global scope to all models. However, `TenantScope::apply()` (vendor line 16) checks `tenancy()->initialized` and **silently does nothing** when tenancy is not initialized. Since Artisan commands run outside HTTP middleware, `tenancy()->initialized` is always `false`, meaning the global scope is never applied.

This causes two dangerous behaviors:

1. **Line 27-29:** `Product::whereNull('category_id')` returns uncategorized products from **all tenants**, not just one.
2. **Line 39-46:** `Category::doesntHave('children')` returns leaf categories from **all tenants**.

**Attack Scenario:**
When the command runs, it sends a mixed bag of products and categories from different tenants to the AI. The AI can (and likely will) assign a product from Tenant A (e.g., `coffee2decide.com`) to a category belonging to Tenant B (e.g., `best-mics.com`). The validation in `AiService::assignCategories()` at line 443 checks `$validCategoryIds`, but those IDs were populated from the cross-tenant query, so the check passes for any category from any tenant.

Result: Products end up assigned to categories from a different tenant, breaking site data integrity and potentially exposing products on the wrong niche site.

**Fix:**
Add a required `--tenant` argument and initialize tenancy before querying. This ensures all `BelongsToTenant` scoped queries are properly filtered.

```php
// In the command signature (line 15-16):
protected $signature = 'pw2d:ai-assign-categories
                        {tenant : The tenant ID (e.g., "coffee-decide")}
                        {--dry-run : Preview assignments without making changes}
                        {--ignore-unmatched : Mark products that don\'t fit any category as ignored}';

// At the top of handle() (line 22), before any queries:
public function handle(AiService $aiService): int
{
    $tenant = \App\Models\Tenant::find($this->argument('tenant'));
    if (!$tenant) {
        $this->error("Tenant not found: {$this->argument('tenant')}");
        return self::FAILURE;
    }
    tenancy()->initialize($tenant);

    // ... rest of command
}
```

---

### High

#### H1. Dispatched Job Runs Without Tenant Context -- Potential Cross-Tenant Feature Scoring

**Severity:** High
**Location:** `app/Console/Commands/AiAssignCategories.php`, line 80

**Description:**
When the command dispatches `RescanProductFeatures::dispatch($item['id'], $item['category_id'])` at line 80, the job is queued. `RescanProductFeatures` (line 42-43) uses `Product::find()` and `Category::find()` which rely on `BelongsToTenant` scoping. However, queue workers may or may not have tenancy initialized depending on the worker configuration.

If the command is fixed per C1 (tenancy is initialized), Laravel's `SerializesModels` trait on the job does not automatically carry over the tenant context. The job may execute in a context where tenancy is uninitialized, causing `Product::find()` and `Category::find()` to silently return `null` (the TenantScope would filter them out if a different tenant is initialized, or return unscoped results if no tenant is initialized).

In practice, `RescanProductFeatures` uses `->find()` by raw integer ID, which will find the record regardless of TenantScope (when no tenant is initialized, the scope is a no-op). But if the category_id belongs to a different tenant (due to C1), the job will score the product against the wrong category's features.

**Fix:**
This is automatically resolved by fixing C1 (tenant scoping prevents cross-tenant category IDs). Additionally, consider adding tenant context to the job:

```php
// In RescanProductFeatures, add tenant initialization:
public function __construct(
    private readonly int $productId,
    private readonly int $categoryId,
    private readonly string $tenantId,
) {}

public function handle(): void
{
    $tenant = \App\Models\Tenant::find($this->tenantId);
    if ($tenant) {
        tenancy()->initialize($tenant);
    }
    // ... rest of handle
}
```

---

### Medium

#### M1. Loose `in_array()` Comparison in AI Response Validation

**Severity:** Medium
**Location:** `app/Services/AiService.php`, lines 440 and 443

**Description:**
Both `in_array()` calls use PHP's default loose comparison (`==` instead of `===`). While PHP 8.3 fixed the worst type juggling cases (string-to-int comparisons are now stricter), using strict mode is a defense-in-depth measure against malformed AI output.

If the AI returns a string `"0"` for a category_id, loose `in_array("0", [0, 5, 10])` would match ID 0 (if it existed). With strict mode, it would not.

**Fix:**
```php
// Line 440:
->filter(fn($item) => isset($item['id'], $item['reason']) && in_array($item['id'], $validProductIds, true))

// Line 443:
if ($categoryId !== null && !in_array($categoryId, $validCategoryIds, true)) {
```

Note: Since JSON-decoded integers from AI responses may arrive as either `int` or `string`, you may need to cast before comparison:
```php
$categoryId = $categoryId !== null ? (int) $categoryId : null;
```

#### M2. No Concurrency Protection -- Duplicate Runs Could Cause Double Assignments

**Severity:** Medium
**Location:** `app/Console/Commands/AiAssignCategories.php`, entire `handle()` method

**Description:**
If two instances of the command run simultaneously (e.g., accidental double invocation), both will fetch the same uncategorized products and send them to the AI. Both will then attempt to update the same products, and both will dispatch `RescanProductFeatures` jobs for the same products. This wastes AI API tokens (the Gemini calls) and could cause race conditions where product A gets assigned to different categories by different AI calls.

**Fix:**
Add the `Isolatable` interface to prevent concurrent execution:

```php
use Illuminate\Contracts\Console\Isolatable;

class AiAssignCategories extends Command implements Isolatable
{
    // The framework will automatically acquire a lock based on the command signature.
    // Only one instance can run at a time.
}
```

#### M3. AI Response `id` Field Not Cast to Integer Before Database Update

**Severity:** Medium
**Location:** `app/Console/Commands/AiAssignCategories.php`, lines 79 and 90

**Description:**
The AI returns JSON where `id` fields may be integers or strings. At line 79, `$item['id']` is used directly in `Product::where('id', $item['id'])->update(...)`. If the AI returns a malformed ID (e.g., a float like `123.0` or a string with extra characters), the WHERE clause would still work due to MySQL type coercion, but it is cleaner and safer to explicitly cast.

Similarly, `$item['category_id']` at line 79 passes the AI-returned value directly into the database update.

**Fix:**
Cast IDs to integers explicitly in the command:

```php
// Line 79:
Product::where('id', (int) $item['id'])->update(['category_id' => (int) $item['category_id']]);

// Line 80:
RescanProductFeatures::dispatch((int) $item['id'], (int) $item['category_id']);

// Line 90:
Product::where('id', (int) $item['id'])->update(['is_ignored' => true]);
```

---

### Low

#### L1. Unbounded `get()` Query on Products Table

**Severity:** Low
**Location:** `app/Console/Commands/AiAssignCategories.php`, line 27-29

**Description:**
`Product::whereNull('category_id')->where('is_ignored', false)->get(...)` loads all matching products into memory at once. In a scenario with thousands of uncategorized products, this could cause high memory usage. The downstream `.chunk(10)` handles the AI calls in batches, but the initial query is unbounded.

**Fix:**
Since the command already chunks the results at line 61, consider using `cursor()` or `chunk()` for the initial query, or add a `--limit` option:

```php
protected $signature = 'pw2d:ai-assign-categories
                        {tenant : The tenant ID}
                        {--dry-run : Preview assignments without making changes}
                        {--ignore-unmatched : Mark products that don\'t fit any category as ignored}
                        {--limit= : Maximum number of products to process}';

// In handle():
$query = Product::whereNull('category_id')->where('is_ignored', false);
if ($limit = $this->option('limit')) {
    $query->limit((int) $limit);
}
$products = $query->get(['id', 'name']);
```

#### L2. No Test Coverage

**Severity:** Low (but affects confidence in all the above)
**Location:** No test file exists for `AiAssignCategories`

**Description:**
There are no tests for this command. Given the tenant isolation concerns, a test that verifies tenant-scoped behavior would catch C1 immediately.

**Fix:**
Create `tests/Feature/AiAssignCategoriesTest.php` covering:
- Products from tenant A are NOT affected when running for tenant B
- AI-returned category IDs not in the valid set are nullified
- `--dry-run` mode does not modify the database
- `--ignore-unmatched` marks unmatched products as ignored

#### L3. Pre-existing Issue: `AiSweepCategory` Has the Same Cross-Tenant Problem

**Severity:** Low (informational -- not in scope of this diff, but discovered during audit)
**Location:** `app/Console/Commands/AiSweepCategory.php`, lines 23 and 33

**Description:**
The existing `AiSweepCategory` command also runs without tenant initialization. Its `Category::where('slug', ...)` query at line 23 could match categories from any tenant if slugs collide (e.g., both tenants have a "keyboards" category with slug "keyboards"). The same tenant scoping fix should be applied there.

## Summary

**Overall Assessment:** The command has one **critical** tenant isolation vulnerability (C1) that must be fixed before any production use. Because Artisan commands run outside the HTTP middleware stack, the `BelongsToTenant` global scope silently becomes a no-op, causing all queries to operate across all tenants. This is the most dangerous class of bug in a multi-tenant system -- it leads to data leaking between tenant sites.

The fix is straightforward: require a `--tenant` argument and call `tenancy()->initialize()` at the top of `handle()`. This same pattern should be applied to all operational Artisan commands (`AiSweepCategory`, `RecalculatePriceTiers`, `SyncOfferPrices`) as a systemic fix.

The AI response validation in `AiService::assignCategories()` is solid -- it validates both product IDs and category IDs against known sets. However, this defense is undermined by C1 because the "known sets" themselves include data from all tenants.

**Recommended priority:**
1. Fix C1 (critical, blocks production use)
2. Fix H1 (resolved automatically by C1 + integer casting)
3. Add `Isolatable` interface (M2) and strict `in_array` (M1)
4. Add tests (L2) to prevent regression
