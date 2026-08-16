# Performance Audit: Spec 031 (picks-only rescan) + the 2026-08-15 `best_offer` exclusion fix
**Date:** 2026-08-16
**Range audited:** `f9a1115..HEAD` (commits through c60f5d6), working tree clean.
**Scope:** `Product::bestOffer/bestPrice` (4b49774) and every consumer, `scope=picks` work-list,
`ListingHealthService`, `SelectLandingPagePicks` / `AuditLandingPageFreshness`, ingestion controllers,
`TenantListController`, `app/Support/*`, `routes/api.php`.

**Measured production scale used throughout:** 2 tenants · ~940 non-ignored products · 1,003 rescannable
offers · 11 landing pages · category rescan = 33–215 sequential POSTs at ~9 s apart · weekly picks pass
= ~82 POSTs (35 pw2d + 47 c2d, ~12 min total) · nightly `pw2d:landing-pages:audit` over 11 pages.

## Summary

> 1. **C1 — `ProductCompare::scoredProducts` was never updated for the `best_offer` exclusion fix.**
>    It still ranks and price-filters on a raw `offers->min('scraped_price')` from an eager-load that
>    omits `condition`/`listing_flags`, while the card rendered next to it resolves `affiliate_url`
>    through the *new, filtered* `best_offer`. A product whose only priced offer is
>    `high_price`/`unavailable`/renewed still ranks, still passes the max-price slider — and renders
>    with **no "Check Current Price" button at all**. This is the only finding that costs money.
> 2. **H1 — the freshness audit invalidates public caches on every run, including no-op runs.**
>    `AuditLandingPageFreshness::execute()` always calls `$page->update()`, whose `saved` hook forgets
>    the landing page's 1 h view-model cache *and* the tenant sitemap cache. 11 busts every night at
>    03:30 plus one per instant-path audit during a picks pass, for pages that didn't change. Two-line
>    fix (`updateQuietly` when `stale_reasons` is unchanged).
> 3. **H2 — `best_price` is no longer store-free.** Because `bestPrice` now delegates to `bestOffer`,
>    which reads `$o->store` for the commission/priority tiebreak, three call sites that eager-load
>    `offers` *without* `store` acquired a fresh per-offer Store lazy load — including the `matched`
>    branch of the ingest hot path (`OfferIngestionService.php:216`).

**Answers to the six priority questions are in "Verdicts" at the bottom.** Headline: the `scope=picks`
endpoint is 3 queries flat and N+1-free (question 3, clean); the nightly audit is ~132 queries and
scales linearly to ~360 at 30 pages with no memory growth (question 4, clean); `hasCleanOffer()` is
*not* per-POST (question 2 — the premise is wrong, it only fires on the negative-condition branch);
`TenantListController` is clean (question 5); and the deferred `product_offers.url` index is **still
not hurting** and can stay deferred (question 6 — arithmetic below).

The 2026-08-10 H1/H2 (job `ShouldBeUnique` + price-changed dispatch guard) and M2 (`store_id` in
`SelectLandingPagePicks`) are **verified fixed**. M1 (url index) and M3 (Filament repeater `find()`
per pick) are **still open** and correctly deferred. No new job-storm class of problem was introduced.

---

## Critical

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **C1. Compare-page scoring never adopted the `best_offer` exclusion — public cards can render with no affiliate CTA.** `scoredProducts` eager-loads `offers:id,product_id,scraped_price` (no `condition`, no `listing_flags`) and computes `'best_price' => $p->offers->min('scraped_price')` **directly**, bypassing the accessor entirely. The max-price filter is likewise a raw `whereHas('offers', scraped_price <= $selectedPrice)`. `visibleProducts` then re-fetches the same ids as full models with `offers.store`, and the Blade card resolves `$product->affiliate_url` → `best_offer` → the **new** filter (excludes `NEGATIVE_CONDITIONS` + `PICK_EXCLUDING_FLAGS`). Three concrete divergences on live public traffic: (a) a product whose every offer is now excluded (single Amazon offer flagged `unavailable` or `high_price` — exactly what a picks pass produces) still ranks and still renders a card, but `@if ($product->affiliate_url)` is false, so `product-compare.blade.php:323` emits **no CTA** — a dead card on a revenue page; `SeoSchema::forSelectedProduct` likewise drops the whole `offers` schema node (`SeoSchema.php:262`); (b) such a product is scored *cheap* on the price axis using the excluded offer's price, so it can outrank clean products; (c) the max-price slider admits it at the excluded price while the `$$$` tier badge beside it comes from `PriceTierRecalculator`, which **does** filter — the two disagree. Note `is_ignored` is never set for `high_price`/`unavailable`, so nothing else filters these products out. | `app/Livewire/ProductCompare.php:176-199` (query + `->min()`), `:186` (price filter); consumed at `resources/views/livewire/product-compare.blade.php:310-327` | Missing affiliate link on an unknown but non-zero share of compare-page cards; mis-ranking on the price axis; slider/tier contradiction. Frequency tracks how many offers the weekly picks pass and Tier-2 rescans flag — Spec 031 records Amazon flagging live picks `high_price` within days. | Make `scoredProducts` use the same predicate as the accessor: add `condition,listing_flags` to the offers column list, and replace `$p->offers->min('scraped_price')` with the filtered minimum — cleanest is `$p->best_price` now that the columns are present (the model is fully hydrated for those two columns; `store` is absent so the tiebreak degrades to price-only, which is all a *price* needs). Apply the same exclusion to the `whereHas` price filter (`->whereNotIn('condition', ListingHealth::NEGATIVE_CONDITIONS)` pushes cleanly into SQL; the JSON flag test does not, so either accept flag-carrying offers through the slider or move the price filter into the PHP pass after scoring). **Bump the cache key** (`products:v2:cat{id}:…`) in the same commit — otherwise pre-fix entries serve the old semantics for up to 90 s after deploy. Add a regression test: a product whose only offer is priced + `listing_flags: ["unavailable"]` must not appear in `scoredProducts`, or must appear with `estimated_price === null`. |

---

## High Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **H1. A read-only freshness audit busts two public caches on every run.** `execute()` unconditionally does `$page->update(['stale_reasons' => …, 'freshness_checked_at' => now()])`. `LandingPage::booted()` registers `static::saved()` → `Cache::forget($page->cacheKey())` (the 1 h `LandingPageController` view model) **and** `Cache::forget(t{tenant}:sitemap:xml)` (10 min). Because `freshness_checked_at` always moves, the write always happens even when `stale_reasons` is byte-identical to what was already stored. So: the nightly command cold-starts all 11 published landing pages every night and busts both tenants' sitemap caches; and during a weekly picks pass, every first-time `condition`/`listing_flags` stamp on a pick offer dispatches `AuditLandingPageFreshnessJob` (`ListingHealthService:153,173`) → another audit → another bust. `ShouldBeUnique` caps the *job count* per page but releases on completion, so a 12-minute pass still produces several busts per page. The action's own docblock claims it is "pure read logic over Products/Categories with exactly ONE write" — the write's side effect on the read cache is exactly what was missed. | `app/Actions/AuditLandingPageFreshness.php:64-67`; hook at `app/Models/LandingPage.php:64-73`; nightly caller `app/Console/Commands/AuditLandingPagesCommand.php:65`; instant caller `app/Jobs/AuditLandingPageFreshnessJob.php:104` | Each cold rebuild ≈ 5 queries + full `SeoSchema::forLandingPage`, paid by whichever real visitor arrives first. Small per event; unbounded in *frequency* because it grows with pages × audit rate, and it defeats the 1 h TTL the page cache was designed around. Also silently makes the sitemap's 10 min TTL meaningless. | Only fire model events when the verdict actually changed:<br>`$changed = ($page->stale_reasons ?? []) !== $reasons;`<br>`$changed ? $page->update([...]) : $page->updateQuietly([...]);`<br>`updateQuietly()` still persists `freshness_checked_at` (so the command's "Checked At" column and the Filament badge stay honest) but skips `saved`, so no cache is invalidated by a no-op audit. A genuine draft→stale transition still busts, which is correct. |
| **H2. `bestPrice` → `bestOffer` → `$o->store` introduced Store lazy loads at three `offers`-only call sites.** Before 4b49774, `best_price` was `$this->offers->min('scraped_price')` — it never touched a relation. It now delegates to `bestOffer`, whose `sortBy` comparators read `$o->store?->commission_rate` / `->priority`. Any caller that eager-loads `offers` but not `offers.store` now issues one `SELECT stores WHERE id = ?` per offer the first time `best_price` is read. Three sites: **(a)** `OfferIngestionService.php:216` `Product::with(['offers','category'])` then `:229` `$product->best_price` — this is the *matched* branch of the ingest hot path (new URL that AI-matches an existing product), so a SERP discovery import pays it per matched offer; **(b)** `RescanProductFeatures.php:42` `Product::with('offers')` then `:55` `$product->best_price`; **(c)** `AuditLandingPagesCommand.php:134` `Product::whereIn(...)->get()` with **no eager load at all**, then `:146` `$product?->estimated_price` — lazy-loads `offers` (1/product) *and* now a Store per offer, ~160 queries for 77 picks where ~77 was already the pre-fix cost. (Note the two sites the builder already fixed — `PriceTierRecalculator`, `SelectLandingPagePicks` — plus `ProblemProducts`/`ProductResource` omit `store_id` from the column list, which is *safe*: a missing FK attribute makes `belongsTo` return null **without** a query. The three above load the full row, so the FK is present and the query fires.) | `app/Services/OfferIngestionService.php:216`, `app/Jobs/RescanProductFeatures.php:42`, `app/Console/Commands/AuditLandingPagesCommand.php:134` | 1–3 extra queries per matched ingest / per rescanned product / per backfilled pick. Not visible at 82 POSTs; a discovery batch import (Tier 3, quarterly, dozens–hundreds of matches) pays it per match, interleaved with AI jobs on a 2-worker DB queue. | One string each: `with(['offers.store','category'])`, `with('offers.store')`, `with('offers.store')`. Zero behaviour change — it just makes the commission/priority tiebreak that `bestOffer` already *tries* to apply actually resolve, instead of silently reading `null` after N wasted queries. |

---

## Medium Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| **M1. `pw2d:flag-condition-products --urls` now prints `N/A` in the price column for every single row.** The eager load is `with('offers:id,product_id,store_id,url')` — **`scraped_price` is not selected**. The row builder then reads `$product->estimated_price` → `best_price` → `best_offer`, whose very first filter clause is `$o->scraped_price !== null`. An unselected column reads as `null`, so *every* offer is excluded, `best_offer` is always null, and the "Est. Price" column is unconditionally `N/A`. Before 4b49774 the same query produced the same `N/A` (raw `min()` over an unselected column) — but this is the review table the owner reads during the Tier-2 rotation, so it is worth fixing while the column-allowlist sweep is open. | `app/Console/Commands/FlagConditionProducts.php:149` (select) vs `:189` (read) | An always-empty column in the human review workflow Spec 031 depends on. No perf cost. | Add `scraped_price,condition,listing_flags` to the offers column list. (Keep `store_id` — it's already there and the row builder uses it at `:183`.) |
| **M2. Filament's `best_price` column shows the *unfiltered* minimum, contradicting the site.** `ProductResource::table` eager-loads `offers:id,product_id,scraped_price` and renders `TextColumn::make('best_price')` — with `condition`/`listing_flags` unselected, the accessor's exclusion clauses evaluate every offer as clean, so the admin list shows the cheapest offer *including* renewed/`high_price`/`unavailable` ones. The public site shows the filtered price. Two numbers for the same product in the two places the owner compares them, with no indication which is which. | `app/Filament/Resources/ProductResource.php:127` (select) vs `:151` (column) | Admin/site disagreement during exactly the flag-triage work Spec 031 institutionalises. No query cost (`store_id` absent → `belongsTo` returns null without a query — verified). | Add `condition,listing_flags` to the column list. Leave `store_id` out deliberately (the tiebreak is irrelevant to a displayed price and adding it would cost a Store query per offer — see H2). `ProblemProducts.php:179` deliberately uses raw `$record->offers->min('scraped_price')` and is **correct as-is**: that page exists to surface bad/priceless listings, so it must *not* apply the exclusion. |
| **M3. `SelectLandingPagePicks::hasEligibleOffer()` omits the `condition` check that both `AuditLandingPageFreshness::hasEligibleOffer()` and `Product::bestOffer` apply.** Selection requires only *priced + no pick-excluding flag*; the audit additionally requires *not `NEGATIVE_CONDITIONS`*. So a product whose only priced, unflagged offer carries `condition = 'renewed'` (with no marker in `raw_title`, so `hasConditionMarker()` misses it too) is **selectable as a pick**, will render on the live guide with `estimated_price === null` and **no CTA** (`_pick-card.blade.php:97,132` — `best_offer` excludes it), and will be reported `pick_ineligible` by the audit *forever*, because regenerating the page re-selects the same product. That is a self-sustaining staleness loop that consumes a full `SelectLandingPagePicks` run per audit and can never clear. Reachability is narrow today (`ListingHealthService` sets `is_ignored` when the *only* offer goes negative, and `is_ignored` is filtered at selection), but it is reachable via a manual un-ignore in Filament — which is precisely the workflow `logIfRecoveringWhileIgnored()` invites the owner into. | `app/Actions/SelectLandingPagePicks.php:252-259` vs `app/Actions/AuditLandingPageFreshness.php:115-123` | A live guide pick with no price and no affiliate link, plus a permanently-stale page the nightly audit re-computes every night. | Add `&& !in_array($offer->condition, ListingHealth::NEGATIVE_CONDITIONS, true)` to `SelectLandingPagePicks::hasEligibleOffer()` — the two methods become identical and should be extracted to one `ListingHealth::isPurchasable(ProductOffer $o): bool` shared by both plus `ListingHealthService::hasCleanOffer()`'s closure and `Product::bestOffer`'s filter. Four copies of one predicate is the actual root cause of this whole finding family. |
| **M4. `pw2d:flag-condition-products` (default mode) loads the entire catalog unbounded.** `Product::with(['category:id,name','offers:id,product_id,raw_title'])->get()` — all ~940 products plus ~1,000 offers into memory in one shot, violating the project's own "`chunk()`/`cursor()` for large datasets — never `get()` on unbounded sets" standard. Fine at today's size (a few MB); the command is exactly the kind that gets pointed at a 10× catalog later. | `app/Console/Commands/FlagConditionProducts.php:74` | ~10–20 MB today; linear and unbounded. | `->chunkById(500, function ($products) { … })`, accumulating `$matches`. Ten-line change, no behaviour difference. |
| **M5. `ListingHealthService::hasCleanOffer()` — the premise of the parent's question is wrong, and the query is fine.** It is **not** called once per ingested offer: the call sits inside the `in_array($condition, NEGATIVE_CONDITIONS)` branch only (`:128`). A 215-offer category walk where, say, 10 listings come back renewed pays **10** extra queries, not 215. The fresh re-read is also *load-bearing*, not redundant: the `matched` path eager-loads `$product->offers` **before** `apply()` writes this offer's new condition, so the in-memory collection would still show the flagged offer as clean and wrongly conclude "a sibling survives". Verdict: keep. One optional tightening below. | `app/Services/ListingHealthService.php:218-228` | ~0.5–5% of POSTs on a rescan, 1 query each. Negligible. | No change required. If ever wanted: push three of the four predicates into SQL — `->whereNotNull('scraped_price')->where('scraped_price','>',0)->whereNotIn('condition', ListingHealth::NEGATIVE_CONDITIONS)->get(['id','listing_flags'])` — leaving only the JSON flag test in PHP. Shrinks the result set; same query count. |

---

## Low Priority

| Issue | Location | Impact | Fix |
|-------|----------|--------|-----|
| L1. `product_offers.url` still unindexed (2026-08-10 M1, deferred). **Verdict: still deferrable — it is not hurting more.** Arithmetic: `WHERE tenant_id=? AND store_id=? AND url=?` uses the `store_id` FK index then row-scans that store's offers comparing a `TEXT` column. Amazon store ≈ 940 rows. Weekly picks pass: 82 POSTs × 940 ≈ 77 K row reads spread over **12 minutes**. Full sweep: 1,003 × 940 ≈ 940 K row reads spread over **2.8 hours**. The extension self-throttles to ~9 s/offer, so the DB is idle >99.9% of that window; this is microseconds of work per 9-second slot. It is O(n²) in catalog size, so revisit at ~5 K offers per store — not now. | `OfferIngestionService.php:64-66`; schema `2026_03_26_000001` | ~0 measurable | See Index Recommendations — SQL provided, ship it whenever another `product_offers` migration is needed. Do not create a migration solely for this. |
| L2. `picksScopeOffers()`'s `->limit(500)` silently truncates a picks sweep once landing pages grow. At 11 pages ≈ 82 offers — 6× headroom. At 100 pages (~700 picks, ~900 offers at today's 1.1–1.3 offers/pick) the cap bites. Because the ordering is `COALESCE(health_checked_at, updated_at) ASC`, truncation degrades gracefully (oldest-first), but unlike the category scope, the extension does **not** re-request in picks mode — so an operator would silently under-cover without any signal. | `app/Http/Controllers/Api/OfferIngestionController.php:134` | None today | Return `'truncated' => $offers->count() === 500` in the JSON so the popup can say "showing oldest 500 of N — re-run after this pass", or raise the cap to 2000. Trigger: >~40 landing pages. |
| L3. `SelectLandingPagePicks`'s duplicate guard does `$products->firstWhere('id', $pickedId)` — a linear scan of the eligible collection per already-picked id, per candidate. On mics (181 eligible) with 7 picks over ~15 candidate evaluations that's ~19 K comparisons per run, ×11 pages nightly. Plus `similar_text()`, which is superlinear in string length (short normalized names, so fine). | `app/Actions/SelectLandingPagePicks.php:112` | Milliseconds | `$byId = $products->keyBy('id');` once outside the closure and `$byId->get($pickedId)` inside. Also memoize `normalizeName()` per product id — it's recomputed for the same picked product on every candidate. |
| L4. `ProductCompare::scoredProducts`'s cache key includes `p{$selectedPrice}`, so every distinct slider position is a cache miss + a full category query + a cache write (90 s TTL each). With `wire:model.live.debounce.300ms` on a range input, one drag can mint a dozen entries. Pre-existing (outside this range), but it interacts with the C1 fix — whatever predicate lands must be applied to both the cached query and the key version. | `app/Livewire/ProductCompare.php:175` | Cache churn, not latency (each miss is one indexed query on ≤181 rows) | Optional: quantize `selectedPrice` into the key (e.g. round to the nearest 5% of `maxPrice`) so a drag reuses buckets. Only worth doing if Redis key count becomes a concern. |
| L5. `AuditLandingPageFreshnessJob::uniqueId()` returns the bare `landingPageId` with no tenant prefix — safe, because `landing_pages.id` is a single-DB global auto-increment, so ids never collide across tenants. Noted only to record that it was checked and is *not* the same class of bug as the 2026-08-10 L7 badge key. | `app/Jobs/AuditLandingPageFreshnessJob.php:45-48` | 0 | None. |
| L6. `ListingHealthService` fires `AuditLandingPageFreshnessJob::dispatchForProduct()` on any `condition`/`listing_flags` change, and that helper runs `LandingPage::where(tenant)->get(['id','picks'])` **before** deciding whether the product is a pick. On the *first* verification pass every offer transitions `condition: null → 'new'` and `listing_flags: null → []`, so all 215 POSTs of a category walk pay that 11-row query even though ~208 of them dispatch nothing. Steady state (second pass onward) the values are already `'new'`/`[]`, `wasChanged()` is false, and the query never runs. | `app/Services/ListingHealthService.php:153,173`; `AuditLandingPageFreshnessJob.php:72-79` | 1 query on an 11-row table, first pass only | Nothing. Revisit only alongside the 2026-08-10 L4 note (a `landing_page_product` pivot) if `landing_pages` ever reaches thousands of rows. |
| L7. `TenantListController` — **clean, confirmed** (parent question 5). Exactly one query, 2 rows, no relations touched, no N+1. It *does* hydrate the `data` JSON column (unavoidable: `name` is a real column but VirtualColumn's `retrieved` hook decodes `data` into ~15 branding attributes) — 2 rows, irrelevant, and narrowing the select would be a pointless micro-optimisation. `orderByRaw("COALESCE(NULLIF(name, ''), id)")` is a filesort over 2 rows; `name` **is** a real column (`2019_09_15_000010`, `getCustomColumns()`), so there is no JSON-path trap here. | `app/Http/Controllers/Api/TenantListController.php:32-43` | 0 | None. Leave exactly as written. |

---

## Caching Recommendations

| Data | Current | Recommended | Expected gain |
|------|---------|-------------|---------------|
| Landing page view model (`t{tenant}:landing:{slug}`) | `Cache::remember` 3600 s, **invalidated by every freshness audit incl. no-ops** (H1) | Same TTL; invalidate only when `stale_reasons` actually changes (`updateQuietly` otherwise) | 11 forced cold rebuilds/night → 0; plus one avoided per no-op instant audit during a picks pass |
| Tenant sitemap XML (`t{tenant}:sitemap:xml`) | `Cache::remember` 600 s, invalidated by the same `saved` hook | Same, gated by H1's `$changed` check | Restores the 600 s TTL's actual meaning |
| Compare-page scored products | `Cache::remember` 90 s, key `products:cat{id}:b{brand}:p{price}` | Keep 90 s; **bump the key to `v2`** as part of the C1 fix; optionally bucket `p{price}` (L4) | Prevents 90 s of post-deploy stale pre-fix semantics |
| Landing-page freshness verdict | Recomputed in full per audit (a whole `SelectLandingPagePicks` run per page) | Leave uncached | Correct as-is. It must reflect live product/offer state; the input set changes on every ingest. The existing `ShouldBeUnique(600 s)` debounce is the right lever and is already in place. |
| `scope=picks` work list | Uncached (3 queries/request) | Leave uncached | 3 queries, twice a week. Caching would only add staleness risk to a freshness tool. |
| Pick product names in the Filament repeater | Still `Product::find()` per pick per render (2026-08-10 M3, open) | Persist `product_name` into the picks JSON at generation time | 7+ point queries per admin form render → 0. Unchanged recommendation. |

---

## Index Recommendations

Nothing in this change range **requires** a new index. The `scope=picks` query shapes are already served:

- `LandingPage::where('tenant_id')->orderBy('slug')` → covered by the existing
  `unique(tenant_id, slug)` from `2026_07_18_120000`. 11 rows today, 100 rows later: still one
  index-ordered scan.
- `ProductOffer::where('tenant_id')->whereIn('product_id', [~77 ids])` → driven by the
  `product_offers_product_id_foreign` index, ~100 rows returned, then a filesort on
  `COALESCE(health_checked_at, updated_at)`. **Explicitly do not** add
  `(tenant_id, product_id)`: it duplicates the FK index's usefulness here, and no covering index can
  eliminate the filesort while the sort key is wrapped in `COALESCE()` — same reasoning as 2026-08-10 L2.
- `Product::whereIn('id', …)` in the audit → primary key.
- `SelectLandingPagePicks`'s `where category_id + is_ignored + status` → already served by
  `idx_products_tenant_category_ignored` (`2026_04_04_000001`).

The one carried-over recommendation, for whenever a `product_offers` migration is next written
(**not** worth a migration of its own today — see L1):

```sql
-- Carried from 2026-08-10 M1. Leading with tenant_id per the project's composite-index rule
-- (@architect directive, project_context.md §11); store_id alone would also work since store
-- rows are already tenant-owned. Prefix length 150 covers the discriminating tail of Amazon
-- /dp/{ASIN} and Shopify /products/{handle} URLs while staying inside the InnoDB key limit.
ALTER TABLE product_offers
  ADD INDEX product_offers_tenant_store_url_prefix (tenant_id, store_id, url(150));
```

Explicitly **not** recommended:

* `health_checked_at` — every consumer sorts through `COALESCE(health_checked_at, updated_at)`,
  which no index can serve. Sort sets are ≤500 rows.
* Anything on `landing_pages.picks` / `stale_reasons` or `product_offers.listing_flags` — all three
  are read only after PHP decode; there is no SQL predicate to index, and the MySQL JSON-`LIKE`
  normalisation trap (Spec 030 §B1) means introducing one would be actively dangerous.
* Any index that would make `BatchImportController.php:49`'s
  `SUBSTRING_INDEX(SUBSTRING_INDEX(url,'/dp/',-1),'?',1)` sargable — a `url(150)` prefix index does
  **not** help a wrapped expression. Fixing that needs a stored `asin`/`url_hash` column, which is a
  bigger change than the once-per-request scan justifies.

---

## Verdicts on the parent's six priority questions

**1. `bestOffer`/`bestPrice` column-allowlist sweep — two of the three known holes are fixed, three
new ones found, and one of them is Critical.** Complete inventory of every narrow `offers:` eager
load in `app/`:

| Site | Column list | `best_offer` derivative read? | Verdict |
|---|---|---|---|
| `SelectLandingPagePicks.php:57` | `id,product_id,store_id,scraped_price,image_url,raw_title,listing_flags,condition` + `offers.store` | yes (`image_url`) | ✅ complete — 2026-08-10 M2 confirmed fixed |
| `PriceTierRecalculator.php:42` | `id,product_id,scraped_price,condition,listing_flags` | yes (`best_price`) | ✅ correct. `store_id` omitted → `belongsTo` returns null **without a query** (verified), so the tiebreak is inert but no N+1 exists — and a tiebreak is irrelevant to a price-only decision |
| `ProductCompare.php:183` | `id,product_id,scraped_price` | **bypassed** — raw `->min()` | ❌ **C1** |
| `ProductResource.php:127` | `id,product_id,scraped_price` | yes (`best_price` column) | ❌ **M2** (no N+1) |
| `FlagConditionProducts.php:149` | `id,product_id,store_id,url` | yes (`estimated_price`) | ❌ **M1** — `scraped_price` missing → always null |
| `FlagConditionProducts.php:74` | `id,product_id,raw_title` | no | ✅ safe (see M4 for the unbounded `get()`) |
| `ProblemProducts.php:140` | `id,product_id,store_id,url,scraped_price` + `offers.store` | no — raw `->min()` **by design** | ✅ correct, must stay unfiltered |
| `GlobalSearch.php:284` | `id,product_id,image_url` | no — reads `offers->first()->image_url` | ✅ safe |
| `BatchImportController.php:50` | closure-filtered, full columns | no | ✅ safe |

**No N+1 was introduced on any public page.** `ProductCompare` (`:109`, `:255`, `:281`, `:302`),
`LandingPageController:65`, and `SimilarProducts:29,44` all eager-load `offers.store` with full
columns — verified line by line. The `store` lazy loads H2 describes are confined to two write-path
jobs and one CLI backfill. Also confirmed: `Attribute::make(get:)` caches object returns by default
(`withObjectCaching`), so `best_offer` is filtered-and-sorted **once** per model instance even though
`image_url` + `affiliate_url` + `estimated_price` + `SeoSchema` each reach for it — the accessor's
repeated-sort cost is a non-issue.

**2. `hasCleanOffer()` — the premise is wrong; it is fine.** Not one query per ingested offer: it is
gated inside the `NEGATIVE_CONDITIONS` branch, so a 215-offer walk pays it only for the handful of
listings that actually come back renewed/refurbished/open-box/used (realistically 0–20 → 0–20
queries). It does **not** duplicate loaded data in the path that matters — the refresh branch uses
`Product::find()` with no offers loaded at all, and where offers *are* loaded (`matched` branch) the
collection predates this offer's own `update()`, which is precisely why the fresh read is required.
Keep as written. See M5 for an optional predicate pushdown.

**3. `scope=picks` — 3 queries flat, no N+1, scales to 100 pages without a query-count change.**
(1) `landing_pages` by tenant ordered by slug (index-ordered, 11 rows); (2) `product_offers` by
`tenant_id` + `whereIn(product_id)` (~100 rows, `limit 500`); (3) the `product:id,category_id` eager
load. `pickProductSlugs()`'s PHP reduce is O(pages × picks) — 77 iterations today, ~700 at 100 pages,
sub-millisecond either way, and correctly avoids the JSON-`LIKE` trap. The `with('product:id,category_id')`
that satisfies the T1 contract addendum is a single batched query, not per-row — verified. The only
scaling concern is the `limit(500)` truncation at ~40+ pages (L2), and it is a coverage issue, not a
performance one.

**4. Nightly audit — ~132 queries, ~360 at 30 pages, flat memory.** Per page: 3 (pick products +
offers + stores) + 1 (`Category::find`) + 7 (`SelectLandingPagePicks`: features, products,
featureValues, offers, stores, presets, preset_features) + 1 (page update) = **12**. × 11 pages =
**~132 queries**, single-digit seconds. At 30 pages: ~360 queries, same shape. **Memory does not
grow with page count** — categories are loaded and released one at a time, so peak is a single
category (largest today: mics, 181 products + ~1,400 feature values + ~200 offers ≈ 10–15 MB
transient). And the "re-runs `SelectLandingPagePicks` per page, loading a whole category each time"
concern cannot become duplicated work: `landing_pages` has `unique(tenant_id, category_id)`, so the
page↔category mapping is 1:1 by construction and no category is ever loaded twice in a run. **The
nightly run's real cost is not queries — it is H1**, the 11 public cache invalidations it performs on
pages that didn't change.

**5. `TenantListController` — clean.** One query, 2 rows, no relations, no N+1, no accidental
`data`-column problem (it *is* hydrated, unavoidably and harmlessly, because VirtualColumn decodes it;
`name` is a real column so the `ORDER BY` is valid SQL). Leave it alone — see L7.

**6. Indexes / the deferred `url` index — still deferrable, and no, it is not hurting more.**
The rescan does hit that lookup ~1,000 times per full sweep, but the sweep is spread over 2.8 hours
at ~9 s/offer: each scan is ~940 row comparisons inside an otherwise-idle 9-second window. The weekly
picks pass is 82 such scans over 12 minutes. Neither is measurable. The concern is purely
asymptotic (O(catalog²) per sweep) — revisit at ~5 K offers per store. SQL is provided above to
piggyback on the next `product_offers` migration. Nothing in the Spec 031 query shapes needs a new
index, and three specific indexes are argued *against* in the section above.
