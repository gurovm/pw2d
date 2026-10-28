# Security Audit: Spec 042 Phase B — per-tenant product title template (commit 959ec30)
**Date:** 2026-10-28
**Scope:** `app/Support/SeoSchema.php` (`forSelectedProduct()` title), `app/Filament/Resources/TenantResource.php` (new `seo_product_title` Select), `app/Support/ProductNameShaper.php` (docblock only), `tests/Feature/Seo/SeoSchemaTest.php`. I traced every place the title is rendered, plus the tenant-resolution path for the product page and for `/livewire/update`.
**Method:** I read the code statically at HEAD (= 959ec30 for `app/` and `tests/`). Nothing was executed and production was not touched.

**Result:** 0 critical, 0 high, 0 medium, 2 low. The new title cannot cause XSS, and one tenant's setting cannot change another tenant's titles. Both low findings are defence-in-depth or pre-date this commit.

## Critical (fix immediately)
None.

## High (fix before release)
None.

## Medium (fix soon)
None.

## Low

| # | Issue | Location | Fix |
|---|-------|----------|-----|
| L1 | **The server does not limit `seo_product_title` to the two allowed values.** In Filament 3.3.50, a Select with static options gets only the implicit `nullable` rule (`vendor/filament/forms/src/Components/Concerns/CanBeValidated.php:644`). The only rule `Select` adds itself is `exists`, and only for relationship selects (`vendor/filament/forms/src/Components/Select.php:970-991`). So a panel user who edits the Livewire state (`data.seo_product_title`) can save any string or array into `tenants.data`. **Impact today: none.** `SeoSchema.php:248` uses strict `=== 'review_score'`, so any other value gets the default template, and the key is not rendered anywhere else. **Failure scenario:** a later refactor reads it loosely (`in_array()` without strict, a `str_contains`, or a `match` that treats "anything non-default" as on), and a junk value starts changing titles on a live tenant. It also needs panel access, which already allows larger edits. | `app/Filament/Resources/TenantResource.php:102-110` | Add a server-side allow-list and stop the placeholder from being selectable (see code below). Add a test that saves `'REVIEW_SCORE'` / `['x']` and asserts the default title. |
| L2 | **Pre-dates this commit, outside the diff: `/product/{product:slug}` runs its model lookup before tenancy is initialized, so the lookup is not tenant-scoped.** `bootstrap/app.php:15-17` appends `InitializeTenancyIfApplicable` to the `web` group after `SubstituteBindings` (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:491`). It is not in the priority list (`TenancyServiceProvider.php:89-99` only prioritises stancl's own middleware), so the order stays as is. Livewire registers `substituteImplicitBindingsUsing` (`vendor/livewire/.../SupportPageComponents.php:206-215`), so the `Product` binding resolves inside `SubstituteBindings`. stancl's `TenantScope` does nothing while tenancy is not initialized (`vendor/stancl/tenancy/src/Database/TenantScope.php:16`). Laravel skips parameters that are already bound when it re-binds before `mount()` (`ImplicitRouteBinding.php:36`), so the unscoped model reaches `ProductCompare::mount()`. Product slugs are unique per `(tenant_id, slug)` only (`2026_03_21_120000_add_tenant_id_to_core_tables.php:47-48`). **Scenario:** on tenant A's domain, `GET /product/<slug that exists only in tenant B>` resolves B's product. `mount()` then returns 410 if B's copy is `is_ignored` (`ProductCompare.php:460-461`). Otherwise the scoped lazy-load of `$product->category` returns null and the request gets 404 (`:463-464`). **None of B's data reaches the page or the title:** SEO uses the `selectedProduct` computed property, which runs its query again after tenancy starts (`ProductCompare.php:113-117`). What remains: (a) a 410-vs-404 signal that a slug exists, ignored, in another tenant; (b) if two tenants ever share a slug, a real product page can return 404/410. (b) is unlikely because slugs get a random 5-character suffix (`FinalizeProductEvaluation.php:170`, `OfferIngestionService.php:275`). The tests hide this because `SeoSchemaTest::setUp()` (`:39`) initializes tenancy before every request. | `bootstrap/app.php:14-18` | Initialize tenancy before route bindings (see code below). Add a feature test that does **not** call `tenancy()->initialize()` in `setUp()`. It should create two tenants with domains, give both the same product slug, request `http://<tenant-a-domain>/product/<slug>`, and assert 200 with tenant A's product name in `<title>`. |

### L1 fix
```php
// app/Filament/Resources/TenantResource.php
Forms\Components\Select::make('seo_product_title')
    ->label('Product page title')
    ->helperText('Title template for product pages. The score version needs every category feature scored; otherwise the default is used.')
    ->options([
        'default'      => 'Name + category — AI Review & Match Score',
        'review_score' => 'Name Review: score/10 — category',
    ])
    ->in(['default', 'review_score'])   // server-side allow-list; null still passes via `nullable`
    ->selectablePlaceholder(false)
    ->default('default')
    ->native(false),
```

### L2 fix
```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        \App\Http\Middleware\InitializeTenancyIfApplicable::class,
    ]);

    // Route-model bindings (incl. Livewire page-component bindings such as
    // /product/{product:slug}) resolve inside SubstituteBindings. Tenancy must
    // already be initialized there, or BelongsToTenant's scope is a no-op.
    $middleware->prependToPriorityList(
        before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
        prepend: \App\Http\Middleware\InitializeTenancyIfApplicable::class,
    );
})
```

## Low / Informational
- **Tests:** the new tests cover template selection, but none checks that a hostile name is escaped under the `review_score` template. The current sinks are safe (see below), but a regression test would protect them. Suggested: a product named `</title><script>alert(1)</script>` with category `"><img src=x onerror=alert(1)>`, `review_score` on and all features scored. GET the product page and assert that neither raw string appears, and that `&lt;/title&gt;` does.
- **Admin authorization (pre-existing, by design):** `TenantResource` has no policy. Filament allows every action when no policy exists, and `User::canAccessTenant()` returns `true` (`app/Models/User.php:51-54`). So every panel user can create, edit and delete every tenant, including this setting. That is fine for one operator. If a second panel account is ever added, add a `TenantPolicy`.
- **Setting changes apply immediately and stay per-domain:** the `DomainTenantResolver` cache (TTL 3600s, `TenancyServiceProvider.php:59-60`) is keyed by domain and cleared when a Tenant is saved (`vendor/stancl/tenancy/src/Database/Concerns/InvalidatesResolverCache.php:30`). Switching a tenant back to `default` takes effect on the next request.

## Passed Checks
- **Server-rendered `<title>`:** `resources/views/components/layouts/app.blade.php:9` prints the title with `{{ }}`. Product and category names are HTML-escaped.
- **og:title / twitter:title:** the same file, `:15`, `:24` and `:34-35`, prints `content` and `data-default` with `{{ }}` inside double-quoted attributes, so quotes are escaped and the attribute cannot be broken out of.
- **JSON-LD:** the composed title is not put into JSON-LD. The product and category names that do go there (Product `name`, BreadcrumbList `name`) are encoded by `SeoSchema::encodeSchemasForScriptTag()` (`SeoSchema.php:604-613`, `JSON_HEX_TAG|AMP|APOS|QUOT`), which `ProductCompare.php:773` uses. The layout's `{!! $schemaJson !!}` (`app.blade.php:43`) only ever receives that encoded output.
- **Livewire / JS path:** `ProductCompare::openProduct()` (`ProductCompare.php:430-448`) dispatches `meta:product-opened` with the same `forSelectedProduct()` payload. Livewire sends dispatch params as JSON in the `/livewire/update` response; they never go through the HTML parser. The handler (`app.blade.php:197-213`) only uses `setAttribute('content', …)` on `<meta>` tags and `document.title = …`, and both treat the value as plain text. The close handler (`:216-229`) restores values from `dataset.default` through the same safe sinks. `openProduct()` is never called during mount, so the payload is never embedded in the initial `wire:effects` HTML.
- **No raw HTML sinks for the title:** `resources/` has no `innerHTML`, `insertAdjacentHTML`, `outerHTML` or `x-html`. None of the `{!! !!}` uses in views touches `$metaTitle` or the SEO payload.
- **Score:** `Product::editorialScore()` returns `?float` (`app/Models/Product.php:109-129`; no division by zero thanks to the `$ids === []` guard). `number_format($score, 1)` produces digits and `.` only. Nothing can be injected through the score.
- **Isolation of the setting:** `tenant('seo_product_title')` reads the tenant initialized for the current request. `/livewire/update` uses the `web` middleware group (`vendor/livewire/.../HandleRequests.php:22-23`), so `openProduct()` runs under the tenant resolved from the Host header. `forSelectedProduct()` is only called from `ProductCompare` (no jobs or console commands carrying over a stale tenancy). Product pages have no full-page or response cache. Tenant `data` is not serialized into any public view or API response. On a central host with no tenant, `tenant()` returns null and the default title is used, which is the safe fallback.
- **Product used for the title is tenant-scoped:** the SEO payload is built from the `selectedProduct` computed property (`ProductCompare.php:113-117`). It eager-loads `brand`, `featureValues.feature`, `offers.store`, `category.features` and runs after tenancy is initialized, so the title, score and breadcrumbs only use the current tenant's product, category and features.
- **Unexpected stored values:** a missing key, `null`, any other string or an array all fail the strict `=== 'review_score'` check (`SeoSchema.php:248`) and give the default template. Nothing crashes and nothing is reflected.
- **Admin panel gate:** `TenantResource` is only reachable through the Filament `admin` panel (`Authenticate` auth middleware, CSRF in the panel stack). `canAccessPanel()` requires an `@pw2d.com` email (`app/Models/User.php:37-40`). The panel enables `login()` and `profile()` only, with no `registration()` (`AdminPanelProvider.php:32-33`). There are no registration routes and no `UserResource`, so accounts can only be created out-of-band.
- **Mass assignment:** the new key goes into the tenant `data` JSON via VirtualColumn (`Tenant::getCustomColumns()` = `id`, `name`; `app/Models/Tenant.php:22-25`). stancl's base Tenant `$guarded = []` is by design (exempt per the audit checklist). The diff adds no new model or `$fillable` change.
- **`ProductNameShaper.php`:** docblock-only change; no code changed.
