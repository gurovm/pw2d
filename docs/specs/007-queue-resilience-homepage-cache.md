# Spec 007: Queue Job Resilience & Homepage Caching

**Priority:** HIGH
**Audit refs:** Performance #2 (no backoff), Performance #3 (homepage uncached), Performance #4 (GlobalSearch uncached categories), Performance #5 (sitemap memory)

---

## Problem

### Queue Jobs
`ProcessPendingProduct` and `RescanProductFeatures` both have `$tries = 3` but no `$backoff` property. When Gemini returns 429 (rate limit), all 3 retries fire immediately — compounding the rate limit and guaranteeing failure.

### Homepage
`Home::render()` fires 2-3 category queries on every page load. Homepage is highest-traffic page; this data changes at most a few times per week.

### GlobalSearch
`Category::with('presets')->get()` runs uncached on every AI search trigger — a full table scan that grows linearly.

### Sitemap
`SitemapController` loads all products and categories via `get()` without chunking.

## Changes Required

### 1. Add exponential backoff to queue jobs

**File:** `app/Jobs/ProcessPendingProduct.php`

```php
// Add after line 23
public array $backoff = [10, 60, 300]; // 10s, 1min, 5min
```

**File:** `app/Jobs/RescanProductFeatures.php`

```php
// Add after line 31
public array $backoff = [10, 60, 300];
```

### 2. Cache homepage data

**File:** `app/Livewire/Home.php`

```php
public function render()
{
    $cacheKey = tenant_cache_key('home:popular_categories');

    $popularCategories = Cache::remember($cacheKey, 3600, function () {
        return Category::whereHas('products')
            ->withCount('products')
            ->orderByDesc('products_count')
            ->limit(8)
            ->get(['id', 'name', 'slug', 'description', 'image']);
    });

    $samplePrompts = Cache::remember(
        tenant_cache_key('home:sample_prompts'),
        3600,
        function () {
            $prompts = Category::whereNotNull('sample_prompts')
                ->get(['id', 'sample_prompts'])
                ->pluck('sample_prompts')
                ->map(fn ($v) => self::normalizePrompts($v))
                ->flatten()
                ->filter()
                ->shuffle()
                ->take(8)
                ->values()
                ->toArray();

            if (empty($prompts)) {
                $prompts = Category::inRandomOrder()
                    ->limit(6)
                    ->pluck('name')
                    ->map(fn ($name) => 'best ' . strtolower($name) . ' for my needs')
                    ->values()
                    ->toArray();
            }

            return $prompts ?: ['Tell me what you need...', 'What are you shopping for?'];
        }
    );

    $searchHints = $popularCategories->shuffle()->take(3)
        ->map(fn ($c) => 'best ' . strtolower($c->name))
        ->values()
        ->toArray();

    return view('livewire.home', compact('popularCategories', 'samplePrompts', 'searchHints'));
}
```

Cache TTL: 1 hour. Cache is busted implicitly when it expires — no need for active invalidation since category changes are infrequent.

### 3. Cache categories in GlobalSearch

**File:** `app/Livewire/GlobalSearch.php` — line 126

```php
// BEFORE
$categories = Category::with('presets:id,category_id,name')
    ->get(['id', 'name', 'slug', 'description']);

// AFTER
$categories = Cache::remember(
    tenant_cache_key('search:categories_with_presets'),
    3600,
    fn () => Category::with('presets:id,category_id,name')
        ->get(['id', 'name', 'slug', 'description'])
);
```

### 4. Chunk sitemap generation

**File:** `app/Http/Controllers/SitemapController.php`

```php
public function index()
{
    $categories = Category::select(['id', 'slug', 'updated_at'])->get();

    // Use cursor to avoid loading all products into memory
    $products = Product::where('is_ignored', false)
        ->whereNull('status')
        ->select(['slug', 'updated_at'])
        ->cursor();

    $leafCategoryIds = Category::doesntHave('children')->pluck('id');
    $categorySlugMap = $categories->pluck('slug', 'id');

    $presets = Preset::whereIn('category_id', $leafCategoryIds)
        ->select(['category_id', 'name', 'updated_at'])
        ->get()
        ->map(fn($p) => [
            'category_slug' => $categorySlugMap[$p->category_id] ?? null,
            'preset_slug'   => Str::slug($p->name),
            'updated_at'    => $p->updated_at,
        ])
        ->filter(fn($p) => $p['category_slug'] !== null);

    return response()
        ->view('sitemap', compact('categories', 'products', 'presets'))
        ->header('Content-Type', 'text/xml');
}
```

Note: `cursor()` returns a `LazyCollection` — verify the Blade `@foreach` in `sitemap.blade.php` works with it (it should).

### 5. Escape LIKE wildcards in GlobalSearch

**File:** `app/Livewire/GlobalSearch.php` — `runDbSearch()` method

```php
// At the top of runDbSearch()
$term = str_replace(['%', '_'], ['\%', '\_'], $this->query);
```

This fixes lines 252, 260, and 277 in one place since `$term` is used in all three LIKE queries.

## Files Modified

| File | Action |
|------|--------|
| `app/Jobs/ProcessPendingProduct.php` | Add `$backoff` property |
| `app/Jobs/RescanProductFeatures.php` | Add `$backoff` property |
| `app/Livewire/Home.php` | Wrap queries in `Cache::remember()` |
| `app/Livewire/GlobalSearch.php` | Cache categories, escape LIKE wildcards |
| `app/Http/Controllers/SitemapController.php` | Use `cursor()` for products |

## Testing

- **Unit:** `ProcessPendingProduct` has `$backoff` property with escalating values.
- **Feature:** Homepage renders from cache on second load (assert no DB queries).
- **Feature:** GlobalSearch with query `%test%` does not match everything (LIKE injection blocked).
- **Feature:** Sitemap generates successfully with 10k+ products without memory error.
