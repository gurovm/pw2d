# Performance Audit: Spec 037 T1 + T3b — AI Usage Instrumentation (commit `03d68d3`)

**Date:** 2026-08-22
**Status of code:** deployed to production
**Scope:** `GeminiService`, `AiService`, `AiUsageService`, `AiUsage`, `create_ai_usage_table` migration, `ListProducts` (T3b)

---

## Summary

> 1. **The synchronous INSERT is a non-issue and needs no mitigation.** On the three user-facing paths it adds ~1 ms to a request that already blocks on a 0.8–2.5 s Gemini round trip *and* already writes a `SearchLog` row in the same block. It is placed at the transport boundary, so it only ever fires when an HTTP call to Google has already happened — it is structurally incapable of being the dominant cost. Any queue/buffer mitigation would be over-engineering. **Do nothing.**
> 2. **The one real defect: `tenant_id` will be `NULL` for the highest-volume purposes in production.** `AiUsageService::record()` has a `$tenantId` parameter built precisely for this, and no production caller ever passes it. `evaluate_product` and `rescan_features` run on a queue worker with no tenancy bootstrapper, so `tenant('id')` returns null. The table's only index leads with `tenant_id`. This makes the spec's stated purpose (per-tenant cost attribution) not work, and makes index tuning meaningless until fixed. ~5-line fix.
> 3. **T3b is clean; the per-render query on that page is older code.** `estimateProductEvaluationCost()` is config-only — zero queries. The one thing genuinely running per-render that shouldn't is `ListProducts.php:72`, a pre-existing eager `Category::pluck()` feeding a modal that opens once a month.

**No CRITICAL findings.** Index, growth, and column sizing all come back "leave it alone" — see §Explicitly Validated.

---

## The headline question: is one INSERT on a user-facing request material?

**No. Not marginally — by roughly three orders of magnitude.** Reasoning, with numbers:

### Cost of the INSERT

`GeminiService.php:92` → `AiUsageService::record()` → one `AiUsage::create()` into a table with one secondary index.

| Component | Cost |
|---|---|
| PHP: Eloquent model build, casts, fill, `create()` events | ~0.05–0.15 ms |
| PDO round trip to MySQL on the same droplet | ~0.05–0.2 ms |
| InnoDB parse/execute + 1 secondary-index maintenance | ~0.05–0.2 ms |
| redo-log fsync (`innodb_flush_log_at_trx_commit=1`, autocommit, NVMe) | ~0.1–0.5 ms |
| **Total** | **~0.3–1.5 ms; call it ~1 ms. Pessimistic ceiling under contention: ~5 ms.** |

### Cost of the call it accompanies

All three user-facing purposes run on `site_model` (Gemini 2.5 Flash), with a 15 s client timeout:

| Purpose | Call site | Shape | Realistic wall clock |
|---|---|---|---|
| `parse_search_query` | `AiService.php:173`, from `GlobalSearch.php:158` | ~1,000 in / ~60 out, `thinkingBudget: 0` | **~700–1,800 ms** |
| `chat_response` | `AiService.php:235`, from `ProductCompare.php:598` | ~800 in / ~200 out, temp 0.4 | **~1,000–2,500 ms** |
| `match_product` (live ingest) | `AiService.php:328`, from `OfferIngestionService.php:149` | short, `thinkingBudget: 128` | **~800–2,000 ms** |

**Ratio: ~1 ms / ~1,200 ms = 0.08%.** At the pessimistic end, 5 ms / 800 ms = 0.6%. This is inside the jitter of a single Gemini call; it is not measurable by a user or by APM percentiles.

### Three structural reasons it can never become material

1. **It is on the slow path by construction.** The instrumentation lives at the transport boundary (`GeminiService::generate()`), not in `AiService`. The cheap paths never reach it: `matchProduct()`'s `ai_matching_decisions` cache hit returns at `AiService.php:270`, and the no-products-for-brand heuristic returns at `AiService.php:295` — both *before* `generate()` is called at `:323`. They pay exactly zero. An `AiUsage` INSERT is always preceded by a network call to Google.

2. **The same requests already do this, and it has never been a problem.** `GlobalSearch::performAiSearch()` writes a `SearchLog` row at `GlobalSearch.php:216` (or `:228` on error). `ProductCompare::analyzeUserNeeds()` writes one at `ProductCompare.php:614`. T1 changes these paths from **1 log INSERT to 2 log INSERTs**. If one was acceptable for the life of the project, two is.

3. **On the `chat_response` path the INSERT is not even the second-most expensive thing.** `ProductCompare.php:618` calls `$this->scoredProducts->count()` in the same `try` block to populate `SearchLog.results_count` — that is the full server-side scoring computation (90 s TTL cache, per `project_context.md` §8). One `INSERT` next to that is noise.

Also verified: **no AI call runs inside an open DB transaction anywhere** in `app/` (the only `DB::transaction` calls are in `MergeDuplicateProducts.php:84,132`, a console command). So there is no risk of the new INSERT extending a transaction that is already holding locks across a multi-second HTTP call.

### Recommended mitigation

**None.** Concretely, do *not*:

- dispatch the usage write to the queue — you would add a `jobs` row INSERT (same cost) *plus* serialization *plus* worker contention with `ProcessPendingProduct`, to avoid an INSERT;
- buffer in memory and flush on `terminate()` — adds state to a stateless service and loses rows on fatal errors, to save 1 ms;
- batch writes — the calls arrive one at a time on these paths; there is nothing to batch.

Each of these is more code and more failure modes for <0.1% of a request. This is precisely the over-engineering CLAUDE.md forbids. The existing design — one INSERT, at the transport boundary, wrapped in a `try/catch` that logs and continues (`AiUsageService.php:49-55`) — is the correct and simplest thing.

---

## High Priority

| Issue | Location | Impact | Fix |
|---|---|---|---|
| **`tenant_id` records as `NULL` for the two highest-volume purposes.** The `$tenantId` parameter added to `record()` for exactly this purpose has zero production callers. | `AiUsageService.php:33,41` (param + fallback) · `GeminiService.php:92` (only call site, omits it) · `config/tenancy.php:30-33` (empty `bootstrappers`) | The spec's stated deliverable — per-tenant cost attribution — silently does not work for `evaluate_product` (~350 rows/category, the dominant writer) or `rescan_features`. Also collapses the selectivity of the table's only index, whose leading column is `tenant_id`. | Thread the tenant through — ~5 lines, no architecture change. See below. |

**The chain, verified end to end:**

1. `GeminiService.php:92` calls `$this->usage->record($purpose, $model, $result['usageMetadata'] ?? [])` — the fourth parameter `?string $tenantId = null` (`AiUsageService.php:33`) is never supplied. It is the only production call site (confirmed by grep).
2. `record()` therefore always falls back to `tenant('id')` at `AiUsageService.php:41`.
3. `ProcessPendingProduct` runs on a Supervisor queue worker. `config/tenancy.php:30-33` has an **empty `bootstrappers` array** — no `QueueTenancyBootstrapper` — and `app/Providers/TenancyServiceProvider.php:42-47` registers only the initialize/end listeners. Tenancy is never initialized in the worker. The job compensates by passing `$product->tenant_id` explicitly (`ProcessPendingProduct.php:108`).
4. So `tenant('id')` → `null` for `evaluate_product` (`ProcessPendingProduct.php:74`) and `rescan_features` (`RescanProductFeatures.php:82`).
5. `match_product` is split: the job-originated call (`ProcessPendingProduct.php:108`) resolves `$tenantId` correctly at `AiService.php:261` but **never forwards it** to `generate()` at `AiService.php:323-328` → `NULL`. The live-ingest call (`OfferIngestionService.php:149`) is fine, because `routes/api.php:49-56` puts `/extension/ingest-offer` behind `InitializeTenancyFromPayload`.

**Attribution matrix as deployed:**

| Purpose | Context | `tenant_id` |
|---|---|---|
| `evaluate_product` | queue worker | **NULL** |
| `rescan_features` | queue worker | **NULL** |
| `match_product` (from job) | queue worker | **NULL** |
| `match_product` (live ingest) | API + `InitializeTenancyFromPayload` | correct |
| `parse_search_query`, `chat_response` | tenant-domain web request | correct |
| `sweep_category`, `assign_categories`, all content generators | console commands, which call `tenancy()->initialize()` (e.g. `AiSweepCategory.php:33`) | correct |
| `extract_product`, `analyze_search_trends` | Filament, tenancy bridged via `TenantSet` | correct |

The irony worth recording: `AiUsage.php:20-30` explains at length that `BelongsToTenant` was deliberately avoided because "relying on BelongsToTenant's auto-populate-on-create hook would silently record every Bouncer call as `tenant_id=null`" — and then the chosen alternative produces exactly that outcome, because the escape hatch was built and not wired up.

**Why the tests didn't catch it.** `AiUsageInstrumentationTest.php:69` and `:159-165` initialise tenancy before generating with `purpose: 'evaluate_product'` — asserting the one condition that never holds on that path in production. `AiUsageServiceTest.php:171-179` (`no_active_tenant_records_a_null_tenant_id`) does exercise the null case, but labels it `sweep_category` — a purpose that in production *does* have a tenant. The mapping is exactly inverted. There is no test that runs `ProcessPendingProduct` and asserts the resulting `ai_usage.tenant_id`.

**Fix (minimal, matches the existing explicit-tenant_id convention):**

```php
// GeminiService::generate(..., string $purpose = 'unspecified', ?string $tenantId = null)
$this->usage->record($purpose, $model, $result['usageMetadata'] ?? [], $tenantId);

// AiService::matchProduct() — $tenantId is already resolved at :261; just forward it
], config('services.gemini.site_model'), purpose: 'match_product', tenantId: $tenantId);

// AiService::evaluateProduct() / rescanFeatures() — add ?string $tenantId = null, forward it.
// ProcessPendingProduct.php:74 and RescanProductFeatures.php:82 pass $product->tenant_id.
```

**Do not** fix this by calling `tenancy()->initialize()` inside the jobs. That would switch `handle()` from explicit-`tenant_id` semantics to global-scope semantics mid-flight and change the behaviour of every query in it, including the deliberate `withoutGlobalScopes()` calls inside `matchProduct()`. Same outcome, far larger blast radius.

**Backfill:** existing production rows cannot be attributed retroactively — the row carries no `product_id` or request id. At current volume (single-digit thousands), accept the gap and note the cutover date. Do not add a column for this.

---

## Medium Priority

| Issue | Location | Impact | Fix |
|---|---|---|---|
| Eager `Category::pluck()` executes on **every** Livewire request to the Products list, to populate a modal opened perhaps monthly. Pre-existing; not from T3b, but it is the answer to "is anything running per-render that shouldn't." | `app/Filament/Resources/ProductResource/Pages/ListProducts.php:72` | One unnecessary `SELECT name, id FROM categories WHERE tenant_id = ?` per page load, per table sort, per search keystroke, per pagination click. ~0.3–1 ms each. Small, but strictly wasted. | Pass a Closure so Filament defers evaluation to modal mount: `->options(fn () => \App\Models\Category::pluck('name', 'id'))` |

`getHeaderActions()` is invoked once per Livewire request — Filament calls it from `bootedInteractsWithHeaderActions()` (`vendor/filament/filament/src/Pages/Concerns/InteractsWithHeaderActions.php:17-19`), and Livewire `boot` hooks run on every hydration, not just initial mount. Everything built inside that method is therefore per-request. Nothing in it runs per-row.

---

## Low Priority

| Issue | Location | Impact | Fix |
|---|---|---|---|
| Likely redundant single-column index on `tenant_id`, auto-created by InnoDB. The `foreign()` call is compiled before the composite `index()`, so at FK-creation time no index on `tenant_id` exists and InnoDB creates `ai_usage_tenant_id_foreign` itself; the later composite does not displace it. | `database/migrations/2026_08_22_000001_create_ai_usage_table.php:18` vs `:29` | ~30-40 bytes/row of index storage and one extra B-tree write per INSERT. At 30k rows: ~1 MB. Immaterial. | **Verify first** (`SHOW INDEX FROM ai_usage`), then do nothing. This is a repo-wide pattern (`create_landing_pages_table.php:33` vs `:36-40` is identical), so fixing it here alone buys nothing. Fold a `dropIndex` into any future `ai_usage` migration if one is ever needed for another reason. |
| `ListProducts.php:28` re-runs `Product::where('status','failed')->count()` uncached on every request, duplicating a value `ProductStatsWidget.php:23` already caches for 60 s on the same page. | `ListProducts.php:28` | Negligible — `products` has both a `status` index (`2026_03_07_184625_add_status_to_products_table.php:18`) and a `(tenant_id, status)` composite (`2026_03_21_120000_add_tenant_id_to_core_tables.php:57`), so under the `BelongsToTenant` scope this is an index-only count. Sub-millisecond. | **Leave it.** Sharing the 60 s cache would make the "Retry Failed (N)" label and its `visible()` guard lie for up to a minute after a retry. The correctness cost exceeds the perf gain. |

---

## Caching Recommendations

| Data | Current | Recommended TTL | Expected gain |
|---|---|---|---|
| `estimateProductEvaluationCost()` result | uncached, computed per request | **none — leave uncached** | Zero. It reads `config()` only. Caching an array lookup would be strictly slower. |
| `ai_usage` aggregate rollups | **does not exist yet** | If a dashboard is ever built: `Cache::remember(..., 300, ...)` + a bounded `created_at >=` window | Prevents an unbounded `GROUP BY` full scan on every widget render. The house pattern is already there — `ProductStatsWidget.php:18`. Forward-looking guard, not a current finding. |
| `Product::where('status','failed')->count()` on ListProducts | uncached per request | **none — leave uncached** | Index-only count; caching costs correctness (stale button label). |

---

## Index Recommendations

**None. Do not write a migration.** That is the honest answer at current and projected volume.

The reasoning, so it isn't re-litigated:

The table has exactly one declared index, `(tenant_id, purpose, created_at)` (`migration:29`), added to satisfy the `project_context.md` §11 directive that composite indexes lead with `tenant_id`. Measured against the queries this data actually exists to serve:

| Query shape | Served by `(tenant_id, purpose, created_at)`? |
|---|---|
| `SELECT purpose, COUNT(*), AVG(input_tokens), AVG(output_tokens), SUM(estimated_cost_usd) GROUP BY purpose` — **the spec's own acceptance query** (spec 037 §2, lines 121-123) | **No.** No `WHERE` clause at all. Full table scan. |
| `WHERE tenant_id = ? AND created_at >= ?` — per-tenant spend in a window | **Partially.** Only the `tenant_id` equality prefix is used for seeking. `purpose` sits between the equality and the range, so the `created_at` range cannot be used — MySQL scans every row for that tenant and filters. Classic middle-column problem. |
| `WHERE created_at >= ?` cross-tenant — the "central cost dashboard" `AiUsage.php:26-28` explicitly anticipates | **No.** Leading column absent. Full scan. |
| `WHERE tenant_id = ? AND purpose = ? AND created_at BETWEEN ? AND ?` | **Yes, perfectly.** This is the only shape it serves — and the least likely of the four. |

Two further points:

- **It is not a covering index for any of them.** None of `input_tokens`, `output_tokens`, `thinking_tokens`, `estimated_cost_usd` are in it, so every aggregate must return to the clustered index regardless. That pushes the optimizer toward a full scan even where the index is technically usable.
- **Its leading column is currently `NULL` for the majority of rows** (see the HIGH finding). An index leading on a column with cardinality ≈ 3, where most rows share one value, offers no selectivity where the rows actually are. **Any index work on this table before the tenant fix is wasted effort.**

Is it over- or under-indexed? Neither meaningfully. It carries one composite it rarely uses plus one likely FK auto-index — roughly 50 bytes/row and two B-tree writes per INSERT, on a table taking ~600 rows per category import. That is not worth a migration to correct.

**Recommendation: leave it alone.** Revisit only when an `ai_usage` reporting surface actually ships, and choose the index against that surface's real query — most likely `(created_at)` alone, or `(tenant_id, created_at, purpose)` if the per-tenant window query dominates. Choosing now is guessing.

---

## Table Growth

**One row per AI call. Verdict: nothing degrades. No pruning, no partitioning, no archive job.**

Per Tier-3 category top-up (~350 products):

| Purpose | Rows | Basis |
|---|---|---|
| `evaluate_product` | 350 | one per product, unconditional (`ProcessPendingProduct.php:74`) |
| `match_product` | ~150–250 | ≤350, short-circuited by the `ai_matching_decisions` cache (`AiService.php:264-271`) and the no-products-for-brand heuristic (`:276-296`) |
| `sweep_category` | ~14 | chunks of 25 |
| content generators (compare / preset / landing page) | ~5–15 | per-category, one-off |
| **Total** | **~520–630 → call it ~600 rows per category** | |

Projections:

| Scenario | Rows/year |
|---|---|
| Pipeline only — 5 categories/quarter | ~12,000 |
| \+ user-facing at today's traffic | ~13,000 |
| \+ user-facing at 50 AI interactions/day | ~30,000 |
| \+ user-facing at 500/day (a genuinely successful site) | ~190,000 |

Both user-facing writers are rate-limited to 10 AI calls/min/session (`GlobalSearch.php:110`, `ProductCompare.php:574`), which caps the abuse ceiling.

Row footprint: ~110 bytes of data (id 8 + tenant_id ~10 + purpose ~16 + model ~16 + 3×int 12 + decimal(10,6) 5 + timestamp 4 + InnoDB row overhead ~27) plus ~50 bytes of index ≈ **~160–200 bytes/row all-in**.

- 30k rows ≈ **6 MB**
- 200k rows ≈ **40 MB**
- 1M rows ≈ **200 MB**

**Degradation threshold.** The binding constraint is the unindexed `GROUP BY purpose` aggregate. InnoDB scans ~1–5M rows/sec from a warm buffer pool:

| Rows | Full-scan aggregate |
|---|---|
| 30k | **2–10 ms** — invisible |
| 200k | ~50–150 ms — noticeable in a widget, fine in a report |
| 1M | ~0.2–1 s — would want an index and a date window |
| 5M | seconds — would want pruning |

**Nothing degrades below ~500k rows.** On the pipeline workload alone that is 40+ years out; even at 500 user-facing AI calls/day it is ~5 years. Adding a prune command, a partition scheme, or a retention policy today would be over-engineering by a wide margin. The single guard worth recording: **any future `ai_usage` dashboard must bound its aggregate with a `created_at >=` window and cache the result** (`ProductStatsWidget.php:18` is the house pattern), rather than scanning the table per render.

---

## Column Sizing

`varchar(255)` on `purpose` and `model` (`migration:19-20`), both drawing from small fixed sets.

**Verdict: premature. Zero measurable gain. Leave as-is.**

- **On-disk row size is identical.** InnoDB VARCHAR is length-prefixed and variable-length. `'evaluate_product'` (16 chars) occupies 16 bytes + 1 length byte whether the column is declared `varchar(32)` or `varchar(255)`. The declared length is a maximum, not an allocation.
- **Index size is identical**, for the same reason — index entries store the actual value.
- Where declared length *does* bite, and why neither applies here:
  - **The 3072-byte InnoDB index key limit.** `(tenant_id varchar(255), purpose varchar(255), created_at)` under `utf8mb4` declares 1020 + 1020 + 5 = **2045 bytes**. It fits, but with no headroom for a fourth VARCHAR column. Worth knowing if this index is ever extended; not a reason to change anything now.
  - **In-memory temp tables for `GROUP BY purpose`.** MySQL 8's TempTable engine handles VARCHAR far better than the old fixed-width MEMORY engine, and with ~10 distinct purposes the difference is single-digit KB.

The only defensible argument for tightening is **data integrity** (constraining `purpose` to the known set) — a correctness argument, not a performance one. Even there I would argue against it: an `ENUM` or a short `varchar` with a check constraint would require a migration every time a new `AiService` method is added, which is exactly the brittleness the pricing-in-config decision (`config/services.php:47-53`) was designed to avoid.

---

## Explicitly Validated — checked, and a non-issue

Recorded so these are not re-audited.

| Checked | Verdict |
|---|---|
| Extra INSERT on `parse_search_query` / `chat_response` / live `match_product` | **Non-issue.** ~1 ms against a 0.8–2.5 s Gemini call (~0.08%); the same request already writes a `SearchLog` row; and on the `chat_response` path `ProductCompare.php:618` already triggers the full scoring computation. No mitigation warranted. |
| Instrumentation placement | **Correct.** At the transport boundary (`GeminiService.php:92`), so cache hits and heuristic short-circuits in `matchProduct()` pay zero. It cannot fire without a preceding network call to Google. |
| Failure isolation | **Correct.** `try/catch` at `AiUsageService.php:35-55` logs and continues; unknown model returns `null` cost rather than throwing (`:76-78`). A dropped table does not fail the AI call — covered by `AiUsageInstrumentationTest.php:138-149`. Meets the spec's "never let accounting break ingestion" rule. |
| AI calls inside DB transactions | **None.** Only `MergeDuplicateProducts.php:84,132` use `DB::transaction`, and neither wraps an AI call. No risk of holding locks across a multi-second HTTP round trip. |
| `estimateProductEvaluationCost()` — does it query? | **No.** `AiUsageService.php:97-104` → `estimateCost()` → `config('services.gemini.pricing')` (`:74`) and `config('services.gemini.admin_model')`. Config only; zero DB, zero I/O. With `config:cache` on production it is an in-memory array lookup. `app(AiUsageService::class)` is a reflection build of a zero-arg constructor, ~1–3 µs, once per request. |
| Anything per-row on `ListProducts` | **No.** Everything in `getHeaderActions()` is per-request, not per-row. The `->each()` in the retryFailed handler (`:52`) chunks and only runs on click. |
| Dot-notation trap in the pricing lookup | **Correctly handled.** `AiUsageService.php:70-74` fetches the whole pricing map and indexes by the literal model string, because Gemini model names contain dots that `config()` would misparse. The comment explains why. Copying a 5-element array per call is free. |
| `decimal(10,6)` for `estimated_cost_usd` | **Fine.** Ceiling $9,999.999999 against per-call costs of ~$0.01. `SUM()` over DECIMAL is exact — the right choice over FLOAT for money. |
| `unsignedInteger` for token counts | **Fine.** 4.29B ceiling against per-call counts in the thousands. |
| `const UPDATED_AT = null` (`AiUsage.php:50`) | **Correct and slightly beneficial.** Append-only log; skips one column write and keeps the row narrower. |
| Default-instantiated `AiUsageService` in the `GeminiService` constructor (`:18`) | **Harmless.** The container injects the type-hinted dependency in normal resolution; the default only applies to manual `new GeminiService()`, which is what the tests do. No per-call allocation on the hot path. |
| Table growth / need for pruning | **No action.** ~13k–30k rows/year realistically; nothing degrades below ~500k. See §Table Growth. |
| Index fitness | **Leave alone.** Not the ideal index, but at this volume a full scan is 2–10 ms. See §Index Recommendations. |
| Column sizing | **Premature.** Zero on-disk or index difference. See §Column Sizing. |

---

## Recommended order of work

1. Thread `tenant_id` through to `AiUsageService::record()` (~5 lines) and add a test that runs `ProcessPendingProduct` and asserts a non-null `ai_usage.tenant_id`. Everything else on this table depends on this being right.
2. Closure-wrap `ListProducts.php:72` (one line).
3. Nothing else. Re-open the index question only when a reporting surface exists to tune against.
