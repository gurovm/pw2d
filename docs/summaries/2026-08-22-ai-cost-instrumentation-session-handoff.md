# Session handoff — 2026-08-22: AI cost instrumentation, model strategy, pw2d top-up prep

## What this session became

It opened as a content request — *"let's top up pw2d, give me search phrases"* — and the phrases were
delivered inside twenty minutes. Everything after that came from one follow-up question: **"how many
products can I scan before hitting daily limits?"**

Answering it honestly meant pricing the pipeline, and pricing the pipeline exposed that **nobody has
ever measured it**. That became the session.

The arc:

1. A top-up run sheet, prioritised by pool size *and* unbuyable share.
2. A limits question → the real ceiling is the queue and the rescan, not Gemini.
3. Pricing that answer → the platform's own cost figure was ~3× too high, and it was a hardcoded string.
4. Asking whether to switch models or providers → Gemini stays; `admin_model` should not.
5. Discovering `GeminiService` throws `usageMetadata` away → build the measurement first.

## Shipped and deployed (prod `03d68d3`)

**Spec 037 T1 + T3b — AI usage instrumentation.**

- New `ai_usage` table: `tenant_id`-led composite index `(tenant_id, purpose, created_at)`, nullable
  `tenant_id` for central/console calls, `created_at` only.
- `GeminiService` captures `usageMetadata` (prompt / candidates / thoughts token counts) and takes a
  new `string $purpose` parameter.
- `purpose` threaded through all **13** `AiService → generate()` call sites, so spend is attributable
  per method instead of anonymous.
- `AiUsageService` owns cost math and persistence, priced from a new `config/services.php`
  `gemini.pricing` map in $/M. Thinking tokens bill at the output rate.
- Two hard safety properties, both test-pinned: an **unknown model** records the row with a null cost
  rather than throwing, and a **failed usage write** is caught and logged, never failing the AI call.
  *Accounting must not be able to break ingestion.*
- **T3b:** the Filament Retry Failed modal no longer claims "~$0.03 in Gemini API usage"; it renders
  ~$0.0103 from the pricing config, so it cannot drift again.

**Tests: 695 → 710 passed / 21 skipped.** Deploy verified end to end: migration DONE in 103ms, php-fpm
active, scheduler cron hook present, all three live URLs HTTP 200.

## Findings worth keeping

**The `$0.03` in the Filament modal was a guess, and it propagated.** It is what made a category
top-up look like a $10 problem. Measured cost is **$0.01025/product → ~$3.59/category**. The lesson is
not the arithmetic — it is that a hardcoded number in a UI string became the platform's working belief
about its own economics, for months, unchallenged.

**Laravel `config()` silently mis-parses any key containing a dot.**
`config("services.gemini.pricing.gemini-2.5-flash")` returns **null** — Laravel splits on every dot, so
it looks for `pricing → gemini-2 → 5-flash`. Every Gemini model name contains dots. The builder caught
it only because the spec demanded an *exact-value* cost assertion instead of a loose "not null" check.
A weaker test would have shipped a table that recorded null cost forever.

**`admin_model` is the worst-value model on the board.** Gemini 2.5 Pro bills output at **$10.00/M** —
the highest of anything considered except 3.1 Pro — and is a generation behind. Gemini 3.7 Flash is
newer *and* 58% cheaper. Output is 78% of per-product cost, so output price is the only lever that
matters; input-side prompt caching would save pennies.

**Provider switch investigated and declined.** Anthropic is the most expensive option at every tier
for this workload — Haiku 4.5 costs 34% more than Gemini 3.7 Flash and 2.8× gpt-5-mini, because
Anthropic has no nano/lite tier. OpenAI's gpt-5-nano is nominally cheapest but beats Gemini Flash-Lite
by **$0.04 per category**. The decisive argument is not price but asymmetry: `GeminiService` is
Gemini-shaped throughout and `AiService` leaks `maxOutputTokens`/`thinkingConfig` across the seam at
15+ sites, so a provider swap needs an `AiTransport` interface and full prompt re-validation — versus
one `.env` line to change models within Gemini. Full reasoning recorded in Spec 037 §1.2/§5 so it is
not re-litigated.

**The real limit on a scanning session is not Gemini.** It is 2 queue workers (~300–450 products/hour)
and the mandatory ~9s/offer rescan. Gemini's RPD is no longer published — Google's docs now point to
AI Studio — so it must be read from the console, not quoted from memory.

**Hitting the daily quota is not a soft failure.** `ProcessPendingProduct.php:235-238` has the same
swallow-on-final-attempt shape Spec 036 just fixed in `RescanProductFeatures`: it stamps
`status='failed'` and does **not** rethrow, so the job never reaches `failed_jobs` and `queue:retry`
cannot see it. Recovery is the Filament "Retry Failed" button, which filters `whereNotNull(category_id)`
— so a detached failed product is stranded.

**Prod was already current on code** (`9ceb807`, Spec 036), so specs 032–036 including Spec 033's
`offer_id` targeting are live — and the **extension was confirmed at v1.8 by the owner**. Both halves
of Spec 033 are therefore in place, so the ergonomic-keyboards rescan is trustworthy. That was the one
open risk hanging over the top-up, and it is closed.


## The audit — 1 critical, 3 high, 8 medium (three agents, all reports on disk)

`docs/reviews/audit-2026-08-22-review.md` · `docs/security/audit-2026-08-22-security.md` ·
`docs/performance/audit-2026-08-22-performance.md`. **No rollback.** No cross-tenant read path, no
secret leakage, no injection, no mass-assignment gap. Findings tracked as A1–A6 in `docs/tasks/todo.md`.

**All three agents independently found the same critical defect.** Every queued Bouncer call records
`tenant_id = NULL` — `evaluate_product` (the dominant cost), `rescan_features`, and job-path
`match_product`. The reason the builder gave for dropping `BelongsToTenant`, which this handoff's author
endorsed without checking, is **factually wrong**: the trait stamps `tenant_id` only
`if (tenancy()->initialized)`, the same condition that makes `tenant('id')` return null. On the queue
both produce an identical NULL. Dropping the trait bought nothing on the write side.

The `SeoMetric` precedent was misapplied. It is analogous in shape but **inverted in safety**:
`seo_metrics.tenant_id` is NOT NULL *and* its writers take the tenant as an argument, so a null row
there is a database error. `ai_usage` copied the trait omission and dropped both safeguards — and
Spec 037 §2 T1 said "`tenant_id` required" while the migration made it nullable.

**Two things about A1 matter more than the bug itself:**

1. **The repair window closes when the next top-up runs.** Rows carry no `product_id` or job
   back-reference, so nothing written before the fix can ever be retro-attributed.
2. **Spec 037's own acceptance query masks it.** `GROUP BY purpose` has no tenant column, so it
   aggregates over the NULL bucket and returns plausible numbers. Reading it would have produced false
   confidence that T1 works. Token counts and costs *are* correct, so T2's validation of the §1 cost
   model is unaffected — only per-tenant attribution is lost.

**A2, to ship alongside A1:** under `declare(strict_types=1)` a `TypeError` on `record(string $model,…)`
is raised at the **argument boundary**, so `record()`'s own try/catch cannot see it — the never-throw
guarantee has a hole. `GeminiService:36` does `$model = $model ?? config(...)`, and `env()` yields null
if `.env` holds the literal `null`. That path also **500s the Filament Products list page** at
header-render time, on a call path this commit introduced. Fix is `(string) config(...)` at two sites.

**Testing was the weak link, and in a specific way:** the tenant test validates the path that *works*,
not the one that is broken — it manually initializes tenancy before an `evaluate_product` call, a state
that never holds there. Its sibling codifies the NULL outcome as correct. Together they read as
"attribution is verified." Eleven of the thirteen `purpose` strings are unpinned entirely, as is T3b.

**Where the author was wrong:** the synchronous INSERT on user-facing paths — the thing flagged as most
concerning — is a non-issue by ~3 orders of magnitude (~1ms against a 700–2500ms Gemini call), sits at
the transport boundary so cache hits pay zero, and rides alongside a `SearchLog` write these requests
already do.

**The agents cross-checked each other**, which is the argument for running three: security reported no
rate limit on the public AI endpoints, having grepped only for `RateLimiter`. A 10/min per session/IP
limit does exist, built on a cache counter (shipped S11). That finding downgraded.

**Settled, do not re-litigate:** `decimal(10,6)` needs ~1B output tokens to overflow — leave it. The
index should be left alone until attribution is fixed (a full scan is 2–10ms; nothing degrades below
~500k rows ≈ 40 years at pipeline volume). Column sizing is premature — InnoDB VARCHAR is
length-prefixed. Record-before-`MAX_TOKENS` is correct, including the 429 and non-2xx paths. The
`purpose` audit is complete: `generateCategoryImage()` is the only bypass, and `gemini-2.5-flash-image`
is also absent from the pricing map, making that gap doubly invisible.

## Ready for tomorrow

`docs/tasks/2026-08-22-pw2d-tier3-topup.md` — the pw2d top-up run sheet. Priority order by pool size
**and** unbuyable share, which agree on the same first pick:

| # | Category | Pool | Buyable | Unbuyable |
|---|---|---|---|---|
| 1 | `gaming-chat-headsets` | 79 | 59 | **25%** |
| 2 | `lavalier-wireless-systems` | 76 | 66 | 13% |
| 3 | `productivity-ergonomic-keyboards` | 99 | 86 | 13% |
| 4 | `mechanical-gaming-keyboards` | 162 | 149 | 8% (optional) |
| 5 | `podcast-studio-mics` | 181 | 153 | 15% (optional, targeted) |

Brand-scoped search phrases per category, plus the phrases to **avoid** — notably never pairing
`ergonomic` with `gaming`, which is how 16 gaming boards landed in the ergonomic category in August.

**The top-up now does double duty:** it is the content work *and* it generates T1's first real data.
After the first batch drains:

```sql
SELECT purpose, COUNT(*), AVG(input_tokens), AVG(output_tokens), SUM(estimated_cost_usd)
FROM ai_usage GROUP BY purpose;
```

If `evaluate_product` lands near ~1,800 in / ~800 out, Spec 037 §1 holds and T3's case stands. If not,
the model ranking shifts and §1 must be revised **before** T3 is approved.

## Open, in recommended order

0. **A1 + A2 — the audit's critical fix.** ~5 lines plus the regression test that fails today.
   **Decide before the top-up runs**, per the repair-window note above.
1. **T2 — `pw2d:ai:eval-model` replay harness.** The ship gate for T3: ≥95% `is_ignored` agreement,
   ≥98% brand exact-match, ≤5pt feature MAD against already-scored products. Must run against prod;
   the golden set exists nowhere else.
2. **T3 — `admin_model` → 3.7 Flash**, gated on T2. One `.env` line plus `thinkingBudget` →
   `thinking_level` translation at the transport boundary only (Gemini 3 rejects both parameters
   together). **Watch for the upside:** `generateLandingPageContent` currently times out on 2.5 Pro,
   which is why landing-page prose is hand-authored. Flash may retire that workaround.
3. **H-A — `modelKey()` false-merges** (still the worst open item; live on 6 pages).
4. **H-D — `import_debt` may be un-clearable**; cheapest, and it changes what a nightly signal means.
5. **T1 gap** — `generateCategoryImage()` bypasses `GeminiService`, so image spend is the one AI cost
   `ai_usage` cannot see. Also a standing CLAUDE.md violation.

## Housekeeping

- **The git remote URL contains a live GitHub personal access token in plaintext.** It is in
  `.git/config`, not committed, but it is a working credential that was exposed in session output.
  **Rotate it** and move to SSH or a credential helper.
- `.claude/settings.local.json` gained an `autoMode.allow` entry so the `/deploy` SSH sequence can run
  under auto mode. The file is gitignored globally, so it did not enter the commit.
- New memory: `prod-is-the-only-database` — the local MySQL `pw2d` DB is empty; a data query there
  returns 0 rather than an error, which is a silently wrong answer.
