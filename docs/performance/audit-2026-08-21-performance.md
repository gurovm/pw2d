# Performance Audit: Specs 032–035
**Date:** 2026-08-21
**Range audited:** `b5c8185..HEAD` — Spec 032 (category health), 033 (offer_id rescan targeting), 034 (pick identity/brand cap), 035 (re-home integrity). All four shipped 2026-08-20/21 and are DEPLOYED.
**Scale:** 11 leaf categories · ~940 pool products · ~1,100 offers · 2 supervisor workers · MySQL 9.3 · Redis cache · `database` queue driver.
**Method:** static analysis of query shapes against the actual index set in `database/migrations/`. **No `EXPLAIN` and no benchmark was run** — every latency figure below is reasoned from index selectivity and row-touch counts, and is labelled as an estimate where it matters.

## Summary

> 1. **H1 — `RescanProductFeatures` swallows its final failure, and Spec 035 made that dangerous.** The observer deletes the old category's feature values *synchronously*, then queues the rescan. If the rescan exhausts its 3 tries, `RescanProductFeatures.php:115-117` does **not** rethrow — the job is marked *completed*, never reaches `failed_jobs`, and the product is left with **zero** feature values. Pre-035 a failed rescan left stale scores; post-035 it leaves none, silently. This is the one finding that can degrade live ranking.
> 2. **H2 — the observer's rescan fan-out is a queue-starvation problem, not a Gemini rate-limit problem.** 2 workers cap concurrency at 2 regardless of how many jobs are queued, so the removed 5 s stagger was largely redundant with a ceiling that already existed. But 100 assigned products = ~12 min where `ProcessPendingProduct` (live extension ingestion) cannot run, because everything shares one `default` queue. Fix is `onQueue`, not a rate limiter.
> 3. **M1 — `SelectLandingPagePicks`'s duplicate guard is O(P²·K) and Spec 034 made it ~4× worse.** The 2026-08-16 audit's L3 (`firstWhere` linear scan per picked id per candidate) was never fixed; `modelKey()` now runs *inside* that same loop, un-memoized, plus `$pickBrandAware` scans the candidate list twice and evaluates the expensive duplicate check *before* the cheap `price_tier` predicate.

**Verdicts on the four commissioned concerns:** Spec 032's central claim **holds** (§Q2). Its no-cache decision **holds** (§Q3). The `(product_id, health_checked_at)` deferral was **right** (§Q2). Rate-limiting middleware on the Gemini admin model is **not** warranted (§Q1). Transaction semantics differ between the command and Filament paths in a way that matters for H1 (§Q4). Spec 033 is a net **performance win** that partially retires the deferred `url` index (§L3).

---

## Q1 — The observer fan-out (concern 1): the premise is inverted

**`AiSweepCategory` does not hit the `whereHas` EXISTS delete, and dispatches zero rescans.**

`ProductObserver.php:72-76` — the sweep sets `category_id = null`, which takes the **null branch**: a plain `DELETE FROM product_feature_values WHERE product_id = ?` and an early `return`. The EXISTS delete (`:78-80`) and `RescanProductFeatures::dispatch` (`:82`) are only reached when a **new** `category_id` is present — i.e. `AiAssignCategories` and Filament re-homes, never the sweep.

Per-product cost, actual:

| Path | Queries/product | Gemini calls/product |
|---|---|---|
| `AiSweepCategory` (→ null) | 4: `firstOrCreate` SELECT (+INSERT), `UPDATE products`, `SELECT landing_pages`, `DELETE product_feature_values` | **0** |
| `AiAssignCategories` (→ new cat) | 4: `UPDATE products`, `SELECT landing_pages`, EXISTS `DELETE`, `INSERT jobs` | **1** (admin_model) |

A hypothetical 100-product sweep = ~400 queries. The `DELETE` uses `product_feature_values_product_id_index` (~6-12 rows); the EXISTS variant adds ~12 PK dives into `features`. That is **~40–80 ms of DB time spread across ~40 s of AI chunk latency** (4 chunks × 25 at ~10 s/chunk). Production evidence agrees: the 2026-08-21 cold-brew sweep detached 6 products, the kettle sweep 9 (`docs/tasks/todo.md`). **No batching needed. Do not add any.**

**Rate protection for the Gemini admin model — adequate, do not add `RateLimited` middleware.** Arithmetic:

- Concurrency ceiling is **2**, not 100. The database queue is pull-based; 100 queued jobs execute 2-at-a-time.
- `gemini-2.5-pro` on a 4096-token scoring prompt ≈ 8–25 s → steady-state **~5–15 requests/minute**.
- The removed stagger (`->delay(now()->addSeconds($assigned * 5))`) produced 12/min. **Same order of magnitude as the ceiling that already exists.** Removing it did not materially change the request rate — the builder's flag in `docs/questions.md:470` is honest but the loss is smaller than it reads.
- Two independent retry layers already exist: `GeminiService.php:53-59` retries 429 three times (2 s/4 s/6 s) at the transport layer, *then* `tries=3` / `backoff=[10,60,300]` at the job layer. Up to 9 attempts over ~6 min per product.
- Google AI Studio Tier 1 for 2.5-pro is 150 RPM. Only the free tier (5 RPM) would bite, and there the transport retry covers it.

Adding a rate limiter would slow an already-slow path to solve a problem the worker count already solves. **What is actually broken is queue sharing (H2) and failure visibility (H1).**

---

## HIGH

| # | Issue | Location | Cost | Fix |
|---|---|---|---|---|
| **H1** | **A failed rescan now leaves a product with zero feature values, silently.** `catch (\Exception $e) { Log::error(...); if ($this->attempts() < $this->tries) throw $e; }` — on the **final** attempt the exception is swallowed, so Laravel marks the job **successful**. It never reaches `failed_jobs`, so `queue:failed` / `queue:retry all` can never recover it. Pre-035 this only meant "scores stayed stale". Post-035 the observer has *already deleted* the old category's values synchronously (`ProductObserver.php:78-80`) before the job runs, so an exhausted rescan leaves the product with **no** `product_feature_values` at all. Such a product scores on rating+price only in `ProductScoringService`, still passes `SelectLandingPagePicks`'s eligibility gate (which checks `ai_summary`/offers, never feature values), and can be selected as a pick with an empty score profile. Visible only as a `Log::error` line. | `app/Jobs/RescanProductFeatures.php:109-118`; deletion at `app/Observers/ProductObserver.php:78-80` | Silent ranking degradation, unbounded in duration (nothing re-queues it). Probability is low per product but rises exactly during a bulk assign run — the case Spec 035 introduced. | Delete the conditional: always `throw $e;`. Laravel's `tries`/`backoff` already do the retry gating; the guard is redundant *and* it suppresses the failure record. Add a test: a rescan that throws 3× leaves the product in `failed_jobs`. Optionally make the observer's delete conditional on a successful rescan (queue a `DeleteForeignFeatureValues` step inside the job) — but the one-line rethrow is the 90% fix. |
| **H2** | **The rescan fan-out starves the ingestion pipeline (head-of-line blocking).** Every job in this codebase uses the `default` queue on the `database` connection with 2 supervisor workers. A `pw2d:ai-assign-categories` run over N products queues N `RescanProductFeatures` at ~8–25 s each → **N × 15 s ÷ 2 workers**. For N=100 that is **~12 minutes** during which `ProcessPendingProduct` (the extension's live ingest path), `AuditLandingPageFreshnessJob`, and `RecalculateCategoryPriceTiers` cannot start. And `ai-assign-categories` runs *precisely during* a Tier-3 discovery import, when the extension is actively POSTing new offers — so the two collide by design. This is the cost the removed stagger was incidentally mitigating; it is a scheduling problem, not a rate-limit one. | `app/Observers/ProductObserver.php:82`; `app/Jobs/RescanProductFeatures.php:27-33` (no `$queue`); also pre-existing at `app/Filament/Resources/ProductResource.php:247` (bulk rescan action, unstaggered since long before 035) | 12 min of blocked ingestion per 100-product assign; ingest POSTs still return 200 (they only enqueue), so the symptom is *delayed* product processing, not errors — i.e. invisible unless you watch `jobs`. | Add `public string $queue = 'bulk';` to `RescanProductFeatures` and change supervisor to `--queue=default,bulk`. Laravel drains `default` fully before touching `bulk`, so ingestion always preempts bulk rescans with **no new workers and no rate limiter**. This also covers the pre-existing Filament bulk action for free — which is the right place for the fix, per the builder's own note in `docs/questions.md:470`. |

### H1 — blast radius (verified independently by the coordinator, 2026-08-21)

`ProductObserver::saved()` does **not** fire the feature-value deletion on product **CREATE**. `wasChanged()` returns false after an insert (`performInsert` never calls `syncChanges()`; `$this->changes` stays empty when `saved` fires from `finishSave`), so `$categoryChanged` is false for a newly created product even though `category_id` was set in the insert. Confirmed by test: `RescanProductFeatures` is **not** queued when a product is created.

This narrows H1 materially without invalidating it. The delete-then-rescan window is reachable **only on a genuine re-home** — an existing product whose `category_id` moves from one value to another. It is *not* reachable on the ~94 products imported during a Tier-3 discovery run, which is the highest-volume creation path in the system. So H1's exposure is bounded by the size of `pw2d:ai-assign-categories` runs and manual Filament re-homes, not by import volume.

**Transaction semantics make it worse on exactly the automated path** — see §Q4. The DELETE commits *before* the dispatch on the command path, so there are two independent routes to a zero-feature-value product, not one.

---

## MEDIUM

| # | Issue | Location | Cost | Fix |
|---|---|---|---|---|
| **M1** | **`SelectLandingPagePicks` duplicate guard: O(P²·K), and Spec 034 amplified an already-logged finding.** Four compounding inefficiencies in one loop: **(a)** `$products->firstWhere('id', $pickedId)` is a linear collection scan per already-picked id per candidate — this is verbatim the **2026-08-16 audit's L3, still open**; **(b)** `modelKey($picked)` is recomputed inside that loop, so the same picked product is re-`tokenize()`d (two `preg_replace` + `explode` + `array_filter`, plus a second `tokenize()` on the brand name) once per candidate per pick site — Spec 034 added this on top of L3; **(c)** `$pickBrandAware` runs the full `$eligible` scan twice whenever no under-quota candidate exists (`$underQuota` exhausts the list, then `$overQuota` restarts from the top); **(d)** `$eligible`'s `&&` chain evaluates the expensive `$isDuplicateOfPicked($p)` **before** the cheap `$predicate($p)` — so for the `budget`/`premium` roles the duplicate machinery runs on every product that the `price_tier` test was about to reject anyway. Also `$addPick` re-runs `$isDuplicateOfPicked` on the product `$pickBrandAware` just validated (`:207`) — a third redundant evaluation. | `app/Actions/SelectLandingPagePicks.php:153-200` (guard), `:157` (firstWhere), `:163` (modelKey), `:237-239` (predicate ordering), `:241`+`:248` (double scan), `:207` (redundant 3rd call) | On the largest live category (~150 eligible, 7 picks): roughly **5–10 k `modelKey()` calls and 200–500 k collection element comparisons per `execute()`**, ≈ 15–60 ms of pure PHP (estimated). Called 11× nightly by `pw2d:landing-pages:audit`, **plus once per `AuditLandingPageFreshnessJob`** — whose frequency Spec 035 just increased, since every re-home now fires one. Scales as O(P²·K): at 500 eligible ≈ 0.3–1 s, at 1,000 ≈ 1–4 s per call. Spec 031's "pool headroom" push toward larger pools walks straight into this. | Mechanical, ~10 lines, no behaviour change: (1) `$byId = $products->keyBy('id');` once, use `$byId->get($pickedId)`; (2) memoize per product id — `$identity[$p->id] ??= [self::modelKey($p), self::normalizeName($p->name)]`; (3) reorder `$eligible` to `$predicate($p) && !in_array(...) && !$isDuplicateOfPicked($p)`; (4) replace the two `->first()` calls with one `foreach` recording the first under-quota and first over-quota hit. Turns O(P²·K) into O(P·K); expect ~10× on the current pool and the difference between 1 s and 100 ms at 1,000 products. |
| **M2** | **`ORDER BY oldest_check ASC` puts `no_data` categories at the top, defeating the spec's stated purpose.** `oldest_check` is `MIN(health_checked_at)` over an empty result set for a category with `pool = 0` → **NULL**, and MySQL sorts NULLs **first** on ASC. Spec 032 says "row one is literally the next category to sweep" and the Filament panel defaults to the same sort. A brand-new or emptied category silently displaces the genuinely-oldest one from row 1 — the exact misread failure mode (§"the rotation queue's top entry was misread") that Spec 032 exists to prevent. Nuance: NULL-first is *correct* for "pool exists but no offer was ever stamped" (that is real import debt) and *wrong* for `pool = 0`. The existing test only seeds non-null checks, so the edge is uncovered. | `app/Actions/AssessCategoryHealth.php:46`; `app/Filament/Resources/CategoryResource.php:137`; test gap at `tests/Feature/Filament/CategoryResourceHealthTest.php:129-163` | Not a latency cost — a correctness cost in the perf feature itself. Bites the moment any category is created or emptied. | `->orderByRaw('CASE WHEN pool_count = 0 THEN 1 ELSE 0 END ASC, oldest_check ASC')` in both places (alias references in `ORDER BY` are valid on MySQL and sqlite). Add a test: a `pool = 0` category sorts **last**, a pool-with-null-checks category sorts **first**. |
| **M3** | **`dispatchForProduct` re-queries the tenant's landing pages once per saved product.** `LandingPage::where('tenant_id', ...)->get(['id','picks'])` runs inside the observer, *before* deciding whether the product is a pick at all. A 100-product sweep issues 100 identical queries returning ~12 rows each, every one carrying a decoded `picks` JSON blob. Same pattern the 2026-08-16 audit logged as L6 for `ListingHealthService`, now reachable from a second trigger site. | `app/Jobs/AuditLandingPageFreshnessJob.php:72-79`, called from `app/Observers/ProductObserver.php:42` | ~100 queries × 12 rows ≈ 5–15 ms total today. Grows as (products swept × landing pages); at 100 pages a 100-product sweep is 10 k row hydrations + 10 k JSON decodes. | Memoize the tenant's pick→page map in a static keyed by `tenant_id` for the life of the process (a CLI sweep is a single process and picks don't change mid-sweep), or `Cache::remember(tenant_cache_key('picks:page_map'), 60, ...)` busted by `LandingPage::saved()` (that hook already exists). Do **not** try a `picks LIKE` SQL filter — the MySQL JSON normalisation trap documented at `:56-68` is real. |
| **M4** | **`AiAssignCategories`' `--ignore-unmatched` branch still uses a mass update, so Spec 030's instant path stays dead for it.** `Product::where('id', ...)->update(['is_ignored' => true])` fires no Eloquent events, so `ProductObserver::saved()`'s `$ignoredFlipped` check (`:38`) never runs and no freshness audit dispatches. This is the *same root cause* Spec 035 exists to fix, one branch away from the two lines it did fix. Already self-reported as knowingly out of scope in `docs/questions.md:472`. | `app/Console/Commands/AiAssignCategories.php:126` | Not latency — a page bulk-ignored out from under stays stale until the nightly 03:30 audit (up to 24 h). Also *saves* work, which is why nothing surfaced it. | `$product->is_ignored = true; $product->save();`. One line, and it makes the file internally consistent with the save 11 lines above it. Note the fan-out is bounded: `is_ignored` products are rarely picks, so `dispatchForProduct` usually finds nothing. |

---

## LOW

| # | Issue | Location | Cost | Fix |
|---|---|---|---|---|
| **L1** | `RescanProductFeatures` job `timeout = 60` vs. an HTTP chain that can legitimately run to ~96 s. `AiService::rescanFeatures` passes no `timeout`, so `GeminiService` defaults to 30 s per attempt (`GeminiService.php:32`) and retries 429 up to 3× with 2 s/4 s waits → 30+2+30+4+30 = 96 s worst case. The worker's alarm fires at 60 s and SIGKILLs mid-retry, so the `catch` block never runs and nothing is logged from the job. 30 s is also tight for `gemini-2.5-pro` with `maxOutputTokens: 4096` on its own. | `app/Jobs/RescanProductFeatures.php:32`; `app/Services/AiService.php:128-130` | Turns a recoverable 429 into an opaque worker kill. Interacts with H1: a killed job burns an attempt without logging. | Raise `$timeout` to 150, **and** pass an explicit `'timeout' => 45` in `rescanFeatures()` so the retry chain fits inside the job budget. |
| **L2** | Redundant index on `product_feature_values`. The table carries `unique(product_id, feature_id)` **and** a standalone `index('product_id')` — the latter is a pure left-prefix duplicate of the former. Both FK constraints are already satisfied (`product_id` by the unique's prefix, `feature_id` by its own index). Every rescan writes ~6 rows via `updateOrCreate` and the observer now issues a `DELETE` per re-home, so this is on a write path Spec 035 made hotter. | `database/migrations/2026_02_13_000006_create_product_feature_values_table.php:21-23` | ~1 extra B-tree maintenance op per row write. Immeasurable today; free to remove. | See Index Recommendations. MySQL-only, verify separately from sqlite. |
| **L3** | **Spec 033 is a net performance win worth recording.** `resolveExistingOffer()`'s `offer_id` branch is a PK point lookup that **skips** the `where(store_id) + where(url)` scan entirely — and `product_offers.url` is still unindexed (2026-08-10 M1, deferred; re-confirmed 2026-08-16 L1). Now that the extension gets `offer_id` from the rescan work list, a full ~1,000-offer sweep goes from ~1,000 × ~940 TEXT row comparisons to ~1,000 PK dives. This **partially retires** the deferred `url` index: it now only serves first-contact ingests and offer_id-miss fallbacks. The one regression is cosmetic — the URL fallback changed `->first()` to `->get()` to log multi-matches, dropping the implicit `LIMIT 1`; with no index on `url` the scan was full either way, so the real cost is zero. | `app/Services/OfferIngestionService.php:335-371`, fallback at `:357-360` | Net negative cost. Recorded so the `url` index stays deferred with a *reason*, not by inertia. | None. Keep the `url` index deferred; revisit only if the offer_id-miss rate rises. |
| **L4** | `AiSweepCategory`'s `chunkById(25)` filters on `where('category_id', $category->id)` while the loop **sets `category_id = null`** — mutating the chunk's own filter predicate mid-iteration. This is **safe only because it is `chunkById`** (keyset on `id`, not `OFFSET`). A future refactor to `chunk()` would silently skip 25 products per flagged batch. | `app/Console/Commands/AiSweepCategory.php:60-70` | Zero today. Latent trap. | Add a one-line comment naming why `chunkById` is load-bearing here. Same applies to `AiAssignCategories.php:81-84`. |
| **L5** | `AssessCategoryHealth::execute()` applies `where('tenant_id', $tenant->id)` on top of `Category`'s `BelongsToTenant` global scope, producing a duplicated predicate. Deliberate defense-in-depth per the docblock, and MySQL's optimizer collapses it. Recorded as checked, not a problem. | `app/Actions/AssessCategoryHealth.php:45` | 0 | None. |
| **L6** | Spec 032's deferred items are all still open, as intended: `Home.php:29-31` (`withCount('products')` unfiltered — but `Cache::remember` 3600 s, so it is a *correctness* issue not a perf one), `BrandResource.php:62`, `ProductCompare.php:99` (correctly `#[Computed(persist: true)]`, so no per-render re-query). | as listed | 0 latency | Leave deferred. When fixed, `Home`'s `home:popular_categories` cache key must be bumped in the same commit. |
| **L7** | **Latent landmine: switching the queue driver to Redis would introduce a read-before-commit race on the Filament re-home path.** All connections in `config/queue.php` set `'after_commit' => false`. Today that is harmless because the `database` driver writes the `jobs` row on the same connection inside Filament's transaction (§Q4), so a worker cannot observe it until commit. On Redis, the job would be pushed to an external store immediately and a worker could pick up `RescanProductFeatures` / `AuditLandingPageFreshnessJob` **before** the transaction commits — reading the pre-update `category_id` and the not-yet-deleted feature values. Redis is already in use for cache, so this migration is plausible. | `config/queue.php:44,73`; transaction at `vendor/filament/filament/src/Resources/Pages/EditRecord.php:139-166` | 0 today; a correctness bug the day the driver changes. | If the queue ever moves to Redis, set `'after_commit' => true` on that connection in the same commit — or add `ShouldDispatchAfterCommit` to `RescanProductFeatures` and `AuditLandingPageFreshnessJob` now, which is driver-independent and costs nothing under `database`. |

---

## Q2 — Verifying Spec 032's central performance claim

**The claim holds, precisely as worded, and the test proves the right thing.**

`decorate()` (`AssessCategoryHealth.php:60-75`) adds four `withCount` correlated subqueries and two `addSelect` correlated subqueries to the caller's existing query. All six are scalar subqueries in the SELECT list — **one round trip**. `CategoryResourceHealthTest::decorated_table_query_count_does_not_grow_with_category_count` asserts query-count invariance across 4→35 rows rather than a magic number, and it is correctly constructed: the `$large` mount happens with the log enabled but is flushed at `:209` *before* the measured `set('tableRecordsPerPage','all')`. It also seeds most rows with a `parent_id`, so Filament's `parent.name` column genuinely exercises the relation. That is the assertion that would catch a future closure switching from `$record->products_count` to `$record->products`. Good test.

**Where the claim stops holding — and one nuance the spec omits.**

Query *count* is invariant to row count. Query *cost* is **linear in total pool size, and pagination does not bound it.** Because `defaultSort` is on `oldest_check` — a select-list alias, not a column — MySQL must evaluate the select expressions for every category row before filesort. `LIMIT 10` prunes the output, not the work.

Reasoned row-touch budget for one admin page load today:

| Subquery | Index used | Touches |
|---|---|---|
| `products_count` | `products_tenant_id_category_id_index` | ~1,050 |
| `pool_count` | `idx_products_tenant_category_ignored` (3-col prefix) | ~940 |
| `buyable_count` | above + `product_offers_product_id_store_id_unique` | ~940 + ~1,100 offers |
| `never_checked_count` | same | ~940 + ~1,100 |
| `oldest_check` / `newest_check` | products index + nested-loop into offers | 2 × (~940 + ~1,100) |

≈ **5,700 index/row touches + ~2,200 `JSON_CONTAINS` evaluations** → **roughly 5–15 ms** (estimated), buffer-pool resident. Trivial, as the spec says.

The dominant per-row term is **not** the `whereHas('offers')` EXISTS by itself — it is the two `whereJsonDoesntContain('listing_flags', ...)` calls inside it (`ListingHealth.php:136-142`). `JSON_CONTAINS` is a per-row function call that no index can serve, and it fires for every offer the EXISTS touches. The `condition` check is `NOT IN` on an indexed column but is evaluated from the row anyway (the EXISTS is driven by `product_id`).

**Scaling estimate (extrapolated linearly, not measured):**

| Pool size | Offers | Est. query time | Verdict |
|---|---|---|---|
| 940 (today) | 1,100 | 5–15 ms | free |
| 10 k | 12 k | 60–120 ms | acceptable |
| **25 k** | 30 k | **150–300 ms** | **the knee — admin list starts to drag** |
| 100 k | 120 k | 0.6–1.5 s | unacceptable |

So the spec's "would stop being trivial in the tens of thousands" is correct; I would sharpen it to **~25 k pool products**. At that point the fix is *not* an index — it is materializing `is_purchasable` as a boolean column on `product_offers`, maintained by `ListingHealthService`, so `buyable_count` becomes an indexed EXISTS with no JSON evaluation.

**Was declining `(product_id, health_checked_at)` right? Yes.** Four reasons:

1. It helps only 3 of 6 subqueries (`never_checked` NOT EXISTS, `MIN`, `MAX`), and only by turning a row lookup into a covering read — perhaps 2–4 ms of a 10 ms query.
2. It does **not** help `buyable_count`, which the spec itself correctly identifies as the real cost, because that needs `scraped_price`/`condition`/`listing_flags`.
3. It does **not** help the rescan work list either — `OfferIngestionController.php:120,164` sorts on `COALESCE(health_checked_at, updated_at)`, which is non-sargable. That kills the "serves two purposes" argument for it.
4. `product_offers` takes a write on every ingest/rescan POST (~1,000+ per full sweep). A fourth secondary index is real write amplification for no measurable read gain.

Re-evaluate at ~25 k pool products, and at that point ship it *together with* the materialized purchasable flag, not alone.

## Q3 — The "no caching" decision

**It holds, comfortably, and the reasoning is stronger than the spec states.**

At 5–15 ms the decorated query is cheaper than the Filament table render around it. A `Cache::remember(..., 900, ...)` would save ~10 ms while introducing exactly the failure mode Spec 032 exists to eliminate: the owner runs a rescan, the panel still shows `import_debt`, and — per Spec 031's Tier-3 rule — that is the signal gating whether `SelectLandingPagePicks` is safe to run. A stale health panel is not merely a worse tool; it is a *wrong* input to a gating decision.

Two additions the spec should carry:

- **The CLI must never be cached.** `pw2d:categories:health` is the cron consumer whose exit code gates the nightly run. A cached `execute()` would make `FAILURE` lag reality by up to the TTL.
- **If it is ever cached, the invalidation surface is large**: any `ProductObserver::saved`, any `ListingHealthService::apply`, any offer ingest. That maintenance burden is itself an argument for not caching until the query crosses ~200 ms — and even then, prefer a 60 s TTL over 900 s.

### Caching Recommendations

| Data | Current | Recommended | Expected gain |
|---|---|---|---|
| Category health (`decorate()` / `execute()`) | none | **Keep none** until the query exceeds ~200 ms (≈25 k pool products); then 60 s, never 900 s | 0 today, and avoids a wrong gating input |
| Tenant pick→page map in `dispatchForProduct` | re-queried per saved product | static memo per process, or 60 s cache busted by `LandingPage::saved()` (hook exists) | 100 queries → 1 per sweep (M3) |
| `modelKey()` / `normalizeName()` per product | recomputed per comparison | in-request memo keyed by `product.id` | ~10× on the pick-selection hot loop (M1) |
| Landing-page freshness verdict | uncached, `ShouldBeUnique(600 s)` debounce | leave uncached | correct as-is; the debounce is the right lever and it now actually fires for command re-homes |
| `home:popular_categories` | 3600 s | unchanged — but **bump the key** when the deferred pool/purchasable filter lands | prevents 1 h of stale counts post-deploy |

## Q4 — Transaction semantics on the re-home paths (commissioned follow-up)

**Answer: on the `AiAssignCategories` path there is no transaction. The `DELETE` is committed before the dispatch. A product can be left with zero feature values in practice, via two independent routes — not only if the job fails later.**

Verified call chain, command path:

1. `AiAssignCategories::handle()` contains **no** `DB::transaction()` — confirmed by reading the whole file. `chunkById()` does not open one either.
2. `Model::save()` does not wrap itself in a transaction. `performUpdate()` runs the `UPDATE`, then `finishSave()` fires `saved`.
3. `ProductObserver::saved()` runs in this order: `dispatchForProduct()` (`:42`) → `clearForeignCategoryFeatureValues()` (`:46`) → EXISTS `DELETE` (`:78-80`) → `RescanProductFeatures::dispatch()` (`:82`).
4. Under MySQL default autocommit with no open transaction, the `DELETE` at step 3 **commits immediately**, before line 82 is reached.
5. `config/queue.php:44` sets `'after_commit' => false` on the `database` connection, so the dispatch is never deferred.

Therefore:

- **Route (a) — dispatch-time failure.** The `DELETE` is durable before the `jobs` INSERT is attempted. If that INSERT throws (deadlock, connection drop, disk full), nothing rolls back and **no rescan is ever queued**. The product is left with zero feature values, with no `failed_jobs` row, because no job was ever created. Narrow window, but completely unrecoverable and invisible.
- **Route (b) — job-time failure.** The H1 path: job queued, runs, exhausts 3 tries, swallows the final exception at `RescanProductFeatures.php:115-117`, is marked *complete*, never lands in `failed_jobs`.

Route (b) is far more likely: it needs only a Gemini 429/timeout storm, which is precisely the condition a bulk assign run creates (H2), and it is made more likely still by the 60 s job timeout cutting the retry chain short (L1). Route (a) requires a database fault.

**The Filament path is safe, which inverts the usual assumption.** `EditRecord::save()` wraps everything in `beginDatabaseTransaction()` (`vendor/filament/filament/src/Resources/Pages/EditRecord.php:139-166`), so the `products` UPDATE, the feature-value `DELETE`, and both job INSERTs are one atomic unit. Because the `database` queue driver writes `jobs` rows on the same connection, a rollback discards them together and a worker can never observe a half-state. **The manual admin path has the safety the automated command path lacks.** (See L7 for how a Redis queue migration would break this.)

**Urgency for the owner:** ship H1's one-line rethrow before the next `pw2d:ai-assign-categories` run. It converts a silent zero-score product into a `failed_jobs` row recoverable with `queue:retry all`, and it closes route (b) — the one that actually fires. Route (a) is not worth defending separately; wrapping the observer body in `DB::transaction()` would change semantics for every caller (including the sweep, and including Filament's already-transactional path) and I would not do it.

Blast radius is bounded by re-home volume only — product **creation** does not trigger this at all (see §H1 blast radius).

## Index Recommendations

**Ship nothing now.** No query shape introduced by Specs 032–035 requires a new index. Everything is already served:

- pool / buyable / never_checked subqueries → `idx_products_tenant_category_ignored` (`tenant_id, category_id, is_ignored`), an exact 3-column prefix match.
- `MIN`/`MAX` join → same index, nested-loop into `product_offers_product_id_store_id_unique`.
- Observer's feature-value `DELETE` → `product_feature_values` `product_id` (+ `features` PK for the EXISTS).
- `AuditLandingPageFreshnessJob`'s page lookup → `unique(tenant_id, slug)` on `landing_pages`.
- Spec 033's `offer_id` branch → primary key.

Two items for **whenever a migration on these tables is next written** — neither justifies a migration of its own. Both are MySQL-only raw statements and **must be verified against production separately from the sqlite test suite** (same constraint as the deferred Perf M1 item; sqlite auto-drops/creates indexes differently and `INFORMATION_SCHEMA` is unavailable there — follow the guarded pattern in `2026_03_29_000001_drop_stale_store_name_indexes.php`).

```sql
-- (1) LOW / L2 — redundant left-prefix duplicate of product_feature_values_product_id_feature_id_unique.
-- Both FK constraints stay satisfied: product_id by the unique's prefix, feature_id by its own index.
-- Free write-path win on a table Spec 035 made hotter (one DELETE per re-home, ~6 upserts per rescan).
-- Verify first:
--   SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_feature_values';
ALTER TABLE product_feature_values
  DROP INDEX product_feature_values_product_id_index;

-- (2) DEFERRED — do NOT ship until pool size approaches ~25k products (see Q2).
-- Serves the never_checked NOT EXISTS and the MIN/MAX subqueries as covering reads.
-- Does NOT help buyable_count (needs scraped_price/condition/listing_flags) and does NOT help
-- the rescan work list (ORDER BY COALESCE(health_checked_at, updated_at) is non-sargable).
-- When that day comes, ship it alongside a materialized `is_purchasable` boolean on product_offers,
-- which is what actually removes the per-row JSON_CONTAINS cost.
ALTER TABLE product_offers
  ADD INDEX product_offers_product_health (product_id, health_checked_at);
```

Explicitly **not** recommended: anything on `listing_flags` or `picks` (read only after PHP decode; no SQL predicate to index, and the MySQL JSON-`LIKE` normalisation trap makes introducing one actively dangerous), and anything on `health_checked_at` alone (every consumer wraps it in `COALESCE`).

## Recurring patterns

Three patterns now have enough repeat occurrences to be worth institutionalizing:

1. **`Builder::update()` silently skips observers, and this codebase leans on observers for freshness, caching, and now feature integrity.** Spec 035 fixed two sites; `AiAssignCategories.php:126` is a third, still open (M4). Grep for `->update([` on a query builder before shipping any bulk mutation.
2. **Unfixed LOW findings get amplified by the next spec.** The 2026-08-16 audit's L3 (`firstWhere` per picked id) was deferred as "milliseconds"; Spec 034 then added un-memoized `modelKey()` inside the same loop, turning it into an O(P²·K) hot path (M1). Deferred algorithmic findings in `SelectLandingPagePicks` should be re-triaged whenever that file is touched, not just when it gets slow.
3. **Jobs that swallow their final exception are invisible failures, and they get more dangerous when a caller starts deleting state up front.** `RescanProductFeatures.php:115-117` (H1). The `if ($this->attempts() < $this->tries) throw` idiom is redundant with Laravel's own retry gating and should be treated as an anti-pattern in review.

Worth recording as a *positive* pattern: `AuditLandingPageFreshnessJob`'s `ShouldBeUnique` needed no new code for Spec 035's queue-collapse requirement — it had been dormant since Spec 030 purely because the mass update never reached the observer. Shipping the dedup guard *before* the trigger that needs it turned out to be the cheap ordering.
