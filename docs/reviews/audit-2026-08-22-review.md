# Review: Spec 037 T1 + T3b — AI usage instrumentation (commit `03d68d3`)

**Date:** 2026-08-22
**Reviewer:** @reviewer
**Status:** Needs changes — 1 critical, 3 high
**Deployment state:** already live in production. Findings are ranked by *hotfix vs. wait* below.

**Hotfix now (before the next import batch):** C-1.
**Hotfix now (independent of batch timing):** the `estimateProductEvaluationCost()` null-guard inside M-1.
**Everything else can wait** for the T2 pass.

---

## Critical Issues (must fix)

### C-1 — Every queued Bouncer call records `tenant_id = NULL`. The dominant workload is unattributable, and the loss is unrecoverable.

`app/Services/AiUsageService.php:41` · `app/Services/GeminiService.php:92` · `app/Models/AiUsage.php:20-33`

`record()` resolves the tenant as `$tenantId ?? tenant('id')`. The `$tenantId` parameter
(`AiUsageService.php:33`) is **dead** — no caller anywhere in `app/` passes it, because
`GeminiService::generate()` neither accepts nor forwards a tenant. So attribution reduces entirely to
`tenant('id')`.

`tenant('id')` returns `null` unless tenancy is initialized in the current process. Verified:

- `config/tenancy.php:30-33` — `'bootstrappers' => []`, empty.
- `vendor/stancl/tenancy/src/TenancyServiceProvider.php:49-55` — `QueueTenancyBootstrapper::__constructStatic()`
  is only invoked for bootstrappers listed in that array. It is not listed, so the `JobProcessing`
  listener (`QueueTenancyBootstrapper.php:62-66`) and the `createPayloadUsing` tenant stamp
  (`:128`) are **never registered**.
- `app/Providers/TenancyServiceProvider.php` registers no queue hook either.

Therefore every AI call made from a queued job records `tenant_id = NULL`:

| Purpose | Caller | Tenancy at call time | Recorded `tenant_id` |
|---|---|---|---|
| `evaluate_product` | `ProcessPendingProduct` (queue) | none | **NULL** |
| `match_product` | `ProcessPendingProduct:108` (queue) | none | **NULL** |
| `rescan_features` | `RescanProductFeatures` (queue) | none | **NULL** |
| `match_product` | `OfferIngestionService` (API request) | `InitializeTenancyFromPayload` | correct |
| `sweep_category`, `assign_categories`, `*_content` | artisan commands | `tenancy()->initialize()` | correct |
| `parse_search_query`, `chat_response` | web request | tenant domain only | NULL on `pw2d.com` |

Those first three rows are ~100% of the spend the spec was written to measure. Spec 037 §1 states
`evaluateProduct()` "is the dominant call — one per imported product."

**This directly contradicts the model's own documentation.** `AiUsage.php:22-27` says
`BelongsToTenant` was deliberately omitted because relying on its auto-populate hook "would silently
record every Bouncer call as `tenant_id=null`, which is wrong for a table whose entire purpose is
per-tenant cost attribution" — and then `:29-31` claims explicit resolution from `tenant('id')` fixes
it. It does not. Both mechanisms produce the identical NULL. The rationale is sound; the
implementation does not deliver it.

**Concrete failure scenario.** Tomorrow's `gaming-chat-headsets` top-up (79 products, per
`docs/summaries/2026-08-22-ai-cost-instrumentation-session-handoff.md`) runs on `coffee2decide` and
`pw2d` in the same session. All ~160 resulting `evaluate_product` + `match_product` rows land with
`tenant_id = NULL`. `SELECT SUM(estimated_cost_usd) FROM ai_usage WHERE tenant_id = 'coffee-decide'`
returns 0.00 while real money was spent. The composite index `(tenant_id, purpose, created_at)`
leads with a column that is NULL for the workload it was built to serve.

**Why this is a hotfix and not a "fix in T2":** the acceptance query in spec 037 §2 and in the handoff
(`GROUP BY purpose`, no tenant column) **will not surface this** — it aggregates across the NULL
bucket and returns plausible-looking numbers. And the rows carry no `product_id`, `job_id`, or any
other back-reference, so once written they cannot be retro-attributed. Every batch run before the fix
is permanently anonymous.

**Fix direction (smallest correct change, no tenancy-semantics blast radius):**

1. Add `?string $tenantId = null` to `GeminiService::generate()` and forward it to `record()`.
2. `AiService::matchProduct()` is a **one-argument fix** — it already resolves `$tenantId` at
   `AiService.php:261` and threads it into every DB write in the method, but omits it from the
   `generate()` call at `:323-328`.
3. `evaluateProduct()` / `rescanFeatures()` take a `?string $tenantId` and the two jobs pass
   `$product->tenant_id`, which both already have loaded.
4. Add a test that runs `ProcessPendingProduct` with tenancy **not** initialized and asserts the
   `ai_usage` row carries the product's tenant. That is the test that is missing today (see H-1).

Do **not** fix this by adding `QueueTenancyBootstrapper` or by wrapping `handle()` in
`tenancy()->initialize()` — both jobs currently rely on global scopes being off
(`ProcessPendingProduct.php:165`, `:189`, `:196` all use `withoutGlobalScopes()` with explicit
`tenant_id`), and turning tenancy on inside them would change the meaning of the unscoped queries at
`:114`, `:148`, and `:302`. That is a separate spec.

---

## High

### H-1 — 11 of the 13 `purpose` strings have zero test coverage. The field the whole table exists for is unpinned.

`app/Services/AiService.php:98, 130, 173, 235, 328, 406, 458, 526, 542, 622, 723, 853, 1052`

The commit threads `purpose:` through 13 call sites. Grepping the suite for `purpose` assertions
finds exactly two: `evaluate_product` (`AiUsageInstrumentationTest.php:86`) and the `unspecified`
default (`:103`). `rescan_features`, `match_product`, `parse_search_query`, `chat_response`,
`sweep_category`, `assign_categories`, `analyze_search_trends`, `category_content`,
`extract_product`, `preset_content`, `compare_content`, `landing_page_content` are all unverified.

Spec 037 §2 T1: *"This is the field that makes the data useful; without it every row is anonymous."*

**Concrete failure scenario.** A copy-paste during the 13-site edit leaves `rescanFeatures()` passing
`purpose: 'evaluate_product'`. Nothing fails. `GROUP BY purpose` then shows `evaluate_product` at an
inflated count and a *deflated* average output-token cost (rescan prompts are much shorter), because
two different workloads are merged into one bucket. Spec 037 §1's cost model gets "corrected" against
contaminated data, and T3's ship decision is made on it. Cost-attribution bugs are self-concealing —
the output is always a plausible number.

The fixture for this already exists: `tests/Feature/Ai/GenerateCompareContentTest.php:52` and
`GeneratePresetContentTest.php:60, 347, 382` already subclass `GeminiService` and were updated to the
new signature — they receive `$purpose` and discard it. Capturing it and asserting is a few lines.
A single data-provider test over all 13 methods with `Http::fake()` would close this properly.

### H-2 — The one non-obvious design decision in the commit — recording usage *before* the `MAX_TOKENS` throw — is not tested.

`app/Services/GeminiService.php:91-96`

The placement is **correct** (see Q4 below), and it is correctly commented. It is also the single
thing in this commit a future refactor is most likely to get wrong, and nothing pins it.
`tests/Unit/GeminiServiceTest.php:91-107` exercises the `MAX_TOKENS` throw but predates this commit
and asserts nothing about `ai_usage`. `AiUsageInstrumentationTest` never sends a `MAX_TOKENS`
response at all.

**Concrete failure scenario.** T3 (`admin_model` → 3.7 Flash) touches exactly this method to add the
`thinkingBudget` → `thinking_level` translation. Someone tidies the method by hoisting the
`finishReason` guard above the parsing block — a natural-looking cleanup — and `record()` silently
moves below the throw. `generateLandingPageContent`, which spec 037 §2 T3 says *currently times out /
truncates on 2.5 Pro*, is the highest-token-count call on the platform, and its cost vanishes from
`ai_usage` entirely. The measurement disappears precisely for the call whose cost the spec most wants
to know.

Also missing: a test asserting that a non-2xx response writes **no** row (`GeminiService.php:79-86`).
That is a deliberate choice and should be pinned as one.

### H-3 — T3b shipped with no test, and its "can never drift again" claim is only half true.

`app/Filament/Resources/ProductResource/Pages/ListProducts.php:30-35` ·
`app/Services/AiUsageService.php:24-25, 97-104`

`estimateProductEvaluationCost()` and the Filament modal copy that consumes it have **zero** test
coverage. CLAUDE.md: *"Every new feature, API endpoint, or core logic update MUST have tests."* This
is the user-visible half of the commit and the specific thing spec 037 §7 called out.

Separately, the comment at `ListProducts.php:30-31` — *"Driven by `config('services.gemini.pricing')`
so this copy can never drift from reality the way the old hardcoded `~$0.03` guess did"* — overstates
what was fixed. Cost = tokens × price. **Price** is now config-driven. **Tokens** are still two
hardcoded guesses (`AiUsageService.php:24-25`, 1800/800), and tokens are the half that actually
drifts, because prompts change and price changes are deliberate `.env`/config edits. The prompt at
`AiService.php:39-93` has grown repeatedly (Stage 2.5 name normalization, IGNORE RULE C).

**Concrete failure scenario.** Spec 037 §2 T3 explicitly plans prompt work if T2 shows drift. A prompt
grows by 40%. The modal keeps quoting `$0.0103` from the stale 1800-token constant, with a comment
asserting it cannot drift — which is *more* misleading than the old `$0.03`, because the old number
was visibly a guess and this one looks measured.

`AiUsageService.php:21-23` already acknowledges the right fix ("Once `ai_usage` has real
`evaluate_product` rows, prefer `AVG(...)`-driven copy instead"). That is a cached one-liner. Do it
as part of the same pass that reads the first T1 data, and correct the comment now.

---

## Medium

### M-1 — The "accounting can never break the AI call" guarantee has two holes.

`app/Services/AiUsageService.php:33, 47, 49-55` · `app/Services/GeminiService.php:36, 92` ·
`ListProducts.php:32`

**(a) `estimateCost()` is called *inside* the try block** (`:47`), inside the `AiUsage::create()`
argument array. Spec 037 §2 T1 requires that an unpriced model "logs tokens with
`estimated_cost_usd = null` rather than throwing". That holds for a *missing* model
(`estimateCost():76` returns null), but not for a *malformed* one. `'pricing' => ['gemini-3.7-flash'
=> ['input' => '0.75', ...]]` written as a quoted string — an easy `.env`/config typo during the T3
migration — makes `$billedInput * $pricing['input']` a `TypeError` on a non-numeric string, which the
`\Throwable` catch swallows. The result is not a null-cost row; it is **no row at all**. The token
counts, which were fine, are thrown away too. Compute the cost before the try, or catch narrowly
around the arithmetic and fall back to null.

**(b) A null model string escapes the guarantee entirely.** Under `declare(strict_types=1)`, argument
type coercion happens *before* the function body, so a `TypeError` on `record(string $model, ...)` is
raised at the **call boundary** and the try/catch inside `record()` cannot see it.
`GeminiService.php:36` computes `$model = $model ?? config('services.gemini.site_model')` and passes
it straight to `record()` at `:92`. `config()` returns `mixed`; `env('AGENT_SITE_MODEL', ...)`
resolves to `null` if the `.env` key is set to the literal string `null`.

**Concrete failure scenario.** `AGENT_ADMIN_MODEL=null` in `.env` on a server (a plausible way to
"unset" it). Two things break: every `admin_model` AI call now dies with an uncaught `TypeError` from
the accounting layer — the exact outcome the design forbids — and `ListProducts.php:32` calls
`estimateProductEvaluationCost()` at header-render time with the same null, **500-ing the entire
Filament Products list page**, which is the operator's primary console. That second one is a new
uncaught call path introduced on a page load by this commit and is worth folding into the C-1 hotfix.
`(string) config(...)` at both sites is the whole fix.

### M-2 — The swallow log line cannot diagnose or reconstruct what was lost.

`app/Services/AiUsageService.php:49-55`

The catch is the right call — accounting must not break ingestion — but the log carries only
`purpose`, `model`, and `$e->getMessage()`. Missing: the resolved `tenant_id`, the three token counts,
the exception class, and any stack context.

**Concrete failure scenario.** A deploy where `migrate --force` fails partway. `ai_usage` does not
exist. A 350-product category import runs to completion, looking entirely healthy — every product
scores, every page renders. `laravel.log` gains 700 identical `warning`-level lines that say
`Base table or view not found` and nothing else, buried among normal traffic. Nobody looks. The
category's entire spend is gone, and it cannot be reconstructed from the logs either, because the
token counts — the only irreplaceable data in the row — were never logged. Adding `tenant_id`,
`input_tokens`, `output_tokens`, `thinking_tokens`, and `get_class($e)` to the context turns a
silent loss into a recoverable one.

Consider `Log::error` rather than `warning`. Total, permanent, invisible loss of the data a table was
just built to collect is not a warning.

### M-3 — 13 magic string literals with no enum or constant.

`app/Services/AiService.php:98, 130, 173, 235, 328, 406, 458, 526, 542, 622, 723, 853, 1052`

The purpose vocabulary is the table's primary grouping key and exists only as scattered string
literals. CLAUDE.md asks for "clean, modern PHP 8.3". A backed `enum AiPurpose: string` would make
H-1's failure mode impossible at the type level rather than merely testable, give the future cost
dashboard a canonical list to iterate, and cost about 20 lines. `GeminiService::generate()` would take
`AiPurpose $purpose = AiPurpose::Unspecified` and store `->value`.

Minor consistency note while you are there: eight purposes are `verb_noun`
(`evaluate_product`, `sweep_category`, `analyze_search_trends`) and five are `noun_noun`
(`category_content`, `chat_response`). That split is defensible (actions vs. content types) but
should be a documented convention on the enum, not an accident.

### M-4 — `generateCategoryImage()` bypasses `GeminiService`, so image spend is invisible and the CLAUDE.md transport boundary is broken.

`app/Services/AiService.php:557-593` (direct `Http::post` at `:566-575`)

Correctly identified as out of scope by the builder and logged in the handoff, so this is
confirmation rather than a new finding — with two additions.

First, **completeness is confirmed**: grepping `generativelanguage.googleapis.com` across `app/`
returns exactly two hits — `GeminiService.php:67` and `AiService.php:568`. Nothing else was missed.
All 13 `AiService → generate()` call sites carry a `purpose`. The audit is complete.

Second, the pricing map (`config/services.php:47-53`) does not contain `gemini-2.5-flash-image`, so
this gap is now doubly invisible: even if the call were routed through `GeminiService` tomorrow, it
would record a null cost. Adding the image model's pricing is a one-line prerequisite for closing this.

**Concrete failure scenario.** Image generation is per-category and rare, so the absolute spend is
small — but it is the only AI cost that will be *structurally* missing from `ai_usage`. Any future
claim of the form "total platform AI spend this quarter = `SELECT SUM(estimated_cost_usd)`" is
silently understated, and nothing in the schema or the query signals it.

### M-5 — The index serves only tenant-first queries; the two queries the design actually names are unindexed.

`database/migrations/2026_08_22_000001_create_ai_usage_table.php:29`

`INDEX (tenant_id, purpose, created_at)` correctly satisfies the `project_context.md` §11 architect
directive, and that is the right leading column. But:

- The spec's own acceptance query (§2 T1) is `SELECT purpose, COUNT(*), AVG(...), SUM(...) FROM
  ai_usage GROUP BY purpose` — no tenant predicate, full scan.
- `AiUsage.php:26-28` names "a future cross-tenant cost dashboard summing spend across all tenants"
  as an explicit design driver. `WHERE created_at >= ?` cannot use a `tenant_id`-leading index.

**Concrete failure scenario.** At two rows per product across all tenants and categories, this table
reaches six figures within a few quarters of normal operation. A "spend, last 30 days, all tenants"
panel then full-scans it on every admin page load, with no cache. Not urgent, but the fix is one line
in a follow-up migration: add `index(['created_at'])` or `index(['purpose', 'created_at'])`.

Related, lower stakes: `AiUsage` is an unbounded append-only log with no `Prunable` trait and no
retention policy. Volume is small enough that this can wait, but it should be decided rather than
defaulted.

---

## Low

**L-1 — `new AiUsageService()` in the constructor initializer is a smell, not a bug.**
`app/Services/GeminiService.php:18`. Verified: when `GeminiService` is resolved through the container
(`app(AiService::class)` → autowire), Laravel resolves the class-typed parameter via `resolveClass()`
and **only** falls back to the default on `BindingResolutionException`. `AiUsageService` is concrete
with no dependencies, so the container path never touches the default. No production behaviour
changes. Two real costs: (1) the default exists solely so tests can write `new GeminiService()` —
which they do, at `AiUsageInstrumentationTest.php:78, 101, 111, 126, 145, 160, 164` and throughout
`GeminiServiceTest` — meaning **no test exercises container resolution of this class at all**;
(2) *concrete future failure*: the moment anyone binds a decorated `AiUsageService` (a buffered
writer, a null-object for tests, a Prometheus wrapper), every `new GeminiService()` silently gets the
undecorated one and the decoration appears not to work. Drop the default; use
`app(GeminiService::class)` in tests. Low priority — but note it is one constructor argument away
from also being the natural fix vehicle for C-1.

**L-2 — `database/factories/AiUsageFactory.php` is unused.** No test or seeder references
`AiUsage::factory()` (grep confirms; the only hit outside the factory itself is a docs mention).
Standards checklist: no dead code. Either use it — it would simplify the tenant-scoping tests — or
delete it.

**L-3 — `catch (RequestException)` at `GeminiService.php:70-77` is unreachable.** With
`throw: false` on the retry (`:63`), `PendingRequest::send()` only calls `$response->throw()` when
`$attempt < $potentialTries && $shouldRetry` (`vendor/.../PendingRequest.php:1071-1073`) — that
exception is consumed by the `retry()` helper. The final-attempt throw at `:1075-1077` is gated on
`retryThrow`, which is false. So the failure always arrives as a returned response and is handled by
the `!$response->successful()` block at `:79-86`, which duplicates the same two messages. Pre-existing,
not introduced here, but it is dead code in a file this commit rewrote. Note also that a request
*timeout* raises `ConnectionException`, which neither block catches — relevant because
`generateLandingPageContent` (`timeout: 120`) is the call spec 037 says currently times out.

**L-4 — `assertTrue(true)` and a tautological assertion.** `tests/Unit/AiUsageServiceTest.php:134`
uses `assertTrue(true)` as a "did not throw" marker; `expectNotToPerformAssertions()` states that
intent natively. More substantively, neither this test nor its Feature twin asserts that the failure
was **logged** — so a refactor that changed the catch body to a bare `return;` would keep both tests
green while destroying the only diagnostic signal (see M-2). `Log::spy()` + an assertion on the
warning is the real test. Separately, `:167`
(`assertFalse($tenantBRows->contains('id', $tenantARows->first()->id))`) cannot fail: both
collections were built with mutually exclusive `where('tenant_id', ...)` filters two lines earlier.

**L-5 — `tenant_scoping_attributes_rows_to_the_active_tenant_only` asserts less than its name
implies.** `tests/Unit/AiUsageServiceTest.php:142-168`. It initializes tenancy explicitly and then
verifies `tenant('id')` passthrough. That is real, but it is the *one* code path where attribution
already works. Read together with `no_active_tenant_records_a_null_tenant_id` (`:171-179`) — which
codifies the NULL outcome as expected behaviour — the pair reads as "tenant attribution is verified"
when the production-dominant path is both untested and wrong. This is the specific test-quality
concern the review question asked about: the tests are honest individually and misleading as a set.

**L-6 — `tests/Unit/AiUsageServiceTest.php` is a DB-backed test in `tests/Unit`.** It uses
`RefreshDatabase` and hits MySQL/SQLite; by the repo's own split (`phpunit.xml:8-13`) it belongs in
`tests/Feature`. Cosmetic. (Both new files use PHPUnit classes with `/** @test */` rather than Pest —
consistent with the existing suite, so not a finding, though `.claude/rules/standards.md` states a
Pest preference.)

**L-7 — `GeminiServiceTest` now silently exercises the swallow path 14 times per run.** It does not
use `RefreshDatabase` (`tests/Unit/GeminiServiceTest.php:9`), so `AiUsage::create()` inside every
`generate()` call fails against an unmigrated in-memory connection and is swallowed. The suite passes
only *because* the catch exists. Harmless today; it means those 14 pre-existing tests are now
coupled to M-1's error handling and would break together for an unrelated reason.

**L-8 — Pricing map is incomplete relative to its own source table.** `config/services.php:47-53`
omits `gemini-2.5-flash-image` (see M-4) and two models priced in spec 037 §1.1 (`gemini-3.1-pro`,
`gemini-3.5-flash`). Not needed today; add the image model at minimum.

---

## Explicitly nothing to report

**`decimal(10,6)` for `estimated_cost_usd` is fine.** No truncation, no overflow.
`database/migrations/2026_08_22_000001_create_ai_usage_table.php:26` gives a range of ±9999.999999.
The most expensive single call on the board is `generateLandingPageContent` at 8192 output tokens on
2.5 Pro ($10/M) ≈ **$0.10** — five orders of magnitude of headroom. Reaching the ceiling needs ~1
billion output tokens in one call, which no model will emit. `AiUsageService.php:89` rounds to 6dp
before insert, so MySQL strict mode never sees an out-of-range value and never truncates. The only
theoretical edge is that a call costing under $0.0000005 stores as `0.000000` — that requires fewer
than 5 input tokens on Flash-Lite, and the aggregate round-off across even 100,000 rows is bounded at
$0.05. Not a concern. The column is correctly sized; do not change it.

**`tenant('id')` does not throw on the central domain.** Confirmed at
`vendor/stancl/tenancy/src/helpers.php:23-34`: it returns early (`return;` → `null`) when
`Tenant::class` is unbound, and `optional()->getAttribute() ?? null` guards the bound-but-null case.
Half of the builder's claim is correct — it is safe. The other half (that it yields the right value)
is C-1.

---

## Praise

- **`AiUsageService.php:70-73` is the best comment in the commit.** Explaining *why*
  `config("services.gemini.pricing.{$model}")` cannot be used — Laravel splits on dots and every
  Gemini model name contains them — is precisely the "comment the why, not the what" the standards
  ask for, on a trap that would have silently produced a null-cost column forever. Equally good: the
  handoff records that this was caught **only** because the spec demanded an exact-value cost
  assertion instead of `assertNotNull`. That is the correct lesson and it should be the template for
  how H-1 and H-3 get closed.

- **Recording before the `MAX_TOKENS` throw is the right call, and it is commented as such**
  (`GeminiService.php:91`). Tokens were billed; a truncated response is the *most* expensive
  outcome per unit of value and the one you most want visible in the data. Skipping the record on
  non-2xx (`:79-86`) is also defensible — those are unbilled — and the genuinely-billed 200-with-no-
  usable-output cases (`finishReason: SAFETY`/`RECITATION`, or `promptFeedback.blockReason` with no
  candidates) all still record correctly, because `record()` sits above the parts loop rather than
  inside it. That ordering was thought through. Pin it (H-2).

- **The migration follows the architect directive exactly** and explains itself: `tenant_id`-leading
  composite index, nullable `tenant_id` with the rationale written at `:15-16`, null-cost rationale at
  `:24-25`, a real `down()`, and an FK pattern consistent with every other tenant-scoped table in
  `database/migrations/`.

- **`const UPDATED_AT = null` on an append-only log** (`AiUsage.php:50`) is a small, correct detail
  that most implementations get wrong by leaving a permanently-equal `updated_at` column on the table.

- **Deciding against `BelongsToTenant` and writing down why** (`AiUsage.php:20-33`) is exactly the
  right instinct — an accounting log genuinely should not carry a tenant global scope, and the
  cross-tenant-dashboard argument is correct. C-1 is a gap between that reasoning and the code, not a
  flaw in the reasoning. Fix the code; keep the docblock.

- **Fixing the stale `~$0.03` string in the same pass**, as spec §7 required, rather than deferring
  it. The finding underneath it — that a hardcoded number in a UI string became the platform's
  working belief about its own economics for months — is the most valuable thing in this commit, and
  it was found by taking the spec's cost table seriously enough to check it.

- Housekeeping across the board: `declare(strict_types=1)` on all four new PHP files, `$fillable`
  defined, `$casts` correct, PHPDoc on every non-obvious method, no `dd()`/`var_dump()`, no
  hardcoded colors, no N+1 introduced, no raw SQL, no new mass-assignment surface. `Http::fake()` is
  the only mock used, per `.claude/rules/standards.md`.

---

## Answers to the specific questions asked

1. **`new AiUsageService()` in the initializer** — acceptable, mild smell, not a testability blocker.
   The container never uses the default. See L-1 for the one concrete future failure (silently
   bypassing a container-bound decorator) and the note that removing it pairs naturally with C-1.
2. **Swallowing `\Throwable`** — the decision is right; the implementation has two holes (M-1a: a
   malformed pricing config drops the whole row instead of degrading to a null cost; M-1b: a
   `TypeError` at the argument boundary escapes the try entirely and breaks the AI call). The log line
   is **not** sufficient to diagnose or reconstruct — M-2.
3. **`decimal(10,6)`** — no issue. Five orders of magnitude of headroom, `round()` before insert.
   Stated in full above; do not change the column.
4. **Record placement** — correct, and the paths you listed are handled: the 429 retry works as
   written (verified against `PendingRequest.php:1071-1073`) and terminates in the `!successful()`
   branch, which correctly records nothing because those calls are unbilled. The
   `catch (RequestException)` block is unreachable dead code (L-3), and an uncaught
   `ConnectionException` on timeout is the real un-handled path — both pre-existing. The gap is
   **test coverage**, not logic — H-2.
5. **`tenant('id')` in `ProcessPendingProduct`** — it does not throw (safe), but the builder's claim
   that it yields the right value is **wrong**, and that is C-1. Root cause verified three ways:
   empty `bootstrappers`, no `QueueTenancyBootstrapper::__constructStatic()`, no app-level queue hook.
6. **Test quality** — the 15 tests genuinely pin cost arithmetic, the unknown-model rule, and the
   missing-`usageMetadata` rule. Three pin less than they appear to: L-5 (tenant scoping verifies only
   the path that already works, while its sibling codifies the broken outcome as correct), L-4
   (`assertTrue(true)`, plus no assertion that the failure was logged, plus a tautology at `:167`).
   Three things that should be pinned are not at all: purpose strings (H-1), usage-on-`MAX_TOKENS`
   (H-2), T3b (H-3).
7. **Purpose strings** — complete and consistent. All 13 `AiService → generate()` sites carry one;
   grep for `generativelanguage.googleapis.com` across `app/` confirms `generateCategoryImage()`
   (`AiService.php:568`) is the only bypass and nothing else was missed. Two minor notes: no enum
   (M-3), and the naming splits between `verb_noun` and `noun_noun` — defensible, undocumented.
