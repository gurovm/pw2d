# Review: Spec 042 Phase B, per-tenant product page title (commit 959ec30)
**Date:** 2026-10-28
**Status:** Approved with comments

**Scope:** `app/Support/SeoSchema.php` (`forSelectedProduct()`), `app/Filament/Resources/TenantResource.php` (Select `seo_product_title`), `app/Support/ProductNameShaper.php` (docblock), `tests/Feature/Seo/SeoSchemaTest.php` (5 new tests), and every path the title takes to the page.

**Method:** Code reading only. This review had no shell, so `git show 959ec30` could not be run. 959ec30 is HEAD and the working tree is clean for `app/` and `tests/`, so the files read are the commit's state. Tests were not run. No production data was queried. Findings that depend on production data say so.

**Counts:** Critical 0 · High 0 · Medium 2 · Low 6

---

## Verdict against the intent

The rule works as specified. A product gets `"{name} Review: {score, 1 decimal}/10 — {category}"` only when `tenant('seo_product_title') === 'review_score'`, `editorialScore()` is non-null, and a category exists (`SeoSchema.php:247-254`). In every other case the old template is used, word for word (arms 2 and 3). One `$score` (`:245`) feeds both the title (`:249`) and `review.reviewRating.ratingValue` (`:299-305`), so those two cannot disagree.

| Case | What the code does | Result | Matches intent |
|---|---|---|---|
| Score 0.0 | Strict `$score !== null` (`SeoSchema.php:248`), so 0.0 passes | `Review: 0.0/10`; page `0.0 / 10` (`product-compare.blade.php:613-617`); ratingValue 0.0 | Yes. Reachable only through a manual Filament entry, because the AI path writes scores > 0 only (`FinalizeProductEvaluation.php:275`) |
| Score 10.0 | `number_format(10.0, 1)` | `10.0/10`; ratingValue 10.0 = bestRating | Yes |
| Whole score 7.0 | `number_format(7.0, 1)` | `7.0/10`; page `7.0 / 10`; JSON `7.0` | Yes, pinned by a test |
| Null category | `editorialScore()` returns null (`Product.php:111-115`), so arm 3 | `{name} — AI Review & Match Score` | Yes. Server-rendered product pages already return 404 with no category (`ProductCompare.php:463-465`), so only the modal can reach this |
| One feature unscored | Score null, so arm 2 | Old template | Yes, tested |
| Key absent (c2d) | `tenant()` returns null, so arm 2 | Old template | Yes, tested |
| Key = `'default'` (written by Filament Create) or `null` (written by Filament Edit, see Low 1) | `!== 'review_score'`, so arm 2 | Old template | Yes, not tested |
| Rounding | Title and markup share `$score`. The page calls `editorialScore()` on the same memoized `selectedProduct` instance (`blade:611`) and prints it with the same `number_format(…, 1)` (`blade:617`) | Same number in all three | Yes |

## Both delivery paths behave the same

1. **Server-rendered head.** `GET /product/{slug}` → `ProductCompare::mount()` sets `selectedProductSlug` (`ProductCompare.php:466`) → `render()` → `SeoSchema::forCategoryPage()` (`:740-748`) → `forSelectedProduct()` (`SeoSchema.php:46-47`) → `layoutData['metaTitle']` (`ProductCompare.php:764`) → `<title>` (`components/layouts/app.blade.php:9`), og:title and twitter:title plus their `data-default` (`:15`, `:24`, `:34`).
2. **JS head update.** `wire:click="openProduct(...)"` (`product-compare.blade.php:260/267/287`, `similar-products.blade.php:12/19/40`) → `ProductCompare::openProduct()` → `forSelectedProduct($this->selectedProduct)` (`ProductCompare.php:439`) → `meta:product-opened` with `title: $seo['title']` (`:441-446`) → the listener sets og:title, twitter:title and `document.title` (`app.blade.php:197-213`).

Both paths pass the same `#[Computed] selectedProduct` (`ProductCompare.php:113-118`, which eager-loads `brand`, `featureValues.feature`, `offers.store`, `category.features`). Both run under the same tenant: `InitializeTenancyIfApplicable` sits on the `web` group (`bootstrap/app.php:15-17`), and that group also covers `/livewire/update`. Blade escapes the string in the head. JS assigns it to `document.title` as plain text. A grep finds no other code that builds a product page title. **Result: the two paths output the same string.**

---

## Critical Issues (must fix)
None.

## High
None.

## Medium

### 1. Changing the setting takes up to 60 minutes to reach the site, and so does changing it back
- **Where:** `app/Providers/TenancyServiceProvider.php:25` (`Events\TenantSaved::class => []`) and `:59-60` (`$shouldCache = true`, TTL 3600). The resolver returns the whole cached `Tenant`, `data` included (`vendor/stancl/tenancy/src/Resolvers/Contracts/CachedTenantResolver.php:37-46`). The setting is read at `app/Support/SeoSchema.php:248`.
- **Scenario:** The owner sets pw2d back to "default" in Filament to end the experiment. Every pw2d.com request still resolves the tenant from the cache entry written before the save, so `tenant('seo_product_title')` keeps returning `'review_score'`. Product pages, and the `openProduct()` head update, keep the score title for up to an hour. A curl right after the save shows the old title. A deploy hides the problem, because `optimize:clear` empties the cache. Every tenant field is affected, but this key is the first one used as an on/off switch for a measured experiment.
- **Fix:** In `TenancyServiceProvider::events()`, add `Events\TenantSaved::class => [fn (Events\TenantSaved $e) => app(\Stancl\Tenancy\Resolvers\DomainTenantResolver::class)->invalidateCache($e->tenant)]`. Add a test that saves a tenant and asserts the resolver cache key is gone. (The performance audit of the same date, `docs/performance/audit-2026-10-28-performance.md`, reports the same issue.)

### 2. Neither delivery path is tested with the new template, and no test checks the "same number" rule end to end
- **Where:** `tests/Feature/Seo/SeoSchemaTest.php:320-374`. All five new tests call `SeoSchema::forSelectedProduct()` directly. The render test at `:416-439` runs without `review_score` and does not read `<title>`. `tests/Feature/Seo/SeoBrandBleedTest.php:153-171` checks the event title only for the absence of "pw2d".
- **Scenario:** `openProduct()` once built its own title string. That is the brand-bleed bug documented at `SeoBrandBleedTest.php:146-152`. If that happens again, or `render()` stops passing `$seo['title']` to `layoutData`, every test still passes. One path would then show `Review: 6.8/10` and the other the old template, or a number different from `reviewRating`. The spec's own check of this rule ("title score = reviewRating 4.9") was a manual curl on prod.
- **Fix:** Add two tests, both with `useReviewScoreTitle()`:
  - An HTTP test on the product page that extracts the `<title>` number, the visible "Acme Shop score: X / 10", and the JSON-LD `ratingValue`, and asserts all three are equal.
  - `Livewire::test(ProductCompare::class, ['slug' => …])->call('openProduct', $slug)->assertDispatched('meta:product-opened', fn ($e, $p) => str_contains($p['title'], 'Review: 6.8/10'))`.

## Low

### 1. On the Filament Edit form, the Select is blank for tenants that never had the key
- **Where:** `app/Filament/Resources/TenantResource.php:102-110` (`->default('default')`). Filament applies defaults only when the form is filled without a record (`vendor/filament/forms/src/Concerns/HasState.php:185-201`; `Components/Concerns/HasState.php:249-260` returns early on edit).
- **Scenario:** The owner opens c2d's edit page and sees "Select an option" instead of the default. Saving c2d for any other reason writes `seo_product_title = null` into `data`. The strict compare reads null as default, so the site output stays correct. Only the form is misleading. The tenants table (`:164-205`) also has no column showing which tenant uses which template.
- **Fix:** Add `->formatStateUsing(fn (?string $state) => $state ?? 'default')` or a descriptive `->placeholder(...)`, and optionally `->selectablePlaceholder(false)` and a toggleable table column.

### 2. `'review_score'` is a magic string in two places, and nothing links them
- **Where:** `TenantResource.php:107` and `SeoSchema.php:248` (the tests write it directly at `SeoSchemaTest.php:316`).
- **Scenario:** Someone renames the option key in the Select, for example to `'score'`. The owner picks it, the form saves it, and `SeoSchema` falls back to the old template on every page with no error. All tests still pass, because they write `'review_score'` directly.
- **Fix:** Use a backed enum (for example `ProductTitleTemplate::ReviewScore`) or a class constant, shared by the Select options and the comparison.

### 3. Scores above 10 are not bounded (this predates the commit; the title now shows the number too)
- **Where:**
  - `Product.php:128` has no upper bound.
  - `RescanProductFeatures.php:83-91` passes the raw AI response to `applyFeatureScores()`, which only checks `$score > 0` (`FinalizeProductEvaluation.php:272-279`).
  - The 0-100 check at `ProductEvaluation.php:284` runs on the Bouncer path only.
  - The Filament feature-value field has no min or max (`FeatureValuesRelationManager.php:44-49`).
- **Scenario:** A rescan returns 120 for one feature of a product whose other features score 95 or higher. The mean goes above 100, so the title reads `… Review: 10.4/10 — …` and the markup carries `ratingValue` 10.4 with `bestRating` 10, an invalid Rating.
- **Fix:** Clamp or validate at write time: reuse the `ProductEvaluation` score check on the rescan path, and add `->minValue(0)->maxValue(100)` in Filament.

### 4. The title says "Review" for products that have no review text (the spec chose this)
- **Where:** The title checks only the score (`SeoSchema.php:247-249`). `review` and `reviewRating` need `ai_summary` (`:289`), and so does the visible "The Verdict" block (`product-compare.blade.php:539`).
- **Scenario:** A fully scored pw2d product with an empty `ai_summary` gets `X Review: 6.8/10 — Category`. The page shows the score line but no review text, and the Product markup has no `review`, `offers` or `aggregateRating`. The number still matches the page. The spec decided this on purpose ("The title gates on the score only"), so it is listed for awareness only. The number of such products on prod was not checked.

### 5. Products filed directly on a category that has subcategories (this predates the commit; depends on data)
- **Where:** `mount()` keeps the product's own category (`ProductCompare.php:469`, `:476`). The product modal, and with it the visible score line, is inside the leaf-only `@else` (`product-compare.blade.php:2`, `:49`, `:402-718`, `:751`). The head still comes from `forSelectedProduct()` (`SeoSchema.php:46-47`).
- **Scenario:** A leaf category with scored products gets a subcategory added and keeps its features. Its product pages then render the subcategory grid while `<title>` says `X Review: 6.8/10 — Category` and the markup carries `reviewRating` 6.8. Nothing on the page shows the score. `reviewRating` already had this problem. The title now has it too. It needs a category with both children and features, and prod was not checked for one.

### 6. Test hygiene
- The five new tests use `/** @test */` doc-comment metadata on method names that do not start with `test` (`SeoSchemaTest.php:320`, `:331`, `:343`, `:354`, `:363`). PHPUnit 11.5.55 (`composer.lock:9831-9832`) reports this as deprecated. PHPUnit 12 drops it, and these tests would stop being discovered while the suite stays green. The file already uses this style (`:376-451`). Fix the whole file at once with `#[Test]` or a `test_` prefix.
- The no-category test (`:363-374`) cannot fail on its own. With no category, `editorialScore()` already returns null (`Product.php:111-115`), so the `$category &&` guard at `SeoSchema.php:248` never decides the outcome. Keep the test, but it does not cover the guard.
- Not covered: the key set to `'default'` (what `CreateTenant` writes), titles at 0.0 and 10.0, and two tenants in one test to prove isolation.

---

## Checklist by area
- **Multi-tenancy:** No new model, table or migration. The key lives in `tenants.data` through VirtualColumn (`Tenant.php:22-25`), like the other `seo_*` keys. It is read only from the current tenant through `tenant()`, and I found no path that leaks across tenants. The one issue is the cache delay (Medium 1).
- **Laravel conventions:** The logic stays in the `SeoSchema` support class. No controller or component changed. The Filament field follows the existing pattern.
- **N+1 / repeated queries:** None added. `editorialScore()` now runs on every call (`SeoSchema.php:245`), but it reads relations that are already eager-loaded, so it adds 0 queries. `tenant()` is an in-memory read, and the `&&` order means it is only evaluated when a score exists.
- **Dynamic branding:** No UI or colour change. The title carries no brand. Nothing to report.
- **Security:** The title is Blade-escaped in the head and JSON-encoded in the Livewire dispatch. The only new input is an admin-only Select.

## Praise
- The score is computed once and reused for the title and `reviewRating`, so the code itself guarantees the two match. No test is needed to keep them in step.
- The strict `!== null` handles 0.0 correctly. `number_format(…, 1)` matches the page, and a test pins the 7.0 case.
- The old templates are kept word for word. c2d is unaffected, and `default_title_is_used_when_template_key_is_absent` pins that.
- The tenant switch uses the existing `tenants.data` pattern with no migration. The tests map one-to-one to the spec's five cases and follow the 2026-04-11 tenant re-fetch lesson.
- The `ProductNameShaper` docblock was updated in the same commit.

---

## Lead verification (2026-10-28) — the tenant-cache finding is NOT a defect

The "setting change shows up to 60 minutes late; nothing clears the tenant cache on save" finding is wrong.
`App\Models\Tenant` extends `Stancl\Tenancy\Database\Models\Tenant`, which uses
`Concerns\InvalidatesResolverCache` (`vendor/stancl/tenancy/src/Database/Models/Tenant.php:29`): its `saved` hook calls
`invalidateCache()` on the Domain, Path and RequestData resolvers. Observed on prod the same day: pw2d's
`seo_product_title` was set via a model `save()` and the next curl of a product page already showed the new title.
No `TenantSaved` listener is needed.
