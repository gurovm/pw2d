# Performance Audit -- 2026-04-04

## Files Audited
- `app/Console/Commands/AiAssignCategories.php`
- `app/Services/AiService.php` (the `assignCategories()` method, lines 399-454)
- `app/Jobs/RescanProductFeatures.php` (dispatch target)

## Findings

### Critical

#### 1. Unbounded `->get()` loads all uncategorized products into memory (Line 27-29)

**Severity:** Critical
**Location:** `app/Console/Commands/AiAssignCategories.php:27-29`

```php
$products = Product::whereNull('category_id')
    ->where('is_ignored', false)
    ->get(['id', 'name']);
```

This loads every uncategorized, non-ignored product into memory at once. If a bulk import adds hundreds or thousands of products before AI processing completes, this will spike memory usage significantly. The command already uses `->chunk(10)` on line 61, but that operates on the in-memory Collection (a `Collection::chunk`, not `Query\Builder::chunk`). The entire result set is already loaded before chunking begins.

**Fix:** Use `Query\Builder::chunk()` or `->cursor()` directly on the query, which streams results from the database:

```php
// Option A: use a lazy collection to iterate without loading everything
$query = Product::whereNull('category_id')
    ->where('is_ignored', false)
    ->select(['id', 'name']);

$total = $query->count();
if ($total === 0) {
    $this->info('No uncategorized products found.');
    return self::SUCCESS;
}

$this->info("{$prefix}Found {$total} uncategorized product(s).");

// ... leaf categories loading ...

$query->chunk(10, function ($chunk) use (...) {
    $results = $aiService->assignCategories($chunk, $leafCategories);
    // ... process results ...
});
```

This changes the in-memory collection chunk to a true database-level chunk, keeping memory usage constant regardless of how many uncategorized products exist.

---

#### 2. Missing tenant context -- command operates across ALL tenants (Line 27-29, 39)

**Severity:** Critical (correctness + performance)
**Location:** `app/Console/Commands/AiAssignCategories.php:27-29` and `39-47`

The `TenantScope` (from `stancl/tenancy`) is a no-op when tenancy is not initialized (`tenancy()->initialized === false`), which is the default for Artisan commands. This means:

1. **Line 27-29:** `Product::whereNull('category_id')` fetches uncategorized products across ALL tenants.
2. **Line 39:** `Category::doesntHave('children')` fetches leaf categories across ALL tenants.
3. **Line 80:** `RescanProductFeatures::dispatch()` will run jobs that may then operate in a different tenant context than intended.

Products from tenant A could be assigned to categories belonging to tenant B. The AI prompt will receive categories from mixed tenants, causing nonsensical assignments.

**Fix:** Add a `--tenant` option (required) and initialize tenancy before querying:

```php
protected $signature = 'pw2d:ai-assign-categories
    {--tenant= : Tenant ID (required)}
    {--dry-run : Preview assignments without making changes}
    {--ignore-unmatched : Mark unmatched products as ignored}';

public function handle(AiService $aiService): int
{
    $tenantId = $this->option('tenant');
    if (!$tenantId) {
        $this->error('--tenant is required.');
        return self::FAILURE;
    }

    $tenant = \App\Models\Tenant::find($tenantId);
    if (!$tenant) {
        $this->error("Tenant '{$tenantId}' not found.");
        return self::FAILURE;
    }
    tenancy()->initialize($tenant);

    // ... rest of command ...
}
```

This is both a correctness bug and a performance issue since without scoping the query scans far more rows than intended and lacks the composite index benefit (the existing index is `(tenant_id, category_id)` which is only useful when `tenant_id` is in the WHERE clause).

---

### High

#### 3. Missing composite index for `(tenant_id, category_id, is_ignored)` (Line 27-29)

**Severity:** High
**Location:** Database index gap affecting `app/Console/Commands/AiAssignCategories.php:27-29`

The query `WHERE category_id IS NULL AND is_ignored = false` (with tenant scope adding `AND tenant_id = ?`) needs an index that covers all three columns. The existing indexes are:

- `(tenant_id, category_id)` -- partially useful but does not cover `is_ignored`
- `(tenant_id, status)` -- irrelevant
- `(tenant_id, name)` -- irrelevant
- No index exists on `is_ignored` at all

For the tenant-scoped version of this query, MySQL can use `(tenant_id, category_id)` but still has to scan all rows with `category_id IS NULL` within the tenant to filter by `is_ignored`. Since `is_ignored` is a boolean with low cardinality, MySQL may not even use it efficiently.

**Fix:** Add a composite index in a new migration:

```php
// database/migrations/2026_04_04_000001_add_uncategorized_index_to_products.php
Schema::table('products', function (Blueprint $table) {
    // Optimizes: WHERE tenant_id = ? AND category_id IS NULL AND is_ignored = false
    $table->index(
        ['tenant_id', 'category_id', 'is_ignored'],
        'idx_products_tenant_category_ignored'
    );
});
```

This index allows the query to jump directly to `tenant_id = X, category_id = NULL, is_ignored = 0` rows.

---

#### 4. Queue flooding -- dispatches one `RescanProductFeatures` job per assigned product (Line 80)

**Severity:** High
**Location:** `app/Console/Commands/AiAssignCategories.php:80`

```php
RescanProductFeatures::dispatch($item['id'], $item['category_id']);
```

Each assigned product dispatches a separate `RescanProductFeatures` job. Each job makes an AI API call to Gemini (`AiService::rescanFeatures()`). With 2 queue workers in production using the database driver:

- 100 assigned products = 100 queue jobs, each making an AI API call with a 60s timeout
- At 2 workers, that is ~50 minutes of sequential AI processing
- Gemini rate limits could be hit, causing cascading failures and retries
- The `jobs` table in MySQL (database queue driver) will spike, adding write contention

**Fix:** Consider rate-limiting the dispatch or using a chained batch:

```php
// Option A: Add a delay between jobs to avoid rate limiting
$delay = 0;
foreach ($results as $item) {
    if ($item['category_id'] && !$isDryRun) {
        RescanProductFeatures::dispatch($item['id'], $item['category_id'])
            ->delay(now()->addSeconds($delay));
        $delay += 5; // 5 seconds between each AI call
    }
}

// Option B: Use Bus::batch() for better monitoring
use Illuminate\Support\Facades\Bus;

$jobs = collect($results)
    ->filter(fn ($item) => $item['category_id'] !== null)
    ->map(fn ($item) => new RescanProductFeatures($item['id'], $item['category_id']));

if ($jobs->isNotEmpty() && !$isDryRun) {
    Bus::batch($jobs->toArray())
        ->name('ai-assign-categories-rescan')
        ->allowFailures()
        ->dispatch();
}
```

---

### Medium

#### 5. Leaf categories query is not cached (Lines 39-47)

**Severity:** Medium
**Location:** `app/Console/Commands/AiAssignCategories.php:39-47`

```php
$leafCategories = Category::doesntHave('children')
    ->get(['id', 'name', 'description'])
    ->map(...)
    ->toArray();
```

This query uses a `NOT EXISTS` subquery (`doesntHave('children')`), which is moderately expensive. While this only runs once per command invocation, caching it would be beneficial if the command is run repeatedly (e.g., during initial setup or after bulk imports).

**Fix:** Cache for 10 minutes (categories rarely change):

```php
$leafCategories = Cache::remember(
    'leaf-categories:' . (tenant('id') ?? 'global'),
    600,
    fn () => Category::doesntHave('children')
        ->get(['id', 'name', 'description'])
        ->map(fn($c) => [
            'id'          => $c->id,
            'name'        => $c->name,
            'description' => $c->description,
        ])
        ->values()
        ->toArray()
);
```

---

#### 6. AI batch size of 10 may be suboptimal (Line 61)

**Severity:** Medium
**Location:** `app/Console/Commands/AiAssignCategories.php:61`

The chunk size of 10 products per AI call is reasonable for prompt length, but each AI call has a 90-second timeout (`AiService::assignCategories` line 426). The calls are sequential -- no parallelism. For 200 uncategorized products, that is 20 sequential AI calls, potentially taking up to 30 minutes (90s worst case per call, though typical is 5-15s).

**Suggestion:** The batch size of 10 is actually fine for the AI prompt (keeps token count manageable). However, the sequential nature means the command blocks for a long time with large product counts. Consider:

1. Adding a progress bar for user feedback
2. Adding a `--limit` option to cap the number of products processed per run

```php
protected $signature = 'pw2d:ai-assign-categories
    {--tenant= : Tenant ID (required)}
    {--limit= : Maximum products to process}
    {--dry-run}
    {--ignore-unmatched}';

// In handle():
$query = Product::whereNull('category_id')->where('is_ignored', false);
if ($limit = $this->option('limit')) {
    $query->limit((int) $limit);
}
```

---

#### 7. Individual `UPDATE` queries inside the loop (Lines 79, 90)

**Severity:** Medium
**Location:** `app/Console/Commands/AiAssignCategories.php:79, 90`

```php
Product::where('id', $item['id'])->update(['category_id' => $item['category_id']]);
// and
Product::where('id', $item['id'])->update(['is_ignored' => true]);
```

Each product update is a separate query. Within a chunk of 10, this means up to 10 individual UPDATE statements. While acceptable for small batches, a bulk update would be more efficient:

```php
// Collect updates, then batch
$categoryUpdates = [];
$ignoreIds = [];

foreach ($results as $item) {
    if ($item['category_id']) {
        $categoryUpdates[$item['category_id']][] = $item['id'];
    } elseif ($ignoreUnmatched) {
        $ignoreIds[] = $item['id'];
    }
}

foreach ($categoryUpdates as $categoryId => $productIds) {
    Product::whereIn('id', $productIds)->update(['category_id' => $categoryId]);
}

if ($ignoreIds) {
    Product::whereIn('id', $ignoreIds)->update(['is_ignored' => true]);
}
```

This reduces N queries to at most (number of distinct categories + 1).

---

### Low

#### 8. `collect($leafCategories)->pluck()` creates unnecessary collection on each chunk (Line 72)

**Severity:** Low
**Location:** `app/Console/Commands/AiAssignCategories.php:72`

```php
$categoryName = collect($leafCategories)->firstWhere('id', $item['category_id'])['name'] ?? '?';
```

Inside the `foreach ($results ...)` loop (which runs inside `->each()`), a new Collection is created from the `$leafCategories` array on every iteration. This is a minor allocation.

**Fix:** Build a lookup map once before the loop:

```php
$categoryNameMap = collect($leafCategories)->pluck('name', 'id')->toArray();

// Then inside the loop:
$categoryName = $categoryNameMap[$item['category_id']] ?? '?';
```

---

#### 9. No progress indication for long-running command

**Severity:** Low
**Location:** `app/Console/Commands/AiAssignCategories.php` (general)

The command provides no progress bar or timing information. With many products, the user has no feedback on how far along the command is. Laravel's `$this->withProgressBar()` or a manual progress bar would improve the user experience.

---

## Summary

The command has two critical issues that should be addressed before production use:

1. **Missing tenant scoping** -- the command will mix products and categories across tenants when run from CLI, leading to incorrect category assignments. This is both a correctness and performance problem.

2. **Unbounded `->get()` loading all results into memory** -- should be converted to true database-level `chunk()` to keep memory constant.

Beyond those, the main performance concern is **queue flooding** from dispatching one `RescanProductFeatures` job per assigned product, which can overwhelm the 2-worker queue and hit Gemini API rate limits. Adding staggered delays or batch processing would mitigate this.

The existing database indexes partially cover this command's queries (`(tenant_id, category_id)` exists) but a composite index adding `is_ignored` would improve the uncategorized-products lookup.

Overall, the command is structurally sound for small-scale use (tens of products) but needs the fixes above to handle scale safely.
