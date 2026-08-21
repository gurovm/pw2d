# Review: Specs 032–035 post-deploy audit

**Date:** 2026-08-21
**Scope:** Spec 032 (category health), 033 (offer_id rescan targeting), 034 (pick diversity), 035 (re-home integrity)
**Status:** Approved with comments — **no CRITICAL defect found**, but 4 HIGH findings, two of which are live-page-visible today.

Method note: shell access was unavailable, so this audit was done by reading current sources + tests
rather than `git diff`. Every finding below is stated against current `HEAD` file:line.

---

## Answers to the five questions asked

### 1. Spec 035 blast radius — every path that saves a Product with a changed `category_id`

**Your CREATE assumption is correct.** Independently confirmed from Eloquent's contract, not from the
tests: `Model::save()` routes a non-existing model to `performInsert()`, which sets `exists`/
`wasRecentlyCreated` and fires `created`/`saved` but **never calls `syncChanges()`**. `$model->changes`
is populated *only* in `performUpdate()`. `wasChanged()` reads `getChanges()`. Therefore inside
`saved()` on an insert, `wasChanged('category_id')` is `false` for every attribute, always — so
`Product::create([... 'category_id' => X])` in `BatchImportController:181`,
`OfferIngestionService:264`, `ProductImportController:170` and `ListProducts:121` fires nothing.
One caveat worth knowing: this is only true for a *fresh* insert. `updateOrCreate()`/`firstOrCreate()`
that resolve to an **existing** row and change `category_id` take the update path and *do* fire.

Complete list of paths that now trigger delete + (conditionally) a Gemini job:

| # | Path | Loop? | Per-item cost | Verdict |
|---|---|---|---|---|
| 1 | `AiSweepCategory.php:97-98` (→ null) | **Yes** — `chunkById(25)` over a 100–200 product category | 1 `DELETE` (all values) + 1 `LandingPage` SELECT + ≤1 audit job. **No Gemini** (null category ⇒ no rescan) | Intended; see MEDIUM-1 for the query cost |
| 2 | `AiAssignCategories.php:115-116` (→ new id) | **Yes** — `chunkById(10)` | 1 `DELETE` (whereHas) + 1 `LandingPage` SELECT + ≤1 audit job + **1 Gemini `RescanProductFeatures`** | Gemini cost is *not* new — the pre-035 command already dispatched it at old line 106. Net-neutral. |
| 3 | `ProcessPendingProduct.php:153` (→ null, category-rejection path) | Effectively — one job per imported/retried product; `ListProducts.php:44-47` "Retry Failed" dispatches these in a bulk loop | 1 `DELETE` of **ALL** feature values + 1 audit job | **Behaviour change, see MEDIUM-2** |
| 4 | Filament `ProductResource` edit form (`ProductResource.php:57`, `Select::make('category_id')`) | No | delete + Gemini rescan | Intended (this is the path Spec 035 says already worked) |

Paths that do **not** fire and are safe: `BatchImportController:145` (`$existing->update($productUpdates)`
never contains `category_id`, and `$existingProducts` is already scoped to `where('category_id',
$category->id)` at line 47 — no re-home possible); `PriceTierRecalculator:66`; `RescanProductFeatures:61`;
`SyncOfferPrices:80` — all change `price_tier` only, so `saved()` short-circuits on two
`wasChanged()` calls.

**No path fires this in an unbounded loop over many products with a Gemini job attached.** The one
loop that dispatches Gemini (#2) already did so before Spec 035.

### 2. Spec 032 tenant scoping — is the `checkedAtSubquery()` no-`tenant_id` assumption safe?

**Yes, on every current caller, and for a stronger reason than the docblock gives.** The docblock
argues "a product's `category_id` FK pins one specific, already tenant-scoped category row". That
argument is load-bearing and holds because `categories.id` is a **globally unique auto-increment PK in
a single database** — `whereColumn('products.category_id', 'categories.id')`
(`AssessCategoryHealth.php:98`) can therefore only ever match products genuinely attached to *that*
row. The same pin protects the three `withCount` closures at lines 63-70, which go through the
`products` hasMany. Both halves of the query use the same pin, so they cannot disagree.

The three ways it could break, all checked and clear:

- A product row whose `category_id` points at another tenant's category. Every write path is
  tenant-constrained: `BatchImportRequest:45`, `ProductImportRequest:32` and
  `OfferIngestionController:44` all use `Rule::exists('categories','id')->where('tenant_id', tenant('id'))`;
  `AiService::assignCategories()` re-validates the AI's answer against `$validCategoryIds`
  (`AiService.php:473`), which came from a tenant-scoped `Category::doesntHave('children')` query; the
  Filament select is relationship-scoped. No unscoped write path exists.
- SQL ambiguity from the `join('product_offers', ...)` at line 97 — `product_offers` has no `status`
  and no `is_ignored` column (`2026_03_26_000001_create_product_offers_table.php`), and stancl's
  `TenantScope` qualifies its predicate as `products.tenant_id`, so the unqualified `where('is_ignored')`
  / `whereNull('status')` at line 80 are unambiguous. (Had they not been, sqlite would have failed
  the suite too — so this is genuinely safe, not just untested.)
- The Filament path: `modifyQueryUsing` (`CategoryResource.php:136`) decorates an already
  tenant-scoped `Category` query, and `Product`'s own global scope applies through the `TenantSet`
  bridge. Whether stancl tenancy is initialized or not, categories and products are scoped
  **consistently**, so no mixed state is reachable.

**One real weakness, not a leak:** `execute(Tenant $tenant)` (`AssessCategoryHealth.php:43-50`) takes a
`Tenant` argument but depends on the caller having already initialized *that same* tenant. Pass a
different tenant than the initialized one and you get that tenant's categories with every count at
`0` and `oldest_check` null — i.e. the whole platform silently reported as `no_data`, no error. See
MEDIUM-4.

### 3. Spec 034 edge cases

- **`modelKey()` with null `brand_id`** — returns `null` at line 420 before tokenizing, so the pair
  falls to the similarity guard. Correct per spec. Consequence worth knowing: a null-brand product is
  also permanently exempt from the brand cap (`pickBrandAware`, line 242: `$p->brand_id === null || ...`),
  so a pool of unbranded products can produce 7 picks from one real-world brand.
- **Soft cap vs `MIN_PICKS`** — a monocultural category **does** reach 5, and I traced it rather than
  trusting the test: overall/budget/premium fill slots 1-3 while `$brandCounts[X] < 3`; from slot 4 on,
  `$underQuota` is null, `$overQuota` returns the best remaining candidate, and `$addPick` succeeds. The
  fill loop at 320-326 repeats to `MAX_PICKS`. `SelectLandingPagePicksTest::it_softly_exceeds_the_brand_cap_when_only_one_brand_is_viable_and_logs`
  asserts exactly this. The `Log::info` at 251 is accurate — `$underQuota`'s predicate is a strict
  subset of `$overQuota`'s, so "null under-quota + non-null over-quota" provably means *every*
  remaining eligible candidate is over quota.
- **Similarity fallback firing conditions** — correct, and correctly one-directional. Lines 165-174:
  both keys non-null ⇒ the key alone decides (`return true` on equal, `continue` on different, never
  falling through). The fallback at 176-196 is reachable only when at least one side is null. This is
  the shipped-bug fix and it is right.

**But `modelKey()` has a false-merge vector the tests do not cover — see HIGH-1.**

### 4. Spec 033 resolution precedence

Precedence is implemented as specified (`OfferIngestionService.php:335-371`): tenant-scoped
`offer_id` → store agreement → URL fallback with `orderBy('id')` and a multi-match warning; never a
hard fail. The `Rule::exists(...)->where('tenant_id', tenant('id'))` boundary at
`OfferIngestionController.php:54` is correct, and the route is behind `InitializeTenancyFromPayload`
(`routes/api.php:49-56`) which 422s on a missing `X-Tenant-Id`, so `tenant('id')` is never null and the
rule can never silently degrade to `whereNull('tenant_id')` (which is what `DatabaseRule::where()`
would do with a null value — worth knowing, since legacy offers can carry `tenant_id = NULL`).

**No path lets a caller target another tenant's offer.** One in-tenant gap: the service never checks
that the targeted offer's `url` agrees with the payload `url` — see MEDIUM-3. The URL fallback is
byte-identical for existing callers apart from `->first()` becoming `->get()->first()` with an explicit
`orderBy('id')`, which formalises the pre-existing lowest-id-wins behaviour rather than changing it.

### 5. Standard checks

Covered in the sections below: N+1 (MEDIUM-1, plus a positive), tenant scoping (question 2 — clean),
business logic in controllers / Form Requests (MEDIUM-5), `$fillable` (clean), hardcoded colors (clean —
Filament badge tokens only, no `#hex` anywhere in the four specs' surface), `strict_types` (LOW-3).

---

## HIGH

### HIGH-1 · `modelKey()` collapses distinct same-brand products whose first digit-bearing token is a size/quantity
`app/Actions/SelectLandingPagePicks.php:431-436`

```php
foreach ($tokens as $i => $token) {
    if (preg_match('/\d/', $token) === 1) { $candidateIndex = $i; break; }
}
```

"Take the FIRST candidate in name order" is correct for `Z10 ... Gen 1` and for trailing SKUs, but
the rule has no notion of *what kind* of number it found. Any name whose first digit-bearing token is a
capacity, a pack size, or a cup count produces a key made of that number.

Worked examples using this codebase's own `tokenize()` (splits on every non-alphanumeric run, so
decimals split):

| Name | Tokens | Key |
|---|---|---|
| `Bodum Chambord 8 Cup French Press` | bodum, chambord, 8, cup, … | `{brand}:8` (prev token `chambord` is 8 chars > `MODEL_TOKEN_MAX_JOIN_LEN`, so no join) |
| `Bodum Brazil 8 Cup French Press` | bodum, brazil, 8, cup, … | `{brand}:8` ← **same key, different product** |
| `COSORI Electric Gooseneck Kettle 0.8L` | …, kettle, `0`, `8l` | `{brand}:0` |
| `COSORI Original Gooseneck Kettle 0.9L` | …, kettle, `0`, `9l` | `{brand}:0` ← **same key** |

**Failure scenario.** Regenerate `/best/gooseneck-kettles` (already flagged `thin` at 34 buyable in
Spec 032's own production table) or `/best/cold-brew-makers` (36 buyable). Two genuinely different
same-brand models collide on key `{brand}:0` or `{brand}:8`; the second is silently rejected by
`$isDuplicateOfPicked` at line 169 and never appears. Because the key comparison is **authoritative
and short-circuits** (line 173 `continue`), no similarity check can rescue it and nothing is logged —
unlike the brand cap, variant rejection emits no `Log::info`. On a thin, brand-concentrated pool this
can push the run below `MIN_PICKS`, and then it escalates: `execute()` throws (line 328-335),
`AuditLandingPageFreshness::hasSelectionDrift()` catches the throw and returns `true`
(`AuditLandingPageFreshness.php:143-147`), so the page is stamped `selection_drift` on **every**
subsequent audit with no way to clear it, and `$page->update()` at line 78 busts the page + sitemap
cache each time the reason set flips.

Note the same class of miss in the other direction: Spec 034 claims the variant rule will help the
mics page, but `Shure SM58` → `sm58` and `Shure SM58S` → `sm58s` are different keys, so the SM58-family
case is only partly addressed. That is a claim to correct in the spec, not a bug.

Suggested fix (report only, not applied): require the candidate token to contain at least one letter,
**or** be ≥3 digits, **or** be preceded by a joinable qualifier — and skip a candidate whose immediately
*following* token is a unit/quantity word (`cup`, `oz`, `l`, `ml`, `quart`, `pack`, `count`, `piece`).
Then add a `Log::debug` when a candidate is rejected as a variant, so the next regeneration is
auditable instead of silent.

### HIGH-2 · Spec 035 fixed two of five mass-update sites; one of the survivors is in the same `foreach` it fixed
`app/Console/Commands/AiAssignCategories.php:126`
`app/Console/Commands/FlagConditionProducts.php:123`
`app/Filament/Pages/ProblemProducts.php:347`

Spec 035's finding is "`Builder::update()` silently skips observers, and this codebase leans on
observers for freshness, caching, and now feature integrity." Three `is_ignored` mass updates still do
exactly that, and `is_ignored` is one of only two triggers in `ProductObserver::saved()` (line 38).

```php
// AiAssignCategories.php:126 — eleven lines below the line Spec 035 fixed
Product::where('id', (int) $item['id'])->update(['is_ignored' => true]);
```

```php
// FlagConditionProducts.php:123
Product::whereIn('id', $ids)->update(['is_ignored' => true]);
```

```php
// ProblemProducts.php:347 — the BULK action
Product::whereIn('id', $records->pluck('id'))->update(['is_ignored' => true]);
```

The inconsistency is visible inside single files: `ProblemProducts.php:325` (single-row "Ignore"
action) uses `$record->update(...)` and fires the observer correctly; the bulk action fifteen lines
later does not. `ProductResource.php:265` does it correctly for its own bulk action.

**Failure scenario.** `php artisan pw2d:flag-condition-products {tenant} --ignore` hides N products,
one of which is a live pick on a published `/best/...` page. `AuditLandingPageFreshnessJob` is never
dispatched, so the page keeps rendering a product now hidden everywhere else on the site until the
nightly `pw2d:landing-pages:audit` catches it — precisely the "instant path is dead for the automated
route" defect Spec 035 exists to close. Same for an admin using ProblemProducts' bulk ignore, which is
the fastest route to hiding a batch of picks by hand.

**Also flag the todo backlog:** `docs/tasks/todo.md` item **Q11 ("Use bulk update for Mark as Ignored —
loops individual `$record->update()`. Use `whereIn()->update()`")** is now an instruction to
*reintroduce* this bug into `ProductResource.php:262-266`. It should be closed as WONTFIX with a
pointer to Spec 035, or the loop should be replaced with `whereIn()->update()` **plus** an explicit
`AuditLandingPageFreshnessJob::dispatchForProduct()` fan-out.

### HIGH-3 · `import_debt` is un-clearable for some products, so the Spec 032 verdict and its exit code can be permanently red
`app/Actions/AssessCategoryHealth.php:68-69` · `app/Support/CategoryHealthRow.php:80-82` ·
`app/Console/Commands/CategoriesHealthCommand.php:65-69` · `app/Services/ListingHealthService.php:85-87` ·
`chrome_extension/background.js:499`

`never_checked_count` counts pool products with **zero** health-stamped offers:

```php
'products as never_checked_count' => fn (Builder $q) => self::applyPoolQuery($q)
    ->whereDoesntHave('offers', fn (Builder $oq) => $oq->whereNotNull('health_checked_at')),
```

Two populations can never satisfy that predicate:

1. **Products with no offers at all.** `whereDoesntHave` is trivially true for them. These exist in
   this catalog — `ProblemProducts`' "No price" filter and `MergeDuplicateProducts`' offer re-pointing
   both produce them.
2. **Products whose every offer resolves to `condition: 'unknown'`.** `ListingHealthService::apply()`
   documents an explicit 029B-B3 "stamp-only" branch for `'unknown'` (line 104-114) — but
   `background.js:499` reads `if (product.condition && product.condition !== 'unknown')`, so the
   extension **never sends it**, `$condition === null` hits the early return at line 85-87, and
   `health_checked_at` is never written. The DOM cases that produce `'unknown'` are real:
   `content.js:154` sets it whenever the Amazon buy box did not load, and it is the initial value for
   any non-Amazon storefront page with no structured data.

**Failure scenario.** A published-page category contains one such product. `pw2d:categories:health`
reports `import_debt` on that category forever; the command exits `FAILURE` on every nightly cron run
(`CategoriesHealthCommand.php:65-69`); and per Spec 031's Tier-3 rule the operator is told "picks must
not be selected until cleared" for a category that can never clear. Spec 032's own production table
already shows this shape — "`import_debt`: semi-auto 1, gaming-keyboards 1, mics 1", three
single-product stragglers — which the spec reads as import debt but which may in fact be unstampable
rows. That distinction is the difference between "run the rescan" and "the rescan cannot help".

Two independent fixes are available and both look right: make `background.js` send
`condition: 'unknown'` so the existing stamp-only branch actually runs; and/or exclude
offer-less products from `never_checked` (they are a different problem, and `pool` already counts them).

### HIGH-4 · `ProcessPendingProduct`'s rejection path now destroys feature values on a hot pipeline path
`app/Jobs/ProcessPendingProduct.php:153` → `app/Observers/ProductObserver.php:72-75`

```php
$product->update(['category_id' => null, 'status' => null]);   // ProcessPendingProduct:153
// ...
if ($product->category_id === null) { $product->featureValues()->delete(); return; }   // observer
```

This path was not enumerated in Spec 035 (which names only the two AI commands and Filament), yet it
is the highest-frequency `category_id` writer in the system: every AI evaluation of a product carrying
an `ai_category_rejections` row lands here, and `ListProducts.php:44-47` ("Retry Failed") dispatches
these jobs in a bulk loop.

**Failure scenario.** An operator bulk-retries 50 failed products. Each one previously swept out of its
category is detached *and* has 100% of its `product_feature_values` deleted — including values for
categories it was never swept from — with no rescan queued (category is null, so the observer returns
at line 74). If the owner later re-homes any of them in Filament, the observer fires a
`RescanProductFeatures`, i.e. a full Gemini call per product to regenerate scores that existed 10
minutes earlier. This is arguably consistent with Spec 035's "a detached product's scores belong to no
category", but the spec never considered the cost on this path, and the deletion is irreversible.

Worth a deliberate decision: keep it (accept re-scoring cost on re-home), or narrow the null-category
branch to delete only values whose feature belongs to the category just left — which requires
capturing `getOriginal('category_id')` in the observer, currently unused.

---

## MEDIUM

### MEDIUM-1 · `dispatchForProduct()` runs one full landing-page query per re-homed product
`app/Jobs/AuditLandingPageFreshnessJob.php:70-79`, called from `ProductObserver.php:42`

```php
LandingPage::where('tenant_id', $product->tenant_id)->get(['id','picks'])->filter(...)
```

Loading every landing page and filtering in PHP is the *correct* design (the MySQL JSON-`LIKE` trap is
well documented at lines 57-65). The new cost is that Spec 035 put it inside two `foreach` loops that
previously ran zero queries. A 100-product sweep is now 100 `landing_pages` SELECTs plus 100 DELETEs
inside `chunkById(25)`. At 11 pages this is cheap in absolute terms, but it is a textbook N+1 and it is
invisible in the test suite, which sweeps 6 products.

`ShouldBeUnique` does its job on the *dispatch* side — verified at `AuditLandingPageFreshnessJob:31-48`
(`uniqueFor = 600`, `uniqueId()` = landing page id, globally unique in a single DB, so no tenant
qualifier is needed). One fidelity caveat on
`AiSweepCategoryCommandTest::sweeping_products_whose_pages_overlap_...`: it asserts *exactly* 2 jobs,
which holds only because `Queue::fake()` never processes jobs and therefore never releases the unique
lock. In production the lock is released by `CallQueuedHandler` as soon as each audit completes, so a
100-product sweep will produce more than 2 — still far fewer than 100, which is what Spec 035 asked
for, but the test proves a stronger guarantee than production provides. Consider
`ShouldBeUniqueUntilProcessing` being explicitly *rejected* here, and say so in a comment.

Also note each of those audits re-runs `SelectLandingPagePicks::execute()` in full
(`AuditLandingPageFreshness.php:144`) — a whole-category product load, scoring pass, and the O(picks ×
pool) duplicate scan. That is the real per-job cost, not the LandingPage query.

### MEDIUM-2 · `offer_id` is honoured without checking that the payload `url` matches the targeted row
`app/Services/OfferIngestionService.php:337-355`, then `:81-86`

Once `offer_id` resolves and the store agrees, the offer is refreshed with the payload's
`scraped_price`, `raw_title`, `image_url` and `stock_status` — but `url` is (correctly) not updated,
and no check exists that the payload URL describes the same listing.

The extension is safe: `background.js:472-477` sends `url: offer.url` straight from the rescan work
list, with a `CRITICAL:` comment saying so. But the endpoint is documented in
`docs/project_context.md` §5 as usable by "Chrome Extension **or external scraper**", and Spec 033
deliberately removed the URL from the matching criteria.

**Failure scenario.** Any non-extension client (or a future extension change that sends the tab's
post-redirect URL — Amazon routinely redirects a `/dp/ASIN` to a sibling variant) writes product X's
price, title and image onto offer Y, and `ListingHealthService` stamps it `health_checked_at` as
verified-clean. The result is an offer whose stored URL points at one listing and whose displayed
price/title describe another — a wrong price on a live comparison card, with a fresh health stamp
asserting it was checked. A one-line guard (mismatch ⇒ log + fall through to URL, exactly like the
store-mismatch branch already does at 347-351) closes it.

### MEDIUM-3 · Spec 032's "Unchecked" deep-link does not filter anything
`app/Filament/Resources/CategoryResource.php:171-177` (and the pre-existing copy at 195-201)

```php
->url(fn ($record) => ProductResource::getUrl('index', [
    'tableFilters' => ['categories' => ['values' => [$record->id]]],
]))
```

`ProductResource` declares `Tables\Filters\SelectFilter::make('category')` (singular, single-select →
state key `value`), not `categories`/`values` (`ProductResource.php:207-210`). Filament ignores an
unrecognised filter key, so the link lands on the **unfiltered** product list.

**Failure scenario.** Spec 032 §4 states the Unchecked column "links to the filtered product list" —
the one action the owner would take from that badge. Clicking `Unchecked: 3` on
`podcast-studio-mics` opens all ~1,200 products with no filter applied, and there is no error to
notice. This was pre-existing on the "Rows" column and was copy-pasted verbatim into the new column
(also a DRY miss — the identical closure now appears twice in one file).

### MEDIUM-4 · `AssessCategoryHealth::execute(Tenant)` silently returns zeros if the argument disagrees with the initialized tenant
`app/Actions/AssessCategoryHealth.php:43-50`

The method takes a `Tenant` but derives every count from `Product`'s global scope, which follows
`tenancy()->tenant`, not the argument. `CategoriesHealthCommand` gets this right by initializing inside
the loop, so nothing is broken today — but the signature actively invites the mistake, and the failure
is silent: categories from tenant B, counts from tenant A's scope, no row matches, every category
reports `pool = 0` ⇒ `no_data`.

**Failure scenario.** Someone adds a Filament widget or a `/admin` health panel that calls
`execute($someTenant)` on the central domain without initializing stancl tenancy. The panel renders
"no_data" for all 11 categories and looks like a data-loss incident. Cheapest fix: assert
`tenancy()->initialized && tenant('id') === $tenant->id` at the top of `execute()`, or initialize
internally in a `try/finally`.

### MEDIUM-5 · `OfferIngestionController` still validates inline, and the security-critical rule now lives there
`app/Http/Controllers/Api/OfferIngestionController.php:19-76`

Its two siblings have Form Requests (`BatchImportRequest`, `ProductImportRequest`) with the same
`Rule::exists(...)->where('tenant_id', ...)` pattern. This one carries 38 lines of inline rules plus a
pre-validation `$request->merge()` mutation at 27-29 — business logic in a controller by the letter of
`.claude/rules/standards.md`. Spec 033 added the `offer_id` rule (line 54) — described in the spec as
"the security boundary, not a convenience" — into that un-extracted block.

Not a defect; it's `docs/tasks/todo.md` **Q2**, still open. Worth re-prioritising now that a tenant
security boundary lives in the un-extracted file: an `OfferIngestionRequest` would make the rule
unit-testable in isolation rather than only through a full HTTP round trip.

---

## LOW

- **LOW-1 · NULLs sort first in `oldest_check ASC`.** `CategoriesHealthCommand` and
  `CategoryResource.php:137` both default-sort ascending, and MySQL/sqlite both place NULL first. A
  `no_data` or offer-less category therefore occupies row one of the "next category to sweep" queue —
  the exact misread Spec 032's "Why" section says the verdict column exists to prevent. Consider
  `ORDER BY oldest_check IS NULL, oldest_check ASC`.
- **LOW-2 · `$isDuplicateOfPicked` recomputes `modelKey()` and rescans `$products` on every candidate.**
  `SelectLandingPagePicks.php:153-200` does `$products->firstWhere('id', $pickedId)` (linear) plus a
  fresh `tokenize()` for each picked product, and `pickBrandAware` calls the closure up to twice per
  candidate before `$addPick` calls it a third time. In-memory only, no N+1 (`brand:id,name` is
  eager-loaded at line 93 — good catch by whoever wrote that), but on a 200-product pool it is roughly
  2M string ops per page. Memoise the keys into a `[product_id => key]` map before the pick loop.
- **LOW-3 · `CategoryResource.php` has no `declare(strict_types=1)`** while every other file touched by
  these four specs does. It is `docs/tasks/todo.md` L7's list; the file was edited by Spec 032 and the
  one-line addition was skipped.
- **LOW-4 · `modelKey()` degrades if `brand` fails to load but `brand_id` is set.** Line 457's
  brand-name exclusion uses `$product->brand?->name`; a null relation makes the brand token joinable, so
  `Jura Z10` keys as `juraz10` instead of `z10`. Both sides of a comparison come from the same
  eager-loaded collection so they degrade together — harmless today, but it means the key is not
  stable across call sites if a future caller loads products differently.
- **LOW-5 · `stale`/`aging` fire at fractional days.** `CategoryHealthRow.php:90-94` uses Carbon 3's
  `diffInDays()`, which returns a signed float — `> 30` trips at 30.01 days, not 31. Matches intent;
  noting it because the constant reads as a whole-day threshold.
- **LOW-6 · Doc drift.** `CLAUDE.md` says "Laravel 11 (PHP 8.3)"; `composer.json:13` pins
  `laravel/framework: ^12.0` and `docs/project_context.md` §8 says Laravel 12. Trivial, but it changes
  Carbon-2-vs-3 reasoning (see LOW-5) for anyone auditing date logic.
- **LOW-7 · `resolveExistingOffer()` uses an unbounded `->get()`** (`OfferIngestionService.php:357-360`)
  where `->first()` plus a separate `count()` — or `->limit(2)` — would do. Bounded in practice by URL
  equality; flagged only against standards.md's "never `get()` on unbounded sets", and because
  `product_offers.url` is a `text` column with no index (todo **Q12**), so every ingest is a scan.

---

## Praise

- **The `wasChanged()` reasoning in `AiSweepCategory.php:63-68` and `AiAssignCategories.php:75-80` is
  exemplary.** The comment explains *why* `tenant_id` must be in the `select()` — that an unselected
  column reads as null and Laravel silently rewrites `where(col, null)` into `whereNull`, turning the
  dispatch query into a permanent no-op. That is the actual failure mechanism, written down where the
  next person will hit it. It is also why the same bug did not recur in `ProductResource.php:262-266`
  or `ProblemProducts.php:325`, both of which I checked against their narrow `modifyQueryUsing`
  selects and both of which are correct.
- **`buyable_count` really is one definition.** `AssessCategoryHealth.php:66-67` and
  `ProductCompare.php:184-195` apply byte-identical filters (`is_ignored = false`, `status IS NULL`,
  `whereHas('offers', ListingHealth::applyPurchasableOfferQuery)`), so the Spec 032 parity test is
  asserting a real invariant rather than a coincidence. No fifth copy of "purchasable" was added.
- **`decorated_table_query_count_does_not_grow_with_category_count`** (`CategoryResourceHealthTest.php:182`)
  asserts *invariance* rather than a magic number, and says so in the docblock. That is the right shape
  for an N+1 regression test and it will still be meaningful after a Filament upgrade.
- **The Spec 034 key-is-authoritative comment** (`SelectLandingPagePicks.php:134-152`) names the two
  real product pairs (Philips 4400/1200 at 95.7%, Z10/Z8 at 92.3%) that would be lost if the similarity
  fallback were allowed to run after a confirmed key mismatch. That is the shipped bug documented at
  the site of the fix, with the evidence that makes the rule non-negotiable.
- **Spec 033's degradation matrix** (old/new server × old/new extension) meant the manifest bump to
  1.8 required no deploy ordering, and the implementation honours it: `!empty($data['offer_id'])`
  treats an absent key, an explicit `null`, and `0` identically.

---

## Suggested follow-up ordering

1. HIGH-1 — it is actively shaping the pages being regenerated this week.
2. HIGH-2 — three one-line changes; also close/annotate todo **Q11** so it cannot be "fixed" back into
   a bug.
3. HIGH-3 — decide whether `'unknown'` should reach the server before more categories go permanently red.
4. HIGH-4 — an owner decision (accept the re-scoring cost, or narrow the delete using
   `getOriginal('category_id')`), not a defect to patch blind.
5. MEDIUM-2, MEDIUM-3, MEDIUM-4 — each is a small, well-isolated change.
