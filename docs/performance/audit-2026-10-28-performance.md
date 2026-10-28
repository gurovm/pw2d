# Performance Audit: Spec 042 Phase B, product page title template (commit 959ec30)
**Date:** 2026-10-28
**Scope:** `app/Support/SeoSchema.php::forSelectedProduct()` (title now uses `Product::editorialScore()`), `app/Filament/Resources/TenantResource.php` (new Select), `app/Support/ProductNameShaper.php` (docblock only), plus every caller of `forSelectedProduct()`.
**Method:** Code reading only. No queries were run: the local DB is empty and this audit had no shell access, so costs are reasoned from the code, not measured.

## Summary
The commit adds **no database queries and no measurable request cost.** No critical or high issues.

1. **Medium: a change to the title setting reaches the public site up to 1 hour late.** The tenant is served from the resolver cache (3600 s) and nothing clears that cache when a tenant is saved.
2. **Low: the eager-load requirement is not written down.** `forSelectedProduct()` now calls `editorialScore()` on every call. All current callers eager-load the relations it needs, but a future caller passing a bare `Product` would lazy-load 2-3 queries without any error.
3. **Low, no action needed: `forSelectedProduct()` runs twice on the `openProduct()` round-trip.** The second run costs 0 queries and microseconds of CPU.

Counts for this commit: **Critical 0 · High 0 · Medium 1 · Low 2.**
Two older problems on the same code path are listed separately at the end. This commit did not introduce them.

## Answers to the audit questions

### What `editorialScore()` reads, and whether every caller has it loaded
`Product::editorialScore()` (`app/Models/Product.php:109-129`) reads `$this->category->features` (line 111) and `$this->featureValues` (line 117). It runs no query itself. `forSelectedProduct()` now calls it on every call, at `SeoSchema.php:245`, before both the `ai_summary` check and the tenant template check.

| Caller | Product instance passed | `featureValues` | `category.features` | Extra queries from this change |
|---|---|---|---|---|
| `GET /product/{slug}` → `ProductCompare::render()` → `forCategoryPage()` → `forSelectedProduct()` (`ProductCompare.php:740-748`, `SeoSchema.php:46-47`) | `$this->selectedProduct` computed | eager-loaded (`ProductCompare.php:116`) | eager-loaded (`ProductCompare.php:116`) | 0 |
| `ProductCompare::openProduct()` (`ProductCompare.php:438-439`) | same computed | eager-loaded | eager-loaded | 0 |
| Every later Livewire round-trip while a product is open (`render()`) | same computed (re-queried each request, as before) | eager-loaded | eager-loaded | 0 |
| Tests (`tests/Feature/Seo/SeoSchemaTest.php`) | `->load('brand','category.features','featureValues')` | loaded | loaded | n/a |

`grep` finds no other callers. `LandingPageController`, `VsPageController` and `Home` do not call `forSelectedProduct()`. The `editorialScore()` calls in `compared-with.blade.php:40`, `vs/show.blade.php:81` and `SelectRivals.php:89` belong to other code and were not changed by this commit. `SelectRivals::liveQuery()` eager-loads both relations (`SelectRivals.php:104-109`).

The only lazy load in `forSelectedProduct()` is `$category->parent`, used for the breadcrumb (`SeoSchema.php:319`). It costs 1 query per request and predates this commit, which added only `$category->name` and `$score`. Loading `parent` eagerly would also cost 1 query, so changing it saves nothing.

### How `tenant('seo_product_title')` is resolved
- `InitializeTenancyIfApplicable` is appended to the `web` group (`bootstrap/app.php:15-17`). Livewire's `/livewire/update` route uses `web` (`vendor/livewire/livewire/src/Mechanisms/HandleRequests/HandleRequests.php:22-23`). Tenancy is therefore initialized on full page loads and on every Livewire round-trip.
- The resolver cache is on with a 3600 s TTL (`app/Providers/TenancyServiceProvider.php:59-60`). On a hit, the whole `Tenant` model comes from the cache store (`vendor/stancl/tenancy/src/Resolvers/Contracts/CachedTenantResolver.php:37-42`), so **0 MySQL queries**. Production uses Redis according to `docs/project_context.md:150`, so a hit is one Redis GET. I did not check the production `.env`; `.env.example:40` says `database`, which would make a hit one query on the `cache` table. On a miss (once per hour per domain) it costs 2 queries: the tenant `whereHas` domains, plus the `domains` eager load (`DomainTenantResolver.php:35-40`).
- `tenant($key)` is `app(Tenant::class)->getAttribute($key)` (`vendor/stancl/tenancy/src/helpers.php:23-34`). That is an in-memory read with no query. It is also only evaluated when `$category && $score !== null` (short-circuit at `SeoSchema.php:248`).

### Whether `forSelectedProduct()` runs more than once per request
| Request | Runs | Queries added by the second run |
|---|---|---|
| `GET /product/{slug}` | 1 (`render()`) | n/a |
| `GET /compare/{slug}` | 0 (no product selected) | n/a |
| `openProduct()` round-trip | **2**: `openProduct():439`, then `render():740` → `SeoSchema:47` | 0. It is the same memoized `#[Computed]` instance, so `category.parent` is already set on the model by the first run. |
| Any other round-trip with a product open (sliders, `revealMore`, `toggleCompare`) | 1 (`render()`) | n/a |

In addition, `editorialScore()` runs once more in the Blade view (`resources/views/livewire/product-compare.blade.php:611`). That makes 2-3 calls per request on the same instance. Each call is one pass over the category's features plus one `pluck` of the product's feature values: a handful of hash lookups, in the low microseconds. Caching or memoizing it would cost more than it saves.

### Caching and index needs created by this change
- **Index needs: none.** The change issues no new query. The relations it reads come from existing eager loads, which already use indexes: `product_feature_values.product_id` (`database/migrations/2026_02_13_000006_create_product_feature_values_table.php:22`) and `features.category_id` (`database/migrations/2026_02_13_000005_create_features_table.php:24`).
- **Caching the `forSelectedProduct()` output: not recommended.** It is pure string building over data already in memory, cheaper than the Redis round-trip a cache would add.
- **Tenant cache invalidation: needed.** See the medium finding below.

## Critical Issues
None.

## High Priority
None.

## Medium
| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **The new title setting is read from a cached tenant that is never cleared on save.** `seo_product_title` is stored in the tenant's `data` JSON, and the public site reads it from the resolver cache. `Events\TenantSaved` has no listeners and `EditTenant` has no `afterSave` hook. | `app/Providers/TenancyServiceProvider.php:25` (`TenantSaved => []`), `:59-60` (TTL 3600); `app/Filament/Resources/TenantResource/Pages/EditTenant.php`; read at `app/Support/SeoSchema.php:248` | When the owner switches the "Product page title" Select in Filament, product pages and the `openProduct()` head update keep the old title for **up to 60 minutes per domain**. A deploy clears it, because `optimize:clear` runs `cache:clear` (`vendor/laravel/framework/src/Illuminate/Foundation/Console/OptimizeClearCommand.php:63`), which hides the problem during release checks. Checking the page right after flipping the setting will look like the feature is broken. The same delay applies to every tenant field (brand name, colors, title suffix). This is a stale-cache problem, not a speed cost. | Clear the resolver cache on save. In `TenancyServiceProvider::events()`: `Events\TenantSaved::class => [fn (Events\TenantSaved $e) => app(\Stancl\Tenancy\Resolvers\DomainTenantResolver::class)->invalidateCache($e->tenant)]`. `bootEvents()` passes listeners to `Event::listen`, which accepts closures. Cost: 1 `domains` query plus one cache delete per domain, per admin save. Add a test: save a tenant, then `Cache::has()` on the resolver key returns false. |

## Low
| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **The eager-load requirement is not documented, and no query-count test protects it.** `forSelectedProduct()` now calls `editorialScore()` unconditionally (line 245), including for tenants that keep the default title and for products with no `ai_summary`. Its docblock does not say which relations must be loaded, and lazy loading is never blocked (`grep preventLazyLoading` in `app/`, `bootstrap/`, `config/` finds nothing). | `app/Support/SeoSchema.php:233-245` | Today: 0 extra queries, because all callers comply. A future caller passing a bare `Product` (for example a new controller or job) would silently add 2-3 lazy queries (`category`, `category.features`, `featureValues`), and more if offers and brand are also missing. | Add one docblock line: "Requires `brand`, `offers.store`, `featureValues`, `category.features` loaded (see `ProductCompare::selectedProduct()`)". Optionally add a test: with the eager-loaded product, wrap `forSelectedProduct()` in `DB::enableQueryLog()` and assert ≤ 1 query (the `category.parent` breadcrumb). |
| **`forSelectedProduct()` runs twice on the `openProduct()` round-trip.** | `app/Livewire/ProductCompare.php:439` and `:740` | 0 queries. The second run repeats `strip_tags`/`Str::limit` over `ai_summary` three times, 3-4 `route()` calls, and one `editorialScore()`: well under 0.1 ms. The `render()` copy is thrown away on that request (see the first item in the next section). | No action on its own. It goes away for free if the first item below is fixed. |

## Older problems on the same path (not caused by 959ec30)
These sit on the code path audited above. They predate this commit and are listed so they are not mistaken for regressions.

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **Medium: `render()` builds the full SEO payload on every Livewire round-trip, and Livewire throws it away.** Layout data is only used when the component is served as a full page (`vendor/livewire/livewire/src/Features/SupportPageComponents/HandlesPageComponents.php:14`). On `/livewire/update` it is merged into a config nobody reads (`SupportPageComponents.php:23-29`). The payload includes `schemaProducts()`, whose only use is that call (`grep` finds no other use). | `app/Livewire/ProductCompare.php:740-748`, `:763-774`; `schemaProducts()` at `:378-411` | On every slider change, reveal, compare toggle and modal open: 3 queries (`products whereIn` up to `displayLimit` ids, `brands`, `product_offers`), hydration of up to 12 products and their offers, the whole `forCategoryPage()` or `forSelectedProduct()` build, and JSON encoding. Estimated (not measured) at ~1-3 ms per round-trip, on the slider hot path. | Skip the SEO build on update requests: `$seo = \Livewire\Livewire::isLivewireRequest() ? null : SeoSchema::forCategoryPage(...)`, and call `->layoutData([...])` only when `$seo` is set. `isLivewireRequest()` checks the `X-Livewire` header (`HandleRequests.php:93-96`). Run the `Livewire::test()` suites afterwards, in case a test asserts SEO data after `->call()`. |
| **Low: `schemaProducts()` runs on product pages even though its result is ignored.** PHP evaluates the argument before `forCategoryPage()` returns early into the selected-product branch. | `app/Livewire/ProductCompare.php:746`; `app/Support/SeoSchema.php:46-47` | 3 unneeded queries on every server-rendered `/product/{slug}` view, which is the main organic and Googlebot entry point. Estimated at ~1-3 ms. | `$this->selectedProduct ? collect() : $this->schemaProducts()` at line 746. This covers the full page load, which the fix above does not. |

## Caching Recommendations
| Data | Current | Recommended TTL | Expected Gain |
|------|---------|-----------------|---------------|
| Tenant model (resolver cache), which now carries `seo_product_title` | 3600 s, never cleared on save | Keep 3600 s and **clear it on `TenantSaved`** | Setting changes go live at once instead of after ≤ 60 min. No change in request cost. |
| `forSelectedProduct()` output / `editorialScore()` | not cached | Do not cache | None. In-memory work is cheaper than a Redis round-trip. |

## Index Recommendations
None. This commit adds no query, and the eager loads it relies on already use indexes (`product_feature_values.product_id`, `features.category_id`).

---

## Lead verification (2026-10-28) — the tenant-cache finding is NOT a defect

The "setting change shows up to 60 minutes late; nothing clears the tenant cache on save" finding is wrong.
`App\Models\Tenant` extends `Stancl\Tenancy\Database\Models\Tenant`, which uses
`Concerns\InvalidatesResolverCache` (`vendor/stancl/tenancy/src/Database/Models/Tenant.php:29`): its `saved` hook calls
`invalidateCache()` on the Domain, Path and RequestData resolvers. Observed on prod the same day: pw2d's
`seo_product_title` was set via a model `save()` and the next curl of a product page already showed the new title.
No `TenantSaved` listener is needed.
