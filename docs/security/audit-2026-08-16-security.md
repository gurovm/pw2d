# Security Audit: Spec 029 §A2a–A2c (offer-vs-product condition, bestOffer exclusion) + Spec 031 (picks-only rescan, tenant directory)

**Date:** 2026-08-16
**Range:** `f9a1115..HEAD` (commits through c60f5d6)
**Scope:** `app/Http/Controllers/Api/{BatchImportController,OfferIngestionController,ProductImportController,TenantListController}.php`, `routes/api.php`, `app/Http/Requests/{BatchImportRequest,ProductImportRequest}.php`, `app/Services/{ListingHealthService,OfferIngestionService,PriceTierRecalculator}.php`, `app/Actions/{SelectLandingPagePicks,AuditLandingPageFreshness}.php`, `app/Models/{Product,ProductOffer}.php`, `app/Support/{ListingHealth,ProductConditionGuard}.php`, `chrome_extension/{background,content,popup}.js` + `popup.html` + `manifest.json`, `tests/Feature/{ExtensionTenantListTest,RescanListControllerTest,OfferIngestionTest,BatchImportControllerTest,ProductImportControllerTest}.php`.
**Prior audit:** `docs/security/audit-2026-08-10-security.md` — M1, M2, L2, L3, L7 verified **fixed** in this range and not re-reported. L5 is still open (see L6 below).

---

## Critical (fix immediately)

None found. No unauthenticated write path, no cross-tenant read/write, no SQL injection, no mass-assignment gap.

---

## High (fix before release)

| # | Issue | Location | Fix |
|---|-------|----------|-----|
| **H1** | **Stored XSS on public tenant sites via `store_slug` → JSON-LD.** `store_slug` is validated as `required\|string\|max:100` with **no format constraint**, and `OfferIngestionService` turns it into a `Store` row via `Str::title(str_replace('-', ' ', $slug))`. That store name is rendered into the product-page JSON-LD as `offers.seller.name` (`SeoSchema.php:275`), and `ProductCompare::render()` encodes schemas with **`JSON_UNESCAPED_SLASHES`** (line 722) before `{!! $schemaJson !!}` inside `<script type="application/ld+json">` (`app.blade.php:43`). `JSON_UNESCAPED_SLASHES` is precisely what stops `</script>` being escaped to `<\/script>`, so a `store_slug` of `</script><img src=x onerror=alert(1)>` (37 chars, no `-`, survives `Str::title` because HTML tag/attribute names are case-insensitive) breaks out of the script block and executes on every render of any product page where that store owns `best_offer` — which the same request can guarantee by sending the lowest price (see H2). The landing-page path is **not** affected: `landing/show.blade.php:33` uses plain `json_encode()`, which escapes `/` by default. This encoding flaw was reported in `docs/security/audit-2026-04-04-frontend-livewire-security.md` and never applied; what is new in this range is that arbitrary store creation is now on a documented, routinely-exercised ingestion path. | `app/Http/Controllers/Api/OfferIngestionController.php:23`; `app/Services/OfferIngestionService.php:58`; `app/Livewire/ProductCompare.php:721`; `app/Livewire/Home.php:82` | Two independent fixes, apply **both**. (1) Constrain the slug: <br>```php
'store_slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
```<br>(2) Make the schema encoder incapable of breaking out — drop `JSON_UNESCAPED_SLASHES` and add `JSON_HEX_TAG` in both `ProductCompare::render()` and `Home::render()`: <br>```php
'schemasJson' => array_map(
    fn (array $s) => json_encode(
        $s,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ),
    $seo['schemas'],
),
``` |
| **H2** | **A crafted ingestion payload controls the affiliate money path.** Spec 029 §A2c made `Product::bestOffer` filter on `condition` and `listing_flags`. Both fields are supplied verbatim by the API caller, so the money path now has three independent levers, all reachable with a single `POST /api/extension/ingest-offer`:<br>**(a) Demote a clean competitor offer** — re-POST an existing offer's exact `url` with `{condition:'new', listing_flags:['high_price']}`. `ListingHealthService::apply()`'s flags branch stores it, `bestOffer` excludes it, and the next-cheapest offer inherits `affiliate_url`. No price change needed.<br>**(b) Promote a previously-flagged offer** — re-POST the same URL with `{condition:'new', listing_flags:[]}`. The clean branch wipes `listing_flags` to `[]` and sets `condition='new'`, re-admitting the offer to `bestOffer`. Note `resolveEffectiveCondition()` only blocks this when the **incoming `raw_title`** carries a marker — and `raw_title` is also caller-supplied, so the guard is trivially bypassed by sending a clean title (which simultaneously overwrites the stored `raw_title`, defeating `SelectLandingPagePicks::hasConditionMarker()` too).<br>**(c) Inject an entirely new winning offer on someone else's product** — the exact-title heuristic (`OfferIngestionService.php:165`, `LOWER(name) = ?` in the same category, non-ignored, `status IS NULL`) matches on caller-supplied `raw_title` alone, needs no `brand`, and never touches the AI matcher. Combined with `Store::firstOrCreate()` auto-creating a store from an arbitrary `store_slug`, one request attaches an offer with an arbitrary `url` at `scraped_price = 0.01` to any live product; it immediately wins `bestOffer`, `best_price`, `estimated_price` and `affiliate_url` on the compare page, the product page, and every landing-page pick card.<br>(c) predates this range; (a) and (b) are new and are the first *price-independent* levers over `best_offer`. All three sit behind the shared extension token (H3), which is the only thing separating "scraper convenience" from "silent affiliate-link hijack + fabricated pricing on a live commercial page." | `app/Models/Product.php:122`; `app/Services/OfferIngestionService.php:58,82,165,200`; `app/Services/ListingHealthService.php:140,165` | Bind offers to stores that the operator actually configured, and bind store URLs to that store's domain. (1) Add a `stores.domain` column (nullable for legacy rows) and stop creating stores from API payloads: <br>```php
$store = Store::where('tenant_id', $tenantId)
    ->where('slug', $data['store_slug'])
    ->first();

if (! $store) {
    return ['action' => 'rejected_unknown_store', 'product_id' => null];
}

$host = strtolower((string) parse_url($data['url'], PHP_URL_HOST));
if ($store->domain && ! ($host === $store->domain || str_ends_with($host, '.' . $store->domain))) {
    return ['action' => 'rejected_url_host', 'product_id' => null];
}
```<br>(New stores become a deliberate Filament action, which is how a vendor relationship is created anyway.) (2) Gate the price-independent flag levers on a plausibility check — refuse to clear `listing_flags` on an offer whose stored `condition` is negative unless the payload also supplies a `scraped_price` (i.e. the page really rendered), and log every `high_price`/`unavailable` set/clear transition with the offer id so a hijack attempt is visible in the audit trail. (3) See M2 for the `is_active` half of the same problem. |
| **H3** | **The single shared extension token is now a tenant-directory key as well as a cross-tenant write key.** `VerifyExtensionToken` compares one static `CHROME_EXTENSION_KEY` and `InitializeTenancyFromPayload` then does `Tenant::find($request->header('X-Tenant-Id'))` with **no association whatsoever between the token and the tenant** — no allowlist, no claim, no per-tenant secret. The builder's claim that `GET /api/extension/tenants` "discloses no authority the token lacked" is **correct and verified**: the token already granted full read/write on every tenant by swapping one header, and `ExtensionTenantListTest::it_lists_every_tenant_regardless_of_which_tenant_id_header_is_sent()` pins exactly that. What the new endpoint changes is the *cost of the first step*: cross-tenant abuse no longer requires guessing string ids like `coffee-decide`. Stated plainly, the standing design is the weakest link in the system: one non-rotating, non-revocable, non-audited secret, held in plaintext in `chrome.storage.local` on every machine that has ever run the extension, grants (i) enumeration of every tenant, (ii) creation/modification of products, offers, and stores in **all** of them, (iii) the ability to set `is_ignored` on arbitrary products (M4), and (iv) control of the affiliate link on every product (H2) and stored XSS on every tenant site (H1). There is no server-side record of which client did what. This is a single-owner deployment today, which is the only reason this is High rather than Critical. | `app/Http/Middleware/VerifyExtensionToken.php:13`; `app/Http/Middleware/InitializeTenancyFromPayload.php:23`; `routes/api.php:20`; `app/Http/Controllers/Api/TenantListController.php` | Move to per-tenant, revocable tokens. Minimal shape that keeps the extension's discovery flow intact: <br>1. New `extension_tokens` table: `id, tenant_id (nullable), name, token_hash, last_used_at, revoked_at`. A row with `tenant_id = null` is a *directory* token (may call `/extension/tenants` only); a row with a `tenant_id` may call every other extension route **for that tenant only**.<br>2. `VerifyExtensionToken` resolves the row by `hash('sha256', $provided)` (constant-time compare on the hash), rejects revoked rows, stamps `last_used_at`, and stashes the row on the request.<br>3. `InitializeTenancyFromPayload` stops trusting the header outright: <br>```php
$record = $request->attributes->get('extension_token');

if ($record->tenant_id === null || ($tenantId && $tenantId !== $record->tenant_id)) {
    return response()->json(['error' => 'Token is not valid for this tenant.'], 403);
}

$tenant = Tenant::find($record->tenant_id);
```<br>4. `TenantListController` then returns only the tenants the presented token is scoped to (a directory token returns all; a tenant token returns its one row) — the endpoint's disclosure shrinks to exactly the caller's existing authority, which is what the class docblock currently *asserts* rather than *enforces*.<br>Interim hardening if the full change is deferred: rotate `CHROME_EXTENSION_KEY` now, and log `tenant_id + route + offer/product id` on every ingestion write so H2/M4 abuse is at least detectable after the fact. |

---

## Medium (fix soon)

| # | Issue | Location | Fix |
|---|-------|----------|-----|
| **M1** | **`listing_flags` byte size is still unbounded — the L2 fix bounds elements, not payload.** `'listing_flags' => 'nullable\|array\|max:5'` counts *elements*; it does not require a list. Laravel validates `listing_flags.*` against the **values**, so an associative payload `{"<8 MB of junk>": "high_price"}` passes every rule and `$offer->update(['listing_flags' => $flags])` persists the attacker-chosen key verbatim into the JSON column (the `array` cast round-trips keys). At `throttle:120,1` that is ~1 GB/min of JSON writes on a single offer row, plus arbitrary strings surfacing in the Filament offer view. The prior audit's L2 fix closed the repeated-element case but not this one. | `OfferIngestionController.php:35`; `BatchImportRequest.php:33`; `ProductImportRequest.php:32` | Normalise to a list before validating, in all three places. For the two Form Requests: <br>```php
protected function prepareForValidation(): void
{
    if (is_array($flags = $this->input('listing_flags'))) {
        $this->merge(['listing_flags' => array_values($flags)]);
    }
}
```<br>(For `BatchImportRequest`, map over `products.*` the same way.) In `OfferIngestionController::ingest()`, do the same via `$request->merge()` before `validate()`, and add a belt-and-braces rule: <br>```php
'listing_flags' => ['nullable', 'array', 'max:5', function ($attr, $value, $fail) {
    if (! array_is_list($value)) {
        $fail('The :attribute must be a list of flag strings.');
    }
}],
``` |
| **M2** | **`Product::bestOffer` ignores `Store::is_active`.** `is_active` is documented as "Inactive stores hidden from comparisons" and is checked in exactly one place in the codebase (`ProcessPendingProduct.php:303`). `bestOffer` filters on price, `condition` and `listing_flags` only — so deactivating a store in Filament does **not** remove its offers from `best_price`, `estimated_price`, or `affiliate_url`. This is both a standalone integrity bug (a vendor relationship the owner ended still receives clicks) and the removal of the obvious incident response for H2: after a rogue store is injected, toggling it inactive would appear to fix the page while the affiliate link kept pointing at the attacker. | `app/Models/Product.php:126` | Add the store filter to the same `filter()` chain (and mirror it in `SelectLandingPagePicks::hasEligibleOffer()`, `AuditLandingPageFreshness::hasEligibleOffer()` and `ListingHealthService::hasCleanOffer()`): <br>```php
->filter(fn ($o) => $o->scraped_price !== null
    && ($o->store === null || $o->store->is_active)
    && !in_array($o->condition, ListingHealth::NEGATIVE_CONDITIONS, true)
    && array_intersect(ListingHealth::PICK_EXCLUDING_FLAGS, $o->listing_flags ?? []) === [])
```<br>`PriceTierRecalculator`'s eager load must then include the store: `'offers:id,product_id,store_id,scraped_price,condition,listing_flags'` + `'offers.store:id,is_active,commission_rate,priority'` — the same class of omission the Fix-3 comment at line 36 already warns about. |
| **M3** | **`background.js` navigates a tab to server-supplied URLs with no host check, and the picks walk is unattended.** `processNextRescan()` calls `chrome.tabs.update(tabId, {url: offer.url})` / `chrome.tabs.create({url: offer.url})` on values that came straight from `/api/extension/rescan-list`, which returns whatever was stored by an ingestion payload. `'url' => 'required\|url\|max:2000'` uses Laravel's `Str::isUrl()` with the default IANA scheme list — it correctly rejects `javascript:`, but permits `ftp`, `file`, `ws`, and ~150 other schemes, and imposes **no host restriction at all**. A poisoned offer row therefore drives the operator's own browser through arbitrary URLs. Spec 031's picks mode makes this materially worse: it is a *scheduled weekly, unattended* walk of ~100 rows in a background tab, spanning every category, with no category selector for the operator to sanity-check against. | `chrome_extension/background.js:248,254`; `OfferIngestionController.php:22` | Server-side, restrict the scheme and (once `stores.domain` exists per H2) the host: <br>```php
'url' => ['required', 'max:2000', 'url:https'],
```<br>Client-side, defend independently — the extension must not trust its own server blindly. In `processNextRescan()`, before navigating: <br>```php
const ALLOWED_HOSTS = [/\.amazon\.(com|co\.uk|de)$/, /(^|\.)clivecoffee\.com$/,
                       /(^|\.)seattlecoffeegear\.com$/, /(^|\.)wholelattelove\.com$/];
let host;
try { const u = new URL(offer.url); if (u.protocol !== 'https:') throw 0; host = u.hostname; }
catch { rescanRun.results.errors++; advanceRescan(attempt); return; }
if (!ALLOWED_HOSTS.some((re) => re.test(host))) {
    console.warn('Rescan: refusing off-allowlist URL', offer.url);
    rescanRun.results.skipped++; advanceRescan(attempt); return;
}
```<br>This list is already the `content_scripts` match list in `manifest.json` — a row outside it could never have been extracted anyway. |
| **M4** | **Any token holder can mark arbitrary products of any tenant `is_ignored`.** `BatchImportController`'s dead-listing heuristic fires on `empty($p['price']) && no negative condition && no flags` and does `$existing->update(['is_ignored' => true])`. `asin` is the only identifier and is fully caller-supplied, so replaying a tenant's known ASINs with `price: null` silently removes those products from every compare page, search result, sitemap entry and landing-page pick — and, per Spec 029's explicit non-goal, un-ignoring is a manual Filament action, so recovery is entirely by hand. ASINs are public information (they are in the offer URLs the site renders). The M1 fix from the previous audit closed the *un-ignore* direction on `ProductImportController` but the *ignore* direction here remains open and needs no prior knowledge of anything private. | `app/Http/Controllers/Api/BatchImportController.php:100` | Do not let a single no-price report be destructive. Require corroboration before ignoring, and record the reason: <br>```php
if (empty($p['price']) && !in_array($effectiveCondition, ListingHealth::NEGATIVE_CONDITIONS, true) && empty($listingFlags)) {
    // A no-price report is evidence, not a verdict: only act on a listing that
    // has already been reported price-less once before.
    if ($offer && $offer->scraped_price === null) {
        $existing->update(['is_ignored' => true]);
        Log::warning('BatchImport: delisting product after a second no-price report', [
            'product_id' => $existing->id, 'asin' => $p['asin'],
        ]);
    } else {
        $offer?->update(['scraped_price' => null]);
    }
    $refreshed++;
    continue;
}
```<br>(Fetch `$offer` above this block rather than below it.) Pair with the per-tenant token scoping in H3 so the blast radius is one tenant rather than all. |

---

## Low / Informational

- **L1 — `condition: 'unknown'` stamps `health_checked_at`, which is a suppression lever.** `ListingHealthService::apply()`'s `unknown` branch writes `health_checked_at = now()` while deliberately storing nothing else. Both rescan scopes order by `COALESCE(health_checked_at, updated_at) asc`, so repeatedly reporting `unknown` for an offer keeps it permanently at the *tail* of the operator's own verification work-list — the exact offer that could not be verified becomes the last one re-checked. This is a deliberate spec decision (029B-B3: "they stop heading the rescan list"), and the extension itself strips `unknown` before POSTing, so it only bites against a hostile or buggy client. If you want the spec behaviour without the lever, split the columns: stamp a new `health_attempted_at` in the `unknown` branch, leave `health_checked_at` untouched, and keep ordering on `COALESCE(health_checked_at, updated_at)` with a secondary `health_attempted_at` tiebreak.
- **L2 — Three near-identical copies of the eligibility rule, and one of them has already drifted.** `ListingHealthService::hasCleanOffer()` (line 218) and `AuditLandingPageFreshness::hasEligibleOffer()` (line 115) both check `scraped_price > 0` **+ `NEGATIVE_CONDITIONS` + `PICK_EXCLUDING_FLAGS`**; `SelectLandingPagePicks::hasEligibleOffer()` (line 252) omits the `NEGATIVE_CONDITIONS` clause and relies on the separate product-level `hasConditionMarker()` raw-title scan instead. They agree today only because a product whose offers are *all* negative-condition is already `is_ignored`. Since `raw_title` is caller-supplied and overwritten on every refresh (H2b), that reliance is fragile. Extract one `ListingHealth::isOfferBuyable(ProductOffer $o): bool` and call it from all three (M2's `is_active` clause then lands in one place too).
- **L3 — `asin` / `external_id` have no format validation.** `'products.*.asin' => 'required|string|max:20'` and `'external_id' => 'required|string|max:20'` are concatenated into `"https://www.amazon.com/dp/{$asin}"` and into the product slug (`Str::slug(...) . '-' . strtolower($asin)`). The URL cannot be host-swapped (the host is fixed before the path), but the slug can receive arbitrary punctuation, which then flows into route URLs and the sitemap. Fix: `['required', 'string', 'regex:/^[A-Z0-9]{10}$/']` on both, and `Str::slug()` the suffix rather than `strtolower()`.
- **L4 — `asin` is meaningless on non-Amazon `scope=picks` rows.** `basename(parse_url($offer->url, PHP_URL_PATH))` returns a Shopify product handle for a Clive/WLL pick. Harmless — `background.js` walks `offer.url` and never reads `asin` — but the field name now lies for roughly half the c2d picks list. Either omit it under `scope=picks` or rename it `url_slug`.
- **L5 — The popup sends the saved token to `http://127.0.0.1:8003` in cleartext when `env = local`.** Loopback only, and `API_CONFIG` is a hardcoded two-entry allowlist so the token can never reach an arbitrary origin — but if the saved token is the *production* one and any other local process is bound to 8003, it is handed over. Consider storing the token per-environment (`extensionToken_local` / `extensionToken_production`).
- **L6 — `existingAsins` still does not validate `category_id`** (carried over as L5 from the 2026-08-10 audit; unfixed). Safe today (parameter binding + initialized-tenancy global scope), but it is the only extension endpoint without the tenant-scoped `Rule::exists`. `ProductImportController.php:52`.
- **L7 — `scope=picks` deliberately returns ignored/pending pick products.** Intended (Spec 031 T1 build note) and not a leak: both queries carry an explicit `where('tenant_id', tenant('id'))` *plus* the `BelongsToTenant` global scopes. Noted so a future reader does not "fix" it into a category-scope-style filter.
- **L8 — `TenantListController` loads full tenant rows (including the `data` JSON column) into memory before mapping.** Output is correct and `every_row_carries_exactly_an_id_and_a_name()` pins the key set, so branding cannot leak today. Cheap extra safety: `Tenant::query()->select(['id', 'name'])`, which makes an accidental `->toArray()` structurally incapable of leaking branding.

---

## Passed Checks

**Priority 1 — `GET /api/extension/tenants`**
- **Response shape is minimal and pinned.** Only `id` + `name` are mapped; the `data` JSON column (branding, colours, hero copy, settings) never reaches the response, and `ExtensionTenantListTest::every_row_carries_exactly_an_id_and_a_name()` asserts the exact key set, so a future field addition fails CI rather than shipping.
- **The builder's "not new exposure" claim is verified true** — `InitializeTenancyFromPayload` resolves `X-Tenant-Id` with `Tenant::find()` and no token/tenant binding, so any token holder already had full cross-tenant read/write. The directory endpoint removes an id-guessing step, nothing more. (What that says about the *underlying* design is H3.)
- **Middleware omission is contained to this one route.** The route sits in its own `Route::middleware([VerifyExtensionToken, throttle:60,1])->group()`; the three other groups in `routes/api.php` all retain `InitializeTenancyFromPayload`, and no route was moved between groups. No route is reachable without tenant context as a side effect.
- **Auth + throttle parity.** `VerifyExtensionToken` uses `hash_equals` and fails secure when `CHROME_EXTENSION_KEY` is unset; `throttle:60,1` matches its read-only siblings and Laravel's default limiter fingerprints per route+IP, so buckets are not shared with the write endpoints. Tests cover no-token/wrong-token/unconfigured-token 403 and assert the tenant ids do not appear in the error body.
- **Ordering is a constant expression.** `orderByRaw("COALESCE(NULLIF(name, ''), id)")` contains no user input.

**Priority 2 — `scope=picks` cross-tenant isolation**
- **Two independent tenant filters on each of the two queries.** `pickProductSlugs()` uses `LandingPage::where('tenant_id', tenant('id'))` *and* `LandingPage`'s `BelongsToTenant` scope; `picksScopeOffers()` uses an explicit `where('tenant_id', tenant('id'))` *and* `ProductOffer`'s scope, and only then `whereIn('product_id', …)`. The explicit offer-side filter is genuinely load-bearing, not decoration: a landing page whose `picks` JSON contained a foreign `product_id` (the failure mode the previous audit's M3 described) fails closed and returns zero rows rather than a foreign offer.
- **`category_id` under `scope=picks` cannot be used as an oracle.** The key is dropped from the `$rules` array entirely, so no `Rule::exists` query executes — there is no differential status code, body, or query timing between a real, foreign, and nonexistent id. Pinned by `scope_picks_ignores_a_supplied_category_id_entirely()` (asserts identical results for a real id, a different category's id, and `999999`).
- **It cannot widen a sweep either** — the value is never read by `picksScopeOffers()`; the work-list is derived solely from the tenant's own landing pages.
- **Row-level `category_id` comes from the product, never the request param** (`with('product:id,category_id')`, itself tenant-scoped), and `scope_picks_reports_each_offers_own_product_category_id()` asserts it for every row rather than spot-checking.
- **`scope` cannot bypass validation by type confusion** — `scope[]=picks` fails `$scope !== 'picks'`, falls into the category branch, and then 422s on `Rule::in`. Case variants (`PICKS`) likewise 422.
- **No JSON `LIKE`** — picks are filtered in PHP over the bounded landing-page set, structurally avoiding the Spec 030 §B1 MySQL JSON-normalisation bug (and the injection surface a `LIKE` would have introduced).
- **Bounded result set** — `limit(500)` on both scopes; `pickProductSlugs()` reads a handful of rows.
- **Test coverage:** 19 tests including cross-tenant isolation, 403 without token, draft pages, multi-store picks, ordering, multi-page slug tie-break, and category-scope regressions unchanged (the `scope=category` row shape is byte-for-byte identical, which is why its pre-existing tests needed no edits).

**Priority 3 — `Product::bestOffer`** (integrity gaps are H2/M2; the mechanics themselves are sound)
- The exclusion set is read from the shared `ListingHealth` constants, identical to the pick-eligibility rule, so `best_price`, `affiliate_url` and `estimated_price` now always describe the *same* offer — the price/link mismatch Fix 3 targeted is genuinely closed.
- `PriceTierRecalculator` correctly widened its eager-load to include `condition` and `listing_flags`; omitting them would have silently readmitted excluded offers to the tier calculation (an unselected column resolves as null and looks clean). The `chunkById(200)` bound is intact.
- The all-excluded case degrades to `best_offer === null`, matching the pre-existing all-null-price behaviour — no null-dereference: every consumer uses `?->`.

**Priority 4 — ingestion condition/flags write surface**
- **Mass assignment: clean.** Every widened write (`ListingHealthService::apply()`, all three refresh branches, both `updateOrCreate` calls) passes an explicit literal key array. No `$request->all()`, no `fill($validated)`, nowhere. `tenant_id` is always set from `tenant('id')` or `$category->tenant_id`, never from the payload.
- **`resolveEffectiveCondition()` cannot un-ignore a product.** Verified by exhaustion: the only `'is_ignored' => false` writes in the entire application are the three `Product::create()` calls for brand-new products; `ListingHealthService` writes `is_ignored` in exactly one direction (`true`), and the recovery path logs a `Log::notice` instead of reversing it, honouring Spec 029's non-goal. `ProductImportController`'s M1 guard early-returns for an ignored product before any write. The `'new' + negative title marker → marker wins` precedence is correct and cannot be inverted; `'unknown'` is never overridden by a title marker, as specified.
- **`condition` and `listing_flags` values are closed vocabularies** at all three endpoints via `Rule::in(ListingHealth::CONDITIONS)` / `Rule::in(ListingHealth::RECOGNIZED_FLAGS)`, with `distinct` now applied per element. `ProductConditionGuard::titleCondition()` maps raw markers to canonical values and falls back to `'used'` rather than leaking a marker string. (Size/keys caveat: M1.)
- **Nested payloads are rejected** — `listing_flags: [['x']]` fails the `string` rule on the element.
- **`hasCleanOffer()` re-queries the DB** rather than reading a possibly-stale eager-loaded `offers` relation, so a just-updated offer is always reflected in the ignore/don't-ignore decision.
- **AI-match tenant hardening (previous M2) is in place** and correct: `Product::withoutGlobalScopes()->where('id', …)->where('tenant_id', $tenantId)->exists()`.
- **Raw SQL** — `orderByRaw('COALESCE(health_checked_at, updated_at) asc')` and `whereRaw('LOWER(name) = ?', [...])` are constant / parameterised respectively; the `SUBSTRING_INDEX` expression in `BatchImportController` is a constant `DB::raw` with the user values bound via `whereIn`. No interpolation anywhere.
- **Log injection** — user-controlled values (`raw_title`, slugs, ASINs) appear only in Monolog context arrays or in a `Log::info` string built from integer counters. No CRLF path.

**Priority 5 — extension credential handling and XSS**
- **No `innerHTML` with server data anywhere in the extension.** The five `innerHTML` assignments are all string literals; every server-derived value goes through `textContent` or `new Option(label, value)`, which sets a text node. Tenant names, `landing_page_slug` (rendered via `formatFlaggedGuides()` → `textContent`), and category names are therefore inert. No `eval`, no `Function()`, no dynamic script injection.
- **Token handling is unchanged and correct.** `type="password"` input, `chrome.storage.local`, sent only as a header, only to a hardcoded two-entry `API_CONFIG` allowlist. `content.js` contains **no** `fetch`, no `chrome.storage` access and no reference to the token — the credential never enters a content script, so no Amazon/Clive/WLL page can reach it. Content-script `matches` are limited to the six store domains; the extension is not injected into `pw2d.com`.
- **Sending the token to the list endpoint before a tenant is chosen is not a regression** — same origin, same header, same transport as every other call; the only difference is the omitted `X-Tenant-Id`.
- **Failure handling does not destroy the credential** — `renderTenantOptions()` preserves a saved `tenantId` on 403/network failure/empty list and re-lists it as `(saved)`, and the tenant select never auto-selects, so a failed fetch can never silently repoint imports at a different tenant.
- **Mutual exclusion is sound in both directions** — one `rescanRun` object serves both scopes, `START_RESCAN` refuses while `active`, and the popup checks `GET_STATUS.active` before starting a SERP batch. The generation counter (`rescanAttempt`) guards every async step, so pause/resume cannot double-POST an offer.
- **No new permissions** in the v1.6 manifest; `host_permissions` unchanged apart from the pre-existing set.

**Other**
- `AuditLandingPageFreshness` still performs exactly one write (its own row) and never mutates a product or offer.
- Extension version bumped to 1.6 in `manifest.json`, in sync with the popup/background changes (CLAUDE.md rule honoured).
