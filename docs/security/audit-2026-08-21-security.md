# Security Audit: Specs 032–035 (shipped + deployed 2026-08-20/21)

**Date:** 2026-08-21
**Range:** `b5c8185..HEAD`
**Scope:** `app/Http/Controllers/Api/OfferIngestionController.php`, `app/Services/OfferIngestionService.php`, `chrome_extension/background.js` (Spec 033) · `app/Actions/AssessCategoryHealth.php`, `app/Console/Commands/CategoriesHealthCommand.php`, `app/Filament/Resources/CategoryResource.php` (Spec 032) · `app/Observers/ProductObserver.php`, `app/Console/Commands/AiSweepCategory.php`, `app/Console/Commands/AiAssignCategories.php` (Spec 035) · `app/Actions/SelectLandingPagePicks.php` (Spec 034)
**Prior audit:** `docs/security/audit-2026-08-16-security.md` — H2 and H3 confirmed **still open**; H1 and M1 confirmed **fixed** (`OfferIngestionController.php:27-29,38`).

## Verdict up front

**Specs 032–035 introduced no cross-tenant read or write, no injection, and no mass-assignment gap.** The `offer_id` primitive is correctly scoped on both paths. The genuinely new findings are one amplification of the pre-existing H2, one observability gap, and one incompletely-applied fix in Spec 035.

**Recommended fix order (see "Sequencing" at the end for the reasoning): M-3 → M-2 → H3 → H2 → M-1 + M-4.**

---

## Critical

None.

---

## High — pre-existing, re-affirmed, **NOT created by 032–035**

| # | Issue | Location | Status |
|---|-------|----------|--------|
| **H3** | Single shared, non-rotating, non-revocable `CHROME_EXTENSION_KEY`; `InitializeTenancyFromPayload` does `Tenant::find($request->header('X-Tenant-Id'))` with no token↔tenant binding. One secret grants full read/write on **every** tenant. | `app/Http/Middleware/VerifyExtensionToken.php:13`; `app/Http/Middleware/InitializeTenancyFromPayload.php:23-36` | **Unchanged.** Spec 033 is built *on top of* this and its spec text explicitly acknowledges it. Fix as prescribed in the 2026-08-16 audit (per-tenant `extension_tokens` rows). |
| **H2** | Crafted ingestion payload controls the affiliate money path: `Store::firstOrCreate()` mints a store from an arbitrary `store_slug`; the exact-title heuristic attaches an offer to any live product; `listing_flags`/`condition` are price-independent levers over `bestOffer`. | `app/Services/OfferIngestionService.php:59-62` (store mint), `:163-175` (`LOWER(name) = ?` heuristic), `:199-209` (`updateOrCreate`); `app/Services/ListingHealthService.php:116-179` | **Unchanged in substance; see M-1 for the new amplification.** The prescribed fix (`stores.domain` + reject unknown slugs) is not implemented. |

---

## Medium — **newly introduced by Specs 033 / 035**

### M-1 (Spec 033) — `offer_id` converts H2 from a discovery-dependent attack into a blind, zero-knowledge, per-row write primitive

**Location:** `app/Http/Controllers/Api/OfferIngestionController.php:54`; `app/Services/OfferIngestionService.php:337-355`, `:81-86`

**This is an amplification of H2, not new authority.** The scoping is correct (see Passed Checks). What changed is the cost of targeting.

Pre-033, hitting a specific existing offer required the exact stored `(store_slug, url)` tuple. Post-033 it requires a single integer. Combined with the refresh branch's write set at `:81-86`:

```php
$existingOffer->update([
    'scraped_price' => $data['scraped_price'],          // ← nulled if key absent (see M-4)
    'raw_title'     => mb_substr($data['raw_title'], 0, 500),
    'image_url'     => $data['image_url'] ?? $existingOffer->image_url,
    'stock_status'  => $data['stock_status'] ?? $existingOffer->stock_status,
]);
```

**Exploit scenario.** A token holder runs, at `throttle:120,1`:

```
for id in 1..N:
  POST /api/extension/ingest-offer
  X-Extension-Token: <the one key>   X-Tenant-Id: <any tenant, per H3>
  {"offer_id": id, "store_slug": "amazon",
   "url": "https://www.amazon.com/dp/B00000000", "raw_title": "x",
   "category_id": <any of this tenant's>, "condition": "new"}
```

Every id that resolves has its `scraped_price` set to `null`, its `raw_title` overwritten, and `listing_flags` cleared. A null price fails `ListingHealth::isPurchasable()` (`app/Support/ListingHealth.php:84-86`), so the offer drops out of `bestOffer` / `best_price` / `affiliate_url` / pick eligibility, and — since commit `9b6575c` hides products with no purchasable offer — the product vanishes from the compare grid. ~10k offers ≈ 83 minutes. Overwriting `raw_title` simultaneously erases the condition-marker evidence `SelectLandingPagePicks::hasConditionMarker()` (`app/Actions/SelectLandingPagePicks.php:344-357`) depends on.

**Why it is Medium and not High:** the same token can already call `GET /api/extension/rescan-list?scope=picks` (no `category_id` needed, 500 rows/request) and `GET /api/categories`, which hands over `offer_id` **and** `url` in bulk. `offer_id` removes one GET from the attack, not a permission. Honest framing for the owner: **Spec 033 did not widen the blast radius; it removed the last requirement to know anything about the data.**

**Fix (concrete).** Bind the write to a plausible observation rather than to a bare id:

```php
// OfferIngestionController::ingest()
'scraped_price' => ['required', 'present', 'nullable', 'numeric', 'min:0'],
```

and in `resolveExistingOffer()`, require the targeted row to agree with the payload it claims to describe:

```php
if ($targetedOffer && $targetedOffer->store_id === $store->id) {
    if ($targetedOffer->url !== $data['url']) {
        Log::warning('OfferIngestion: offer_id/url disagree — refusing targeted write', [
            'offer_id'     => $targetedOffer->id,
            'stored_host'  => parse_url($targetedOffer->url, PHP_URL_HOST),
            'payload_host' => parse_url($data['url'], PHP_URL_HOST),
        ]);
        return null; // fall through to the URL lookup, which will find nothing
    }
    return $targetedOffer;
}
```

This costs the legitimate rescan flow nothing (`background.js:477` sends `url: offer.url`, the DB-stored value, verbatim — see the CRITICAL comment at `background.js:472-475`) and restores "you must know the URL" as a precondition. Pair with the H2 fix (`stores.domain` allowlist) and per-tenant tokens (H3).

---

### M-2 (Spec 033) — the store-mismatch fallback can silently mint a product and report it as a successful rescan

**Location:** `app/Services/OfferIngestionService.php:342-355`, `:261-312`; `chrome_extension/background.js:138-148`, `:521-531`

`resolveExistingOffer()` correctly discards a mismatched `offer_id` and falls back to `(store_id, url)`. If **that** also misses, control reaches the create-new branch at `:261`: a new `Store` (already created at `:59`), a new `Product` stub, and a `ProcessPendingProduct` dispatch (one Gemini `admin_model` call). The response is `action: 'created'` — and the extension's own bucket table maps it to the clean bucket:

```js
const RESCAN_ACTION_BUCKET = { refreshed: 'updated', created: 'updated', matched: 'updated', ... }
```

So a rescan that *minted a duplicate instead of refreshing the row it was told to refresh* is counted as `updated` and reported to the operator as a clean pass. This is precisely the "unobservable bug class" Spec 033 §2 set out to kill (`Log::warning` on multi-match), left open on the adjacent branch. The AI-cost amplification (one Gemini call per miss, 120/min) is a secondary concern.

**Realistic trigger** (not adversarial): an offer stored under a stale or duplicated store row — e.g. two `amazon` `Store` rows after a re-slug — whose URL exists only on the stale row. `offer_id` resolves, `store_id` disagrees with the store resolved from the hardcoded `store_slug` in `content.js`, the URL lookup under the *current* store finds nothing, and the walk mints a duplicate of a product it already had. See INFO-6 for why the *other* degradation path the spec anticipated (offer deleted mid-walk) does **not** reach this branch.

**Fix.** Two lines. Server side, tag the response so the client can tell them apart:

```php
// create-new branch, after $action = 'created';
if (!empty($data['offer_id'])) {
    $action = 'created_after_targeting_miss';
    Log::warning('OfferIngestion: offer_id supplied but a NEW product was created', [
        'offer_id' => $data['offer_id'], 'new_product_id' => $product->id, 'url' => $data['url'],
    ]);
}
```

Client side, do not bucket it as clean — `background.js:528-531` already has the `unknown_action` path, so simply *not* adding the new key to `RESCAN_ACTION_BUCKET` makes it surface as `unknown_action` and name the guide (`:544-548`) for free. Bump `manifest.json` 1.8 → 1.9.

---

### M-3 (Spec 035) — the mass-update fix was applied to 2 of the 5 sites; the surviving 3 include one in a file Spec 035 edited, and one the owner uses routinely

**Location:** `app/Filament/Pages/ProblemProducts.php:347`; `app/Console/Commands/AiAssignCategories.php:126`; `app/Console/Commands/FlagConditionProducts.php:123`

Spec 035's premise is that `Builder::update()` fires no Eloquent events and therefore bypasses `ProductObserver`. `ProductObserver::saved()` has **two** triggers (`ProductObserver.php:38-39`): `category_id` changed **and** `is_ignored` flipped to true. Spec 035 fixed both `category_id` sites and left every `is_ignored` mass-update site untouched.

**The three surviving sites were independently confirmed by the coordinator.** `app/Filament/Pages/ProblemProducts.php:347` is the **"Problem Products" triage page the owner uses routinely** — this is not a theoretical path, and it raises this site's practical priority above the two command-line sites:

```php
// ProblemProducts.php:347 — routine owner-initiated triage.
// Also contradicts ProductObserver's own docblock claim that
// "A manual category change in Filament uses Eloquent and so does fire the observer".
Product::whereIn('id', $records->pluck('id'))->update(['is_ignored' => true]);
```

```php
// AiAssignCategories.php:126 — in the very file Spec 035 edited, 10 lines below its own fix
Product::where('id', (int) $item['id'])->update(['is_ignored' => true]);
```

```php
// FlagConditionProducts.php:123
Product::whereIn('id', $ids)->update(['is_ignored' => true]);
```

**Impact scenario (normal operation, no adversary).** The owner bulk-ignores 30 products from Problem Products. Three of them are live picks on published `/best/...` guides. No `AuditLandingPageFreshnessJob` is dispatched; those pages keep rendering picks for products now hidden from the compare grid, search, and sitemap, until the nightly `pw2d:landing-pages:audit` runs. Same for `--ignore-unmatched` and `pw2d:flag-condition-products`. This is exactly Spec 030's "instant freshness path is dead" bug, still alive on the other trigger — and the docblock now *asserts* coverage that does not exist, which is more dangerous than a known gap because the next author will build on it.

**Fix.** Same treatment Spec 035 applied to the two `category_id` sites:

```php
// ProblemProducts.php:347  ← ship this one first
$records->each(fn (Product $p) => $p->update(['is_ignored' => true]));
```
```php
// AiAssignCategories.php:126
$product->is_ignored = true;
$product->save();
```
```php
// FlagConditionProducts.php:123
Product::whereIn('id', $ids)->cursor()->each(fn (Product $p) => $p->update(['is_ignored' => true]));
```

The fan-out is already collapsed by `AuditLandingPageFreshnessJob`'s `ShouldBeUnique` / `uniqueFor = 600` (`AuditLandingPageFreshnessJob.php:31,38`), so this cannot reintroduce S9. Extend `ProductObserverTest` with a bulk-ignore case so the invariant the docblock claims is actually pinned.

---

### M-4 (pre-existing, newly weaponisable via M-1) — omitting `scraped_price` is a destructive default via undefined-array-key

**Location:** `app/Services/OfferIngestionService.php:82`, `:204`, `:272`, `:286`

`'scraped_price' => 'nullable|numeric|min:0'` and `Validator::validated()` omits absent keys entirely. All four call sites read `$data['scraped_price']` **directly** (unlike `image_url`/`stock_status`, which use `?? $existingOffer->…`). Omitting the field therefore raises four `Undefined array key` warnings and evaluates to `null`, wiping the stored price. The extension always sends the key explicitly (`background.js:486`), so this only bites a hand-crafted payload — which is exactly M-1's payload.

**Fix.**

```php
'scraped_price' => ['present', 'nullable', 'numeric', 'min:0'],
```

and defensively `$data['scraped_price'] ?? null` at all four sites so the class can never be called into an undefined-key state by a future caller.

---

## Low

### L-1 (Spec 032) — the documented rationale for the missing `tenant_id` join is **not** the control that is actually holding

**Location:** `app/Actions/AssessCategoryHealth.php:83-100` (docblock + `checkedAtSubquery()`), `:60-75` (`decorate()`)

The docblock states the safety argument is *"a product's `category_id` FK pins one specific, already tenant-scoped category row."* That is the **weaker** of the two guarantees present. The control actually doing the work is `Product`'s `BelongsToTenant` global scope: `Stancl\Tenancy\Database\TenantScope::apply()` calls `$model->qualifyColumn('tenant_id')`, so every one of the five subqueries (`products_count`, `pool_count`, `buyable_count`, `never_checked_count`, and both `MIN`/`MAX` correlated selects) receives an unambiguous `products.tenant_id = <current tenant>`.

I verified this holds on **both** paths:

- **Filament table path** — `CategoryResource.php:136` `modifyQueryUsing()`. `IdentifyTenant` is registered as a Livewire *persistent* middleware (`vendor/filament/filament/src/FilamentServiceProvider.php:83-89`), so it re-runs on `/livewire/update` (sort, paginate, search) and re-fires `TenantSet` → `tenancy()->initialize()` (`AdminPanelProvider.php:70-72`). Tenancy is initialized on the AJAX table requests, not just the initial page load. **Holds.**
- **CLI path** — `CategoriesHealthCommand.php:51` `tenancy()->initialize($tenant)` inside a `try/finally` with `tenancy()->end()` at `:92`. **Holds.**

The residual risk is that the *stated* reason is the one that would fail first. If `AssessCategoryHealth::decorate()` is ever called with tenancy **not** initialized (a central-domain dashboard widget, a queued job without the `tenancy()->initialize()` wrapper, a tinker/ops script), the global scope silently vanishes and only the `category_id` pin remains — and the counts would then include any foreign-tenant product whose `category_id` happened to point at this category. Note `execute()`'s explicit `where('tenant_id', $tenant->id)` at `:45` scopes only the **outer** `categories` query, not the subqueries, so a `$tenant` argument that disagrees with ambient tenancy yields all-zero counts (fail-closed, not a leak — but silently wrong).

`tests/Feature/Actions/AssessCategoryHealthTest.php:233-253` asserts category-level isolation (row count) but never seeds a foreign-tenant product against a local category, so the actual control is unpinned.

**What a maintainer should do — state this plainly, because the docblock as written will lead someone to the wrong conclusion when refactoring:**

> **Rewrite the docblock at `AssessCategoryHealth.php:86-89` so it names `BelongsToTenant`'s global scope (`products.tenant_id = <current tenant>`, injected into every subquery) as *the control*, and the `products.category_id` FK pin as *defence in depth* — not the reverse.** As it stands, a maintainer who reads it will conclude the FK pin is sufficient and may confidently call `decorate()` from a context without initialized tenancy (a central-domain widget, an un-wrapped job), silently removing the only real control. The comment is the load-bearing documentation for a deliberate omission; if it names the wrong mechanism, the omission stops being deliberate the moment someone else touches it.

**Also add the belt to the global scope's braces, and the missing regression test:**

```php
private static function checkedAtSubquery(string $aggregate): Builder
{
    return self::applyPoolQuery(Product::query())
        ->join('product_offers', 'product_offers.product_id', '=', 'products.id')
        ->whereColumn('products.category_id', 'categories.id')
        ->whereColumn('products.tenant_id', 'categories.tenant_id') // ← explicit, survives loss of tenancy
        ->selectRaw("{$aggregate}(product_offers.health_checked_at)");
}
```

```php
/** @test */
public function a_foreign_tenants_product_pointing_at_this_categorys_id_never_inflates_the_counts(): void
{
    $categoryA = Category::factory()->create();           // tenant A
    tenancy()->end();
    Tenant::create(['id' => 'leak-probe', 'name' => 'Leak Probe']);
    tenancy()->initialize(Tenant::find('leak-probe'));
    Product::factory()->create(['category_id' => $categoryA->id]); // tenant B, A's category
    tenancy()->end();
    tenancy()->initialize($this->tenant);

    $this->assertSame(0, $this->row($categoryA)->pool);
}
```

### L-2 (Spec 032) — interpolated SQL fragment in a private helper

**Location:** `app/Actions/AssessCategoryHealth.php:99` — `->selectRaw("{$aggregate}(product_offers.health_checked_at)")`

Not exploitable: `checkedAtSubquery()` is `private static` and called from exactly two sites (`:72-73`) with the literals `'MIN'` / `'MAX'`, and the docblock says so. Flagged only because it is a string-interpolated SQL sink one visibility change away from being reachable. Cheapest hardening — remove the parameter entirely and make it two two-line methods, or `match ($aggregate) { 'MIN', 'MAX' => $aggregate, default => throw new \InvalidArgumentException() }`.

### L-3 (Spec 035) — observer-driven feature-value deletion is irreversible and now fires on an AI verdict

**Location:** `app/Observers/ProductObserver.php:70-83`; `app/Console/Commands/AiAssignCategories.php:115-116`

`clearForeignCategoryFeatureValues()` hard-deletes. Before Spec 035, a wrong AI category assignment left the old scores intact (the bug being fixed — 12 values in a 6-feature category). Now a wrong assignment **destroys** them, and recovery is a Gemini `RescanProductFeatures` call per product. `AiAssignCategories` feeds `$product->name` into the prompt (`AiService.php:435-441`); product names originate from scraped `raw_title` and are attacker-influenceable through the ingestion API (H3), so a name crafted as prompt injection can steer the assignment.

**Blast radius is bounded and the bound was verified:** `AiService::assignCategories()` at `:467,473-475` validates the returned `category_id` against `array_column($categoryOptions, 'id')`, and `$categoryOptions` comes from `Category::doesntHave('children')` under the tenant global scope (`AiAssignCategories.php:48`). A hallucinated or injected foreign/nonexistent `category_id` is coerced to `null`. So the worst case is "wrong category **within the same tenant** + a score wipe", never a cross-tenant category assignment. Hence Low.

**Fix (defence in depth).** Do not let one AI verdict be both the decision and the destruction — log what is destroyed:

```php
private function clearForeignCategoryFeatureValues(Product $product): void
{
    $doomed = $product->featureValues()
        ->when($product->category_id !== null, fn ($q) => $q->whereHas(
            'feature', fn ($fq) => $fq->where('category_id', '!=', $product->category_id)
        ))->get();

    if ($doomed->isNotEmpty()) {
        Log::info('ProductObserver: clearing foreign-category feature values', [
            'product_id'      => $product->id,
            'new_category_id' => $product->category_id,
            'feature_ids'     => $doomed->pluck('feature_id')->all(),
            'raw_values'      => $doomed->pluck('raw_value', 'feature_id')->all(),
        ]);
        ProductFeatureValue::whereIn('id', $doomed->pluck('id'))->delete();
    }
    // ... rescan dispatch unchanged
}
```

The log line makes a bad sweep recoverable without an AI call and makes the 2026-08-21-class incident greppable in both directions.

### L-4 (Spec 034) — first interpolation of a DB string into a log *message* (breaks the prior audit's stated invariant)

**Location:** `app/Actions/SelectLandingPagePicks.php:251-259`

```php
Log::info(sprintf('SelectLandingPagePicks: brand cap (%d picks) exceeded for category "%s" (id %d), role "%s" ...',
    self::MAX_PICKS_PER_BRAND, $category->name, $category->id, $role, $overQuota->brand_id ?? 'null'));
```

The 2026-08-16 audit's Passed Checks recorded: *"user-controlled values … appear only in Monolog context arrays or in a `Log::info` string built from integer counters. No CRLF path."* `$category->name` is a DB string (admin- or AI-authored) interpolated straight into the message. A newline in a category name forges log lines. Very low impact (category names are not attacker-controlled today) but it silently invalidates a documented invariant.

**Fix.** Move the strings to the context array, which Monolog escapes:

```php
Log::info('SelectLandingPagePicks: brand cap exceeded — picked an over-quota brand rather than leave the slot empty', [
    'cap' => self::MAX_PICKS_PER_BRAND, 'category_id' => $category->id,
    'category_name' => $category->name, 'role' => $role, 'brand_id' => $overQuota->brand_id,
]);
```

### L-5 — `offer_id` enumeration oracle: real, but adds nothing the token did not already give

`offer_id` is a global sequential integer and the `Rule::exists` is tenant-scoped, so `422` vs `200` is an "exists within the tenant named by `X-Tenant-Id`" oracle, and `X-Tenant-Id` is freely swappable (H3). Three mitigating facts, all verified:

1. **No new reach for the normal case.** `rescan-list` already returns `offer_id` + `url` in bulk (500/request), and `scope=picks` needs no `category_id` at all.
2. **One genuinely new set:** offers on `is_ignored` or `pending_ai` products are filtered out of `categoryScopeOffers()` (`OfferIngestionController.php:116-119`) and so were not id-enumerable before. They are now. Impact is bounded — the 2026-08-16 finding that `ListingHealthService` writes `is_ignored` in exactly one direction and `logIfRecoveringWhileIgnored()` (`ListingHealthService.php:196-203`) logs rather than reverses was re-verified and still holds. **An attacker cannot resurrect a hidden product**, only rewrite the price/title/image of one that stays hidden.
3. **Enumeration is not read-only.** A `422` costs nothing (validation precedes the service, so no `Store` row is minted), but a `200` means the row has *already* been overwritten and `health_checked_at` stamped. Probing == corrupting, which is at least self-announcing.

No fix beyond M-1's `url`-agreement check, which turns a bare-id probe into a no-op.

---

## Informational

- **INFO-1 — `User::canAccessTenant()` returns `true` unconditionally** (`app/Models/User.php:51-54`) — every `@pw2d.com` admin can enter every tenant's panel. Deliberate per the docblock at `:42-45` and correct for a single-owner deployment; recorded so it is an acceptance rather than an oversight. `canAccessPanel()` (`:37-40`) is a suffix check on `email`; since Filament registers no `->registration()` route this is not self-service escalation, but `->profile()` does let a logged-in admin change their own email — irrelevant today, relevant the day a second, non-admin role exists.
- **INFO-2 — `AuditLandingPageFreshnessJob::uniqueId()` returns only the landing page id** (`:45-48`). Safe: `landing_pages` uses `$table->id()` (global auto-increment, `2026_07_18_120000_create_landing_pages_table.php:18`), so two tenants can never collide on the `ShouldBeUnique` lock key. Worth a one-line comment before someone "fixes" it into a tenant-prefixed key, or before the PK strategy changes.
- **INFO-3 — the `tenant_id`-in-`select()` fix in both AI commands is fail-closed, not fail-open** (`AiSweepCategory.php:63-69`, `AiAssignCategories.php:75-83`). If a future narrow `select()` forgets `tenant_id`, `dispatchForProduct()` runs `LandingPage::where('tenant_id', null)` → `whereNull` → 0 rows under the global scope. The audit silently never dispatches; it never audits another tenant's page. The comments explain the symptom correctly. Consider making it structural rather than remembered: have `AuditLandingPageFreshnessJob::dispatchForProduct()` `Log::warning` on a null `tenant_id`, so the silent no-op becomes a loud one.
- **INFO-4 — `chunkById` is the right choice in both commands** (`AiSweepCategory.php:70`, `AiAssignCategories.php:84`) — both loops mutate the very column their `where` filters on, which would skip rows under plain `chunk()`. Correct as written.
- **INFO-5 — `AuditLandingPageFreshness::hasSelectionDrift()` re-runs the full `SelectLandingPagePicks::execute()`** (`AuditLandingPageFreshness.php:135-153`), including `similar_text()` (super-linear) over the category's whole pool. Reachable from the API (`ListingHealthService.php:133` → observer → job). Not a DoS today: `ShouldBeUnique` / `uniqueFor = 600` caps it at one run per page per 10 minutes, Spec 034 *reduced* `similar_text` calls by making `modelKey()` authoritative (`SelectLandingPagePicks.php:165-174`), and pick candidates are `whereNull('status')` so their names are AI-normalised rather than raw scraped titles.
- **INFO-6 — Spec 033's "never hard-fail on an unresolvable `offer_id`" is not what shipped, and the service carries a dead branch and a misleading comment.** Spec 033 §1 requires that an offer deleted between `rescan-list` and the POST *degrade to the URL path, not 422 and stall a ~100-offer walk*. The controller's `Rule::exists` (`OfferIngestionController.php:54`) rejects it at validation instead — pinned by `offer_id_pointing_at_a_nonexistent_offer_returns_422_at_validation()`. Consequently the `// else: offer_id didn't resolve (deleted mid-walk) — degrade silently` branch at `OfferIngestionService.php:353-354` is **unreachable over HTTP**, and its comment describes behaviour the system does not have. The 422 is the *safer* choice (it is what prevents deleted-mid-walk from silently minting a duplicate — see M-2) so this is not a fix request; but either the spec or the comment should be corrected so the next reader is not misled about which degradation paths exist.
- **INFO-7 — `preg_replace('/[^a-z0-9]+/', ...)` without the `/u` flag** in `normalizeName()` (`:399`) and `tokenize()` (`:475`) operates byte-wise on `mb_strtolower()` output. Can split multibyte sequences into meaningless tokens. Purely a matching-quality issue — the output is only ever compared in memory and never reaches SQL, HTML, or a log message.

---

## Passed Checks

*This section records what was actually verified rather than assumed. The next auditor should not have to redo it.*

**Priority 1 — `offer_id` tenant isolation (the primary concern). No cross-tenant write on any path.**

- **The controller rule is genuinely load-bearing, not decoration.** `Rule::exists('product_offers', 'id')->where('tenant_id', tenant('id'))` (`OfferIngestionController.php:54`) runs through Laravel's `DatabasePresenceVerifier`, which uses the **query builder**, not Eloquent — `BelongsToTenant`'s global scope does **not** apply there. Without the `where()` clause this really would be an unscoped id lookup. The spec's claim and the inline comment at `:48-53` are both accurate.
- **`tenant('id')` cannot be null on this route.** `InitializeTenancyFromPayload` returns 422 on a missing `X-Tenant-Id` and 404 on an unknown one before the controller runs (`InitializeTenancyFromPayload.php:26-34`), and `routes/api.php:49-56` puts both ingest routes inside that middleware group. So the rule can never degrade to `whereNull('tenant_id')` (which is what `Rule::exists()->where($col, null)` would compile to).
- **The service re-scopes independently, with two mechanisms.** `ProductOffer::where('id', …)->where('tenant_id', $tenantId)` (`OfferIngestionService.php:338-340`) plus `TenantScope::apply()`, which calls `$model->qualifyColumn('tenant_id')` → `product_offers.tenant_id` — unambiguous, verified in `vendor/stancl/tenancy/src/Database/TenantScope.php:20`.
- **The store-mismatch fallback cannot be used to reach an otherwise-unreachable row.** On mismatch the `offer_id` is *discarded entirely* (`:343-352`) and the URL lookup at `:357-360` is byte-identical to the pre-033 behaviour except for `orderBy('id')`. Its `store_id` comes from `Store::firstOrCreate(['slug' => …, 'tenant_id' => $tenantId])` (`:59-62`), so it is transitively tenant-bound, and `ProductOffer`'s global scope applies on top. There is no code path that combines a foreign `offer_id` with any other identifier.
- **`offer_id` does not make H2(c) easier or harder — it is unreachable from that path.** The exact-title heuristic and `Store::firstOrCreate` product-hijack live in the create/match branch at `:163-209`, which is only reached when `resolveExistingOffer()` returns `null`. When `offer_id` resolves, the method returns at `:125` long before. H2(c) is **unchanged**.
- **`url` is not in the refresh branch's write set** (`:81-86`), so a targeted `offer_id` cannot repoint an existing offer at an attacker-controlled URL — the affiliate destination of an existing row is not rewritable through this path.
- **Failing validation has no side effects.** `$request->validate()` completes before `$service->processIncomingOffer()` at `:70`, so a 422'd `offer_id` never reaches `Store::firstOrCreate` and cannot mint a store row.
- **Test coverage is genuine, not ceremonial.** `OfferIdRescanTargetingTest` covers the higher-id-twin regression, cross-tenant 422 *plus* an explicit `assertDatabaseHas(['health_checked_at' => null])` on the foreign row (asserting the *absence of the write*, not just the status code), store mismatch → URL fallback + warning, nonexistent id → 422, absent id → byte-identical legacy path, and multi-match logging.
- **Parameter binding throughout.** `where('id', $data['offer_id'])` with an `integer` rule; `whereRaw('LOWER(name) = ?', [...])`; `orderByRaw('COALESCE(health_checked_at, updated_at) asc')` is a constant. No interpolation anywhere in the ingestion path.

**Priority 2 — Spec 032 tenant isolation**

- **Filament table path holds under Livewire.** `IdentifyTenant` is in Filament's persistent-middleware set (`FilamentServiceProvider.php:83-89`), so `TenantSet` → `tenancy()->initialize()` re-fires on `/livewire/update`; sorting, paginating and searching the health table all run tenant-scoped. The outer query is *doubly* scoped: Filament's `whereBelongsTo($tenant, 'tenant')` plus `Category`'s own global scope.
- **CLI path holds.** `tenancy()->initialize()` / `tenancy()->end()` in a `try/finally` per tenant (`CategoriesHealthCommand.php:51,91-93`). The command is artisan-only and stamps each row with its own `tenant_id` (`:76`), so the multi-tenant table is operator output, not a web-exposed leak.
- **No sort injection.** Filament resolves `$tableSortColumn` through `getSortableVisibleColumn()` and falls back to the default on an unknown value; `defaultSort('oldest_check', 'asc')` and `orderBy('oldest_check')` (`AssessCategoryHealth.php:46`) are constants.
- **`buyable_count` reuses `ListingHealth::applyPurchasableOfferQuery()` verbatim** (`:67`) rather than becoming a fifth definition of "purchasable" — the exact drift the 2026-08-16 S2/M3 findings were about. `buyable_count_agrees_with_product_compare_scored_products_count()` pins the parity.
- **One query, not N+1** — five correlated subqueries inside the caller's existing query, pinned by `decorated_table_query_count_does_not_grow_with_category_count()`.

**Priority 3 — Spec 035 observer safety**

- **No authorization assumption breaks.** `ProductObserver` consults no `auth()`, Gate, or Policy — it reads only model state (`wasChanged`) and dispatches. Running under an artisan command with no authenticated user changes nothing.
- **No tenant-context assumption breaks.** `dispatchForProduct()` reads `$product->tenant_id` explicitly (`AuditLandingPageFreshnessJob.php:72`) rather than ambient `tenant('id')`, and both commands were correctly updated to select the column. `clearForeignCategoryFeatureValues()` scopes through `$product->featureValues()` (product-scoped, no tenant assumption needed). Its `whereHas('feature', …)` runs under `Feature`'s global scope; if tenancy were ever initialized for the *wrong* tenant, the EXISTS finds nothing and **nothing is deleted** — fail-closed toward the old bug, never toward destroying another tenant's data.
- **The queue-flood constraint (Spec 035 §3 / S9) is genuinely met.** `AuditLandingPageFreshnessJob implements ShouldBeUnique` with `uniqueFor = 600` and `uniqueId()` = landing page id (`:31,38,45-48`). A 100-product sweep across 3 pages produces at most 3 audits per 10 minutes, and because the guard lives on the job it also covers `ListingHealthService`'s per-offer dispatches — the spec's stated preference, correctly implemented.
- **`RescanProductFeatures` is only dispatched when `category_id !== null`** (`ProductObserver.php:72-82`), avoiding the `TypeError` its non-nullable `int $categoryId` constructor would otherwise throw. (`ProductResource.php:227,247` do *not* have this guard — pre-existing, out of scope, but it will fatal on a detached product.)
- **No double-dispatch.** `AiAssignCategories` correctly removed its own `RescanProductFeatures::dispatch()` when the observer took over (`:109-114`); there is exactly one dispatch site.

**Mass assignment**

- Every model in `app/Models` declares `$fillable`; no `$guarded = []` outside stancl's own `Tenant`. `ProductOffer.php:16-28` includes `tenant_id`, and every write in the ingestion path passes an explicit literal key array — no `$request->all()`, no `fill($validated)`. `tenant_id` is always set from `tenant('id')` or `$category->tenant_id`, never from the payload.
- `offer_id` is **not** in `ProductOffer::$fillable` and is never passed to `update()` / `create()` — it is used solely as a lookup key. Correct.

**Other**

- `manifest.json` bumped 1.7 → 1.8 in sync with the `background.js` change (CLAUDE.md rule honoured); no endpoint URL changed, so the `popup.js` / `content.js` simultaneous-update rule is correctly not triggered.
- `store_slug` is now format-constrained (`OfferIngestionController.php:38`) — the 2026-08-16 **H1** stored-XSS source fix is in place. The `listing_flags` **M1** `array_values()` normalisation plus `array_is_list()` belt-and-braces is in place at `:27-29,62-66`.
- The `url` rule rejects `javascript:` and `data:` — verified directly against `vendor/laravel/framework/src/Illuminate/Support/Str.php:608-609`, whose pattern requires a literal `://` after the scheme. (Host restriction is still absent — pre-existing **M3** from the 2026-08-16 audit.)

---

## Sequencing — if only one fix ships today

**Ship M-3, and specifically `app/Filament/Pages/ProblemProducts.php:347`.**

The four Mediums split cleanly along the axis the coordinator identified, and that axis decides the order:

| | Gate | Frequency | Who is harmed |
|---|---|---|---|
| **M-1**, **M-4** | Requires the shared extension token | Adversarial only | — |
| **M-2**, **M-3** | None | Normal operation | Readers of published guides / the owner's own data |

**Why M-3 over M-1/M-4.** Both M-1 and M-4 sit behind a credential that, per **H3**, *already* grants cross-tenant read/write on every endpoint, and per `rescan-list` already discloses `offer_id` + `url` in bulk. Fixing M-1 while H3 stands is a lock on an interior door of an unlocked house — worse, it manufactures a false sense of closure on a finding whose real remedy is per-tenant revocable tokens. The correct sequence for that whole family is **H3 → H2 → M-1 + M-4 together**, and none of it should be started as a one-day fix.

**Why M-3 over M-2.** Both are non-adversarial misfires, so frequency and blast radius decide:

1. **M-3 fires on a routine, owner-initiated action.** Every bulk-ignore from Problem Products — a page the owner uses as standard triage — skips the instant freshness audit. M-2 needs a store-row/slug disagreement *and* a URL miss; plausible, but not routine. (And per INFO-6, the deleted-mid-walk case that would have made M-2 common is caught at validation instead.)
2. **M-3's impact is reader-facing and commercial.** A published "Best X" guide keeps recommending a product the owner has just hidden from the compare grid, search, and sitemap. M-2 corrupts the owner's own data and AI budget — bad, but internal, and it announces itself in the duplicate-product count eventually.
3. **M-3 is the cheapest and lowest-risk ship.** Three one-line changes from `Builder::update()` to model-level saves. No API contract change, no extension release, no `manifest.json` bump, and the fan-out is already collapsed by `ShouldBeUnique` so it cannot reintroduce S9. M-2's fix is half server, half client — the client half needs an extension release, which is a slower ship regardless.
4. **M-3 closes a false guarantee, which is worse than an open gap.** `ProductObserver`'s docblock now *asserts* that the observer covers ignore-flips and that Filament re-homes fire it. Both are currently untrue for the `is_ignored` path. Spec 035's entire thesis is "`Builder::update()` silently skips observers, and this codebase leans on observers for freshness, caching, and now feature integrity" — leaving three of five sites unconverted means the next author will build on a documented invariant that does not hold. That is exactly how the 2026-08-21 incident happened the first time.

**Order: M-3 → M-2 → H3 → H2 → M-1 + M-4.**
