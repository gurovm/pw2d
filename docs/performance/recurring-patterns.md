# Performance Findings -- Recurring Patterns
**Last updated:** 2026-03-22

## Pattern 1: Cache Keys Missing Tenant Scope
**Severity:** CRITICAL
**Occurrences:** 3 confirmed

Cache keys throughout the codebase do not include the tenant ID. In a multi-tenant setup, this causes cross-tenant data leakage.

Affected locations:
- `app/Models/Setting.php:19` -- `"setting:{$key}"` should be `"setting:{tenantId}:{$key}"`
- `app/Livewire/ProductCompare.php:112` -- `"products:cat{id}:b{brand}:p{price}"` missing tenant prefix
- `app/View/Components/SimilarProducts.php:20` -- `"similar_products_{id}"` missing tenant prefix

**Fix pattern:** Always prefix cache keys with `tenancy()->initialized ? tenant('id') : 'central'`.

## Pattern 2: Queries in Render Methods Without Caching
**Severity:** HIGH
**Occurrences:** 3 confirmed

Livewire `render()` methods and computed properties fire DB queries on every re-render without caching.

Affected:
- `app/Livewire/Home.php:24-47` -- 2-3 category queries per render
- `app/Livewire/GlobalSearch.php:126` -- loads ALL categories+presets per AI search
- `app/Livewire/ProductCompare.php:559` -- preset query inside render

**Fix pattern:** Use `Cache::remember()` with tenant-scoped key and 300-600s TTL for slowly-changing data (categories, presets, features).

## Pattern 3: Missing Image Attributes (width/height/loading)
**Severity:** MEDIUM
**Occurrences:** 4 locations

`<img>` tags for category cards and similar products lack `width`, `height`, and `loading="lazy"` attributes, causing Cumulative Layout Shift (CLS).

**Fix pattern:** Always include explicit `width` and `height` attributes. Add `loading="lazy"` for below-fold images. Add `fetchpriority="high"` for LCP candidates.

## Pattern 4: Redundant Queries Across Sibling Components
**Severity:** MEDIUM
**Occurrences:** 2 confirmed

Parent and child Livewire components query the same data independently.

Affected:
- `ComparisonHeader::mount()` line 68 re-queries the Category already loaded by `ProductCompare::mount()`
- `ProductCompare::render()` queries Preset data that ComparisonHeader already has

**Fix pattern:** Pass data from parent to child via props. Avoid re-querying in mount/render when the parent already has the data.

## Pattern 5: No Exponential Backoff on External API Jobs
**Severity:** HIGH
**Occurrences:** 2 jobs

Both queue jobs (`ProcessPendingProduct`, `RescanProductFeatures`) retry immediately on failure, including Gemini API rate limits.

**Fix pattern:** Add `public array $backoff = [10, 60, 300];` to all jobs that call external APIs.
