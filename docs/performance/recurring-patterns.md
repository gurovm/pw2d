# Performance Findings -- Recurring Patterns
**Last updated:** 2026-04-04

## Pattern 1: Cache Keys Missing Tenant Scope
**Severity:** CRITICAL
**Status:** RESOLVED -- All cache keys now use `tenant_cache_key()` helper which prefixes with `t{tenantId}:`.

Verified locations (all correct):
- `app/Models/Setting.php:19` -- uses `tenant_cache_key("setting:{$key}")`
- `app/Livewire/ProductCompare.php:112` -- uses `tenant_cache_key(...)`
- `app/View/Components/SimilarProducts.php:20` -- uses `tenant_cache_key(...)`

## Pattern 2: Queries in Render Methods Without Caching
**Severity:** HIGH
**Occurrences:** 3+ confirmed (Livewire) + 2 confirmed (Filament)

Livewire `render()` methods, computed properties, and Filament navigation badge / widget `getStats()` methods fire DB queries on every re-render without caching.

Affected (Livewire):
- `app/Livewire/Home.php:24-47` -- **RESOLVED** -- uses `Cache::remember()` with 3600s TTL
- `app/Livewire/GlobalSearch.php:128` -- **RESOLVED** -- uses `Cache::remember()` with 3600s TTL
- `app/Support/SeoSchema.php:161` -- Preset query inside `forLeafCategory()`, called from `render()` on every Livewire re-render
- `resources/views/livewire/comparison-header.blade.php:15` -- `Category::find()` in Blade template on every render

Affected (Filament):
- `app/Filament/Pages/ProblemProducts.php:46-49` -- complex REGEXP+subquery COUNT on every admin page load (navigation badge)
- `app/Filament/Widgets/ProductStatsWidget.php:15-21` -- 6 separate COUNT queries on every dashboard render
- `app/Filament/Resources/ProductResource/Pages/ListProducts.php:28` -- failed products count query on every ListProducts render

**Fix pattern:** Use `Cache::remember()` with tenant-scoped key and appropriate TTL (60-120s for admin stats, 300-600s for slowly-changing data like categories, presets, features).

## Pattern 3: Missing Image Attributes (width/height/loading)
**Severity:** MEDIUM
**Occurrences:** 6 locations

`<img>` tags for category cards, similar products, and search results lack `width`, `height`, and `loading="lazy"` attributes, causing Cumulative Layout Shift (CLS).

Affected:
- `resources/views/livewire/home.blade.php:75` -- category card images (missing width, height, loading="lazy")
- `resources/views/livewire/product-compare.blade.php:31` -- subcategory card images (missing width, height, loading="lazy")
- `resources/views/livewire/product-compare.blade.php:60-62` -- category hero image (missing width, height, fetchpriority="high")
- `resources/views/components/similar-products.blade.php:15` -- similar product images (missing width, height, loading="lazy")
- `resources/views/livewire/global-search-results.blade.php:74` -- search result thumbnails (missing width, height, loading="lazy")
- `resources/views/livewire/product-compare.blade.php:557-560` -- product modal image (has width/height, missing fetchpriority="high")

Already correct:
- `resources/views/livewire/product-compare.blade.php:394` -- product grid cards (has width, height, loading="lazy")
- `resources/views/livewire/navigation.blade.php:9-18` -- nav logo (has width, height, fetchpriority="high")

**Fix pattern:** Always include explicit `width` and `height` attributes. Add `loading="lazy"` for below-fold images. Add `fetchpriority="high"` for LCP candidates.

## Pattern 4: Redundant Queries Across Sibling Components
**Severity:** MEDIUM
**Occurrences:** 2 confirmed

Parent and child Livewire components query the same data independently.

Affected:
- `ComparisonHeader::mount()` line 67-68 re-queries `Category::with('children')->find($categoryId)` -- the category and children are already loaded by `ProductCompare::mount()`
- `SeoSchema::forLeafCategory()` line 161 queries `Preset::where('category_id', ...)->get()` -- presets are already loaded in ComparisonHeader

**Fix pattern:** Pass data from parent to child via props. Avoid re-querying in mount/render when the parent already has the data.

## Pattern 5: No Exponential Backoff on External API Jobs
**Severity:** HIGH
**Status:** RESOLVED -- both `ProcessPendingProduct` and `RescanProductFeatures` now have `$backoff = [10, 60, 300]`.

**Remaining gap:** GeminiService itself has zero transport-level retry for 429 rate limits. A 429 throws immediately, consuming a job-level retry. Add `Http::retry()` with a 429-specific `when` clause to handle transient rate limits without burning job attempts.

## Pattern 6: Artisan Commands Missing Tenant Context
**Severity:** CRITICAL
**Status:** RESOLVED for AiAssignCategories (now requires `{tenant}` argument).

**Fix pattern:** All Artisan commands operating on tenant-scoped models MUST either:
1. Accept a `--tenant` option and call `tenancy()->initialize($tenant)` before querying, OR
2. Use `withoutGlobalScopes()` explicitly and handle `tenant_id` filtering manually.

## Pattern 7: Unbounded `->get()` in Console Commands
**Severity:** HIGH
**Status:** RESOLVED for AiAssignCategories (now uses `chunkById()`).

**Remaining instance:** `RecalculatePriceTiers` uses `Category::with(['products.offers'])->get()` which loads ALL categories, ALL products, and ALL offers into memory. Use `chunkById()` per category with a `MIN(scraped_price)` join.

**Fix pattern:** Use `Query\Builder::chunkById()` or `->cursor()` instead of `->get()` for potentially large result sets.

## Pattern 8: Mass DELETE Without Proper Scoping
**Severity:** CRITICAL
**Occurrences:** 1 confirmed
**Added:** 2026-04-04

`ProcessPendingProduct.php:173-176` deletes ALL negative `AiMatchingDecision` rows for the entire tenant every time a product is successfully processed. This:
- Causes write amplification (hundreds of rows deleted per product)
- Invalidates valid negative cache entries, forcing expensive AI re-evaluation on next import
- Creates lock contention on `ai_matching_decisions` during batch imports

**Fix pattern:** Scope bulk deletes to the minimum affected subset. In this case, only invalidate decisions for the same brand, or better yet, only invalidate when the newly-processed product could realistically match future imports (same brand + category).

## Pattern 9: Repeated Identical Queries Inside Job Loops
**Severity:** HIGH
**Occurrences:** 1 confirmed
**Added:** 2026-04-04

`ProcessPendingProduct.php:237-239` queries `Store::withoutGlobalScopes()->where('is_active', true)->get(['slug'])` on every image download to check SSRF allowlisting. During a batch import, this fires the same query N times.

**Fix pattern:** Cache slowly-changing lookup data (store slugs, category features, allowed hosts) with `Cache::remember()` and a TTL of 1-60 minutes.

## Pattern 10: Missing ShouldBeUnique on AI Jobs
**Severity:** HIGH
**Occurrences:** 2 confirmed
**Added:** 2026-04-04

Neither `ProcessPendingProduct` nor `RescanProductFeatures` implements `ShouldBeUnique`. Duplicate dispatches (rapid extension clicks, batch re-imports) can result in concurrent execution of the same product, causing duplicate AI API calls and race conditions on merge logic.

**Fix pattern:** All jobs that call external APIs with per-entity scope should implement `ShouldBeUnique` with a `uniqueId()` returning the entity's primary key.

## Pattern 11: Missing Eager Loading in Filament Resource Tables
**Severity:** HIGH
**Occurrences:** 3 confirmed
**Added:** 2026-04-04

Filament resource tables that display relationship columns (e.g., `product.name`, `parent.name`, `feature.category.name`) without a `modifyQueryUsing` that eager-loads those relationships cause N+1 queries. Each row triggers a lazy load.

Affected:
- `app/Filament/Resources/AiMatchingDecisionResource.php` -- `product.name` column, no eager loading
- `app/Filament/Resources/CategoryResource.php` -- `parent.name` column, no eager loading
- `app/Filament/Resources/ProductResource/RelationManagers/FeatureValuesRelationManager.php` -- `feature.category.name` column, no eager loading

**Fix pattern:** Always add `->modifyQueryUsing(fn (Builder $query) => $query->with([...]))` on Filament tables that display relationship columns. Specify only the columns needed (e.g., `'product:id,name'`).

## Pattern 12: `withoutGlobalScopes()` Used Where Tenant Scoping Is Active
**Severity:** CRITICAL
**Occurrences:** 1 confirmed
**Added:** 2026-04-04

`ListProducts.php:28,43` uses `Product::withoutGlobalScopes()` for the "Retry Failed" button, but Filament's admin panel already initializes stancl tenancy via the `TenantSet` event bridge (AdminPanelProvider.php:70-72). This means `withoutGlobalScopes()` is unnecessary and actively harmful -- it bypasses tenant scoping, causing the query to count/process failed products from ALL tenants.

**Fix pattern:** Never use `withoutGlobalScopes()` in Filament resource pages unless there is a documented cross-tenant requirement. The Filament admin panel initializes tenancy via `TenantSet`, so normal queries are already correctly scoped.

## Pattern 13: Bulk Actions Using Per-Record Updates Instead of Single Query
**Severity:** MEDIUM
**Occurrences:** 1 confirmed
**Added:** 2026-04-04

Filament bulk actions that update a boolean flag on multiple records use `foreach ($records as $record) { $record->update(...) }` instead of a single `whereIn('id', ...)->update(...)`.

Affected:
- `app/Filament/Resources/ProductResource.php:248-252` -- `markIgnored` bulk action uses N individual updates

**Fix pattern:** Use `Model::whereIn('id', $records->pluck('id'))->update([...])` for bulk flag updates. This reduces N queries to 1. See `ProblemProducts.php:241` for the correct pattern already in the codebase.

## Pattern 14: Product Accessors Require `offers.store` Eager Loading
**Severity:** CRITICAL
**Occurrences:** 3 confirmed query sites
**Added:** 2026-04-04

The Product model's `bestPrice`, `bestOffer`, `affiliateUrl`, `estimatedPrice`, and `imageUrl` accessors ALL depend on the `offers` (and `offers.store`) relationship. Any query that fetches Product models for rendering MUST include `->with(['offers.store'])`. When this invariant is violated, each accessor triggers lazy-loaded queries.

Affected:
- `app/Livewire/ProductCompare.php:181,207` -- `visibleProducts` loads `['brand', 'featureValues.feature']` but NOT `offers.store`. Blade uses `image_url`, `affiliate_url`. **~36 extra queries per page render.**
- `app/Livewire/ProductCompare.php:91` -- `selectedProduct` same issue. **~6-10 extra queries per modal open.**
- `app/View/Components/SimilarProducts.php:24-45` -- zero eager loading. **~12 extra queries per product detail view** (mitigated by 7-day cache, but first-hit is expensive).

**Fix pattern:** Always add `'offers.store'` to the eager-load list when fetching products for display. Add a PHPDoc note on the Product model documenting this invariant.

## Pattern 15: Setting::get() Caches Default Value (Bug)
**Severity:** MEDIUM
**Occurrences:** 1 (Setting model)
**Added:** 2026-04-04

`Setting::get($key, $default)` wraps the default value inside `Cache::rememberForever()`. Once cached, subsequent calls with different defaults still return the first-cached default. Fix: cache only the raw DB value, apply default outside cache boundary.

## Pattern 16: Observer Cascades Causing Recursive Event Firing
**Severity:** MEDIUM
**Occurrences:** 1 confirmed
**Added:** 2026-04-04

`FeatureObserver::created` calls `Feature::create()` for descendant categories, which fires the observer again recursively. The `exists()` check prevents duplicate data, but the recursive queries are wasteful.

**Fix pattern:** Wrap propagated model creation in `Model::withoutEvents()` to prevent observer re-entry.

## Pattern 17: TEXT Columns Used for Exact-Match Lookups Without Hash Index
**Severity:** HIGH
**Occurrences:** 1 confirmed (product_offers.url)
**Added:** 2026-04-04

`product_offers.url` is a TEXT column queried with exact-match WHERE clauses in three separate code paths (OfferIngestionService, ProductImportController, BatchImportController). MySQL cannot index TEXT columns efficiently for equality checks. Every exact-match query on `url` does a full scan within the filtered rows.

**Fix pattern:** For TEXT columns used in equality lookups, add a companion `{column}_hash` CHAR(64) column storing `SHA2(value, 256)`. Index the hash column. Query by hash for exact matches. Alternatively, change the column to VARCHAR(N) if the max length is bounded.

## Pattern 18: Per-Row INSERT/UPDATE in High-Throughput Loops
**Severity:** HIGH
**Occurrences:** 1 confirmed (BatchImportController)
**Added:** 2026-04-04

`BatchImportController::import()` processes up to 100 products in a loop, firing `Product::create()` + `ProductOffer::create()` + `ProcessPendingProduct::dispatch()` per iteration. This generates 200+ individual INSERT queries and 100 job dispatch INSERTs per request.

**Fix pattern:** For batch endpoints, collect records into arrays and use `Model::insert()` or `Model::upsert()` for bulk operations. Use `Queue::bulk()` to dispatch jobs in one query. Wrap in `DB::transaction()` for atomicity and performance.

## Pattern 19: Uncached Middleware DB Lookups on API Routes
**Severity:** HIGH
**Occurrences:** 1 confirmed (InitializeTenancyFromPayload)
**Added:** 2026-04-04

`InitializeTenancyFromPayload` calls `Tenant::find($tenantId)` on every Chrome Extension API request without caching. Unlike the domain-based `DomainTenantResolver` (which uses `$shouldCache = true` with 3600s TTL), the payload-based middleware has no cache layer. At 120 requests/min, this adds 7200 unnecessary queries/hour.

**Fix pattern:** Cache model lookups in middleware using `Cache::remember()` with a TTL matching the domain resolver (3600s). Invalidate on model update events.

## Pattern 20: Livewire `wire:model.live` on Range Sliders Without Debounce
**Severity:** HIGH
**Occurrences:** 1 confirmed
**Added:** 2026-04-04

`wire:model.live` on `<input type="range">` elements sends a Livewire round-trip on every `input` event (continuous during mouse drag). Each request triggers full component re-render including DB queries and scoring.

Affected:
- `resources/views/livewire/product-compare.blade.php:322` -- price slider sends 20-50 requests per drag

**Fix pattern:** Use `wire:model.live.debounce.300ms` at minimum. Prefer Alpine `x-model` for immediate visual feedback with Livewire dispatch only on `@change` (mouseup).
