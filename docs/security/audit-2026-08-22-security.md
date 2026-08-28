# Security Audit — Spec 037 T1 + T3b (commit `03d68d3`)

**Date:** 2026-08-22 · **Status:** deployed to production
**Verdict:** **No rollback, no hotfix.** Nothing in this commit is exploitable. The primary finding is
a data-integrity defect that silently defeats the feature's own purpose, and the model docblock's
justification for it is factually incorrect.

## Critical — none

Empty, and deliberately not padded. No cross-tenant read path, no secret leakage, no injection vector,
no auth bypass, no config exposure. Nothing warrants touching production out of band.

## High

### H1 — The dominant write paths record `tenant_id = NULL`, and the stated reason for dropping `BelongsToTenant` does not hold
`app/Services/AiUsageService.php:41`, `app/Services/GeminiService.php:92`. Data integrity, not exploitable.

The premise is correct: `config/tenancy.php` registers **zero** bootstrappers, so `QueueTenancyBootstrapper`
never runs and a `database`-queue worker has no ambient tenant.

The **conclusion** does not follow. `BelongsToTenant::bootBelongsToTenant()` stamps `tenant_id` only
`if (tenancy()->initialized)` — the same condition that makes `tenant('id')` return null. On the queue
path the trait and the explicit resolution produce an **identical** result: `NULL`. The docblock at
`AiUsage.php:20-27` asserts the trait "would silently record every Bouncer call as tenant_id=null" —
true, but the shipped code does exactly that too.

**Live consequence in production:**

| Purpose | Caller | Tenancy | Recorded `tenant_id` |
|---|---|---|---|
| `evaluate_product` | `ProcessPendingProduct.php:74` (queue) | not initialized | **NULL** |
| `rescan_features` | `RescanProductFeatures.php:82` (queue) | not initialized | **NULL** |
| `match_product` | `ProcessPendingProduct.php:108` (queue) | not initialized | **NULL** |
| `match_product` | `OfferIngestionService.php:149` (HTTP) | initialized | correct |
| `parse_search_query` / `chat_response` | `GlobalSearch.php:158`, `ProductCompare.php:598` | initialized on tenant domain | correct |
| commands + Filament calls | `tenancy()->initialize()` / `TenantSet` bridge | initialized | correct |

Per spec §1, `evaluateProduct()` **is** the dominant cost (~78% of per-product spend). So the largest
cost bucket, every rescan, and the queue half of `match_product` are unattributable.

Sharpest illustration: `AiService::matchProduct()` resolves `$tenantId` at `:261`, uses it for every DB
query in the method (`:265, :277, :290, :340, :351`) — then calls `generate()` at `:323-328` without
forwarding it. The tenant is in a local variable two lines away, and is discarded.

**Fix** — the plumbing already exists; `AiUsageService::record()` already accepts `?string $tenantId`:
add `?string $tenantId = null` to `GeminiService::generate()` and forward it to `record()`; pass it from
the 13 `AiService` call sites; have `ProcessPendingProduct:74` and `RescanProductFeatures:82` pass
`$product->tenant_id`, exactly as `ProcessPendingProduct:108` already does.

Backfill of existing NULL rows is approximate at best (join `created_at` against product timestamps).
Simpler to accept the gap and record the cut-over date wherever the data is reported.

### On the `SeoMetric` precedent — analogous in shape, inverted in safety
- **Same:** no trait, no global scope, append-only observability table read cross-tenant. Omitting the
  trait is sound.
- **Different, and it is the half that matters:** `seo_metrics.tenant_id` is **NOT NULL**
  (`create_seo_metrics_table.php:15`) and its writers take the tenant as an **argument**
  (`PullGscMetrics.php:120`, `PullGa4Metrics.php:57` use `$tenant->getTenantKey()`). A null-attributed
  `seo_metrics` row is a database error; it cannot happen.

`ai_usage` copied the trait omission and dropped **both** safeguards: nullable column, value from
process-global ambient state. The spec text said "**`tenant_id` required**"; the migration made it
nullable. The fix is the explicit argument, not the constraint — `parse_search_query` from the central
domain is a legitimate null.

## Medium

- **M1 — Read scoping is fail-open for every future reader.** `AiUsage::sum('estimated_cost_usd')`
  returns all tenants' spend. **Zero** non-test readers exist today, so no live exposure — but the first
  widget someone writes will be unscoped by default, and NULL rows mean a *correctly* scoped query
  silently undercounts. Add `scopeForTenant()` / `scopeAllTenants()` so the safe form is the easy form.
- **M2 — Ambient-tenant resolution inherits the worker's global state.** `record()` reads `tenant('id')`
  — mutable process-global state — not the tenant of the work. No bleed today, but `AiSweepCategory.php:33`
  and `AiAssignCategories.php:30` already `initialize()` with no `end()`, harmless only because they are
  one-shot artisan processes. The day that pattern lands in a queued job, every later AI call in that
  worker bills the leaked tenant. The H1 fix removes the dependency on ambient state entirely — that is
  the real reason to do it, not just the NULLs.
- **M3 — Unbounded growth with an unauthenticated write amplifier.** `parse_search_query` and
  `chat_response` are anonymous-triggered with no rate limit and now also insert a row. Honest scoping:
  the table is **not** the problem (tens of thousands of rows/year), and an abuse loop's Gemini bill
  dwarfs the storage. The pre-existing missing throttle on the two public AI entry points is the real
  issue. Cheap mitigation worth doing anyway: `MassPrunable` + `model:prune` daily.

## Low / informational

- **Secret leakage: clean, every path traced.** The API key travels only as an `x-goog-api-key`
  **header**, never in a URL — so even an uncaught `ConnectionException` message is key-free. Both
  exception messages GeminiService constructs interpolate `$status` only, never `$response->body()`.
  `AiUsageService.php:50-54` logs `purpose`, `model`, `$e->getMessage()`; realistic worst case is a
  `QueryException` interpolating its bindings — tenant_id, purpose, model, three ints, a decimal.
- **Mass assignment: no risk.** `record()` is the sole writer, builds from typed locals. `tenant_id`
  being fillable is correct and necessary.
- **Injection: none.** All 13 call sites pass a **literal** `purpose`; `model` derives from config → env.
- **The tenant-attribution test validates the path that works, not the one that is broken.**
  `AiUsageInstrumentationTest.php:152-170` manually calls `tenancy()->initialize()` — a state that never
  occurs on the `evaluate_product` path. It passes and gives false confidence. Add a test that dispatches
  the real `ProcessPendingProduct` and asserts `AiUsage::sole()->tenant_id === $product->tenant_id`;
  it fails today and passes after H1.
- **`nullOnDelete()` merges deleted tenants into the untagged bucket** (`:18`). After H1, NULL would
  cleanly mean "central/console call"; with `nullOnDelete` it also means "tenant since deleted".
- **The index does not serve the spec's own acceptance query.** `(tenant_id, purpose, created_at)`
  cannot serve `GROUP BY purpose` with no `tenant_id` predicate. Irrelevant at current row counts, but
  worth knowing before someone concludes the index "isn't working."
- **Config co-location nit.** The display-safe pricing map now shares an array with `GEMINI_API_KEY`.
  Consider `services.gemini_pricing` or `config/ai-pricing.php`.
- **`generateCategoryImage()` bypasses instrumentation entirely** — any `ai_usage` total is an
  undercount. Pre-existing, already in todo.
- **Pre-existing, adjacent:** `AiService.php:578` puts a raw upstream body into an exception; truncate it.

## Passed checks

- No cross-tenant read path exists — zero non-test readers of `AiUsage` anywhere.
- Even a hypothetical unscoped admin reader is not privilege escalation: `User::getTenants()` returns
  `Tenant::all()` and `canAccessTenant()` returns `true` unconditionally, so Filament tenancy here is a
  UI context switcher, not an authorization boundary. This is what keeps M1 at Medium.
- **Accounting cannot break ingestion.** `record()` is total, `estimateCost()` returns null for an
  unpriced model, and the write is placed before the `MAX_TOKENS` throw so truncated-but-billed calls are
  recorded. Both spec safety rules correctly implemented and tested.
- The `config(...)['model']` array-index workaround is correct — dot-notation would mis-parse
  `gemini-2.5-flash`. Well documented at `AiUsageService.php:70-74`.
- No new routes, no endpoint changes — the CLAUDE.md popup/content sync rule correctly not triggered.
- **T3b is genuinely better than what it replaced** — cost derived from config with a null-safe
  fallback, no XSS surface.
