# Spec 039 — Bouncer overflow path: operator-session evaluations

**Status:** APPROVED 2026-08-28 ("build"). T1–T4 built + T1/T2 review fixes applied 2026-08-28 (811 tests); T3/T4 reviewed (SHIP) + 4 mediums fixed; T5 built; T6 runbook done. **837 tests. Ready to deploy** (no migration; touches the Gemini job → deploy needs the queue restart, step 9b).
**Depends on:** Spec 038 (shipped). Extends Spec 037 T2 (replay harness, not yet built).
**Owner decision recorded:** the owner runs top-ups from VS Code on weekends, when the Claude Code
subscription's weekly window is about to renew and its 5-hour windows are idle. Claude (the assistant in
that session) evaluates whatever the Gemini Bouncer could not — interactively, with the operator present.

## 0. Why

On 2026-08-28 a 277-row import hit the admin model's daily quota after ~250 evaluations; 93 products
stranded at `pending_ai`/`failed` until the next day. The same session already had a stronger model
sitting idle: the one writing this spec. Landing-page prose is already authored this way (Gemini times out
on it), so the pattern exists — this spec gives it a schema, a command pair, and a measurement gate.

**Boundary, stated once:** this is an *interactive* path. The operator opens the session, exports the
backlog, Claude evaluates it, the operator applies the result. No cron, no headless loop, no subscription
credentials in the app. If that boundary ever needs to move, Spec 037 §5's `AiTransport` + the Claude
API is the route, and this spec's schema is reused unchanged.

**v1 is the overflow path only.** Imports still dispatch Gemini jobs exactly as today. The session
finishes what Gemini leaves behind (`pending_ai` after a quota wall, `failed` after retry exhaustion).
Making the session the *first* path is out of scope (§6) and gated on the harness numbers.

## 1. Architecture

```
                    ┌──────────────────────────┐
  import ──────────▶│ ProcessPendingProduct job │──▶ AiService::evaluateProduct() ──▶ Gemini
                    └────────────┬─────────────┘                │
                                 │ ProductEvaluation (T1)        │ parse
                                 ▼                              ▼
                    ┌──────────────────────────┐
                    │ FinalizeProductEvaluation │  ◀── shared action (T2): ignore / dedup-merge /
                    └──────────────────────────┘      category-rejection / brand / update / features / image
                                 ▲
                                 │ ProductEvaluation (T1)
  export-pending (T3) ──▶ file ──▶ Claude in session ──▶ file ──▶ apply-evaluations (T4)
                                                                   └─▶ ai_usage row, model = claude-code-session, cost 0
```

One evaluation schema. One finalize path. Two producers.

## 2. Scope

### T1 — `ProductEvaluation` value object (single schema)

`app/Support/ProductEvaluation.php`, immutable, built via `fromArray(mixed $raw): self` which **validates**
and throws `InvalidProductEvaluation` (message names the field) on any violation — including a non-array
payload (Gemini returns `parsed = null` on a non-JSON reply; that must surface as `InvalidProductEvaluation`,
never a `TypeError`, because the job catches `\Exception` and a `TypeError` leaves the product stuck at
`pending_ai` with no retry path — review HIGH-1, 2026-08-28).

**Strictness rule (review M2/M3):** the value object must accept everything the *old* job accepted from
Gemini. Free text (`ai_summary`, feature `reason`) is truncated at its cap, not rejected; feature `reason`
is nullable; an `ignored` `reason` is any non-empty string here. The four-value reason enum and the
"every category feature present" rule are enforced **at apply time for file rows (T4)**, where the
producer is under our control and strictness costs nothing.

| Field | Type | Rules |
|---|---|---|
| `status` | `scored` \| `ignored` | **optional; absent = `scored`** — Gemini's scored payload has never carried a `status` key (builder finding 2026-08-28); only `ignored` is ever explicit |
| `reason` | string | required when `ignored`; one of `accessory_or_bundle`, `generic_white_label`, `renewed_or_refurbished`, **`wrong_category`** (new — see below); absent when `scored` |
| `name` | string ≤ 255 | required when `scored` |
| `brand` | string ≤ 100 | required when `scored` |
| `ai_summary` | string ≤ 600 | required when `scored` |
| `price_tier` | 1\|2\|3\|null | optional |
| `amazon_rating` / `amazon_reviews_count` | number\|null | optional (Gemini leaves null; session leaves null) |
| `features` | map feature name → `{score: 1..100, reason: string ≤ 300}` \| null | required when `scored`; scores are numeric 0..100 (strings coerced; bare numeric entries allowed; `0` is valid here and simply skipped by the legacy `score > 0` guard in `applyFeatureScores()`); unknown feature names rejected against the category at apply time, not here |

`wrong_category` is the one addition to the Gemini vocabulary. Gemini's prompt never emits it (Stage 1
has no such rule); the session path may. Finalize maps it to what `AiSweepCategory` does today: create
`AiCategoryRejection(product, category)`, set `category_id = null`, `status = null`, `is_ignored` untouched.
Motivation: 2026-08-28 lavalier import let ≥15 shotgun mics through the gate; the session can catch that
at evaluation time instead of a later sweep.

The Gemini parse path (`ProcessPendingProduct`) constructs `ProductEvaluation::fromArray($result['parsed'])`
and must keep passing every existing test — the object is a formalisation, not a behaviour change. The
one intentional change: a Gemini response missing `name`/`brand` now throws `InvalidProductEvaluation`
instead of the current bare `\Exception` (same retry semantics).

### T2 — `FinalizeProductEvaluation` action (extracted, behaviour-identical)

`app/Actions/FinalizeProductEvaluation.php` — `execute(Product $product, Category $category,
ProductEvaluation $eval, string $source): FinalizeOutcome` where outcome ∈ `scored | ignored | merged |
rejected_from_category`.

Move, do not rewrite, from `ProcessPendingProduct::handle()` lines ~78–222: the ignored branch; the short-
name guard + `capProductName()`; `AiService::matchProduct()` dedup and the offer-merge + `forceDelete`;
the `AiCategoryRejection` check (extended: also the `wrong_category` write above); fuzzy brand resolve;
product update (name/slug/brand/summary/tier/rating/status null); negative-decision invalidation; the
feature-score loop; `downloadAndStoreImage()`. The feature-score loop becomes a public static
`applyFeatureScores(Product, Category, array $features): int` and **`RescanProductFeatures` calls it too**
(closes todo L2; L3's price-note builder may move into a tiny `ProductPromptContext` helper if the builder
finds it natural — optional).

`ProcessPendingProduct::handle()` after T2: load → build inputs → `evaluateProduct()` →
`ProductEvaluation::fromArray` → action → log. The retry/`failed` semantics at the catch block are
**unchanged**. `matchProduct()` stays an AI call inside the action (site model, cheap, separate quota) for
both producers — the session path does not replace dedup.

### T3 — `pw2d:products:export-pending`

```
pw2d:products:export-pending {tenant} {category-slug?}
    {--status=pending_ai,failed : comma list; also accepts "processed" for calibration exports}
    {--limit=}
    {--out= : file path; default storage/app/bouncer/{tenant}-{category|all}-{Ymd_His}.json}
    {--anchors=5 : processed products included as scoring anchors}
```

Read-only. Initializes tenancy for `{tenant}`. Output (pretty JSON, one file):

```json
{
  "meta": {"tenant": "pw2d", "exported_at": "...", "status_filter": ["pending_ai","failed"], "count": 93},
  "category": {"id": 12, "name": "Lavalier & Wireless Systems", "slug": "...", "budget_max": 100, "midrange_max": 300,
               "features": [{"name": "...", "unit": null, "is_higher_better": true}]},
  "rules": "<BouncerRules::text($categoryName)>",
  "brands": ["Rode", "DJI", "Hollyland", ...],
  "anchors": [{"name": "DJI Mic 2", "brand": "DJI", "price_tier": 2, "features": {"Audio Quality": 85, ...}}],
  "products": [{"product_id": 4887, "raw_title": "...", "price": 129.0, "price_note": "Mid-range ($100–$300)",
                "rating_note": "4.4/5 stars (151 reviews)", "store": "Amazon", "url": "...", "status": "failed"}]
}
```

`rules` comes from a new `App\Support\BouncerRules::text(string $categoryName): string` that holds the
Stage 1 / 2 / 2.5 / 3 text **currently inlined in `AiService::evaluateProduct()`** — the prompt is
refactored to use it. One source of truth for the gate rules; the export adds one paragraph describing
`wrong_category`. Multi-category exports (no slug) group products per category with their own
`category`/`anchors` blocks.

`--status=processed` exports already-scored products **blind** (no stored scores, no stored brand) —
that is the calibration set for T5.

### T4 — `pw2d:products:apply-evaluations`

```
pw2d:products:apply-evaluations {tenant} {file}
    {--source=claude-code-session : recorded as ai_usage.model}
    {--dry-run : validate everything, write nothing, print the full outcome table}
```

Input file: `{"evaluations": [{"product_id": 4887, ...ProductEvaluation fields...}, ...]}`.

Per row, in this order, each product in its **own DB transaction**:
1. `ProductEvaluation::fromArray` — invalid → row marked `error`, nothing written for it.
2. Product must exist, belong to `{tenant}` (explicit `tenant_id` check, not just the global scope), and
   have `status IN ('pending_ai','failed')` — otherwise `skipped` with the reason (this is what makes a
   re-run of the same file idempotent).
3. Feature names must all exist on the product's category — else `error`.
4. `FinalizeProductEvaluation::execute(..., source: $source)`.
5. `AiUsageService::record('evaluate_product', $source, [], $tenantId)` — tokens null, cost 0.
   Add `'claude-code-session' => ['input' => 0.0, 'output' => 0.0]` to the pricing map so the row prices
   to `0.000000` rather than `NULL` and the unpriced-model warning stays quiet. Cost reports therefore
   show session evaluations as **$0 and countable** — the subscription is a sunk cost.

Output: a table `product_id | outcome | detail` and a summary `scored N · ignored N (by reason) · merged N
· rejected N · skipped N · errors N`. Exit code 1 if `errors > 0` (skips alone are fine). `--dry-run`
runs steps 1–3 for every row and prints what 4 *would* do, writing nothing — the operator always runs
it first.

### T5 — harness hook (Spec 037 T2, `--from-file`)

`pw2d:ai:eval-model` gains `{--from-file=}`: instead of calling a candidate model, it reads a file in the
T4 format and diffs each row against the product's **stored** evaluation (is_ignored, brand, feature
scores, summary word-checks). Same metrics table, same gate: `is_ignored` agreement ≥ 95%, brand exact
≥ 98%, feature MAD ≤ 5. If Spec 037 T2 is built first, this is a small addition; if this spec is built
first, T5 builds the read-only diff core that T2 later wraps. **The session path is not trusted for
first-pass evaluation until it has passed this gate on a 50-product calibration export.**

### T6 — runbook `docs/ops/bouncer-session.md`

The operator sequence (below), the subagent prompt template (rules + features + anchors + brands + the
exact JSON schema, batches of ~20 products, one subagent per batch), the budget note (~1.5k tokens per
product all-in across subagent overhead; 277 products ≈ 300–400k), the "dry-run first" rule, the
interactive-only boundary, and the cost-log query to confirm rows landed with model `claude-code-session`.

```
import (extension) → Gemini drains what it can
→ php artisan pw2d:products:export-pending pw2d <slug>
→ Claude evaluates (subagents, batches of 20) → evaluations.json
→ php artisan pw2d:products:apply-evaluations pw2d evaluations.json --dry-run   (must be error-free)
→ php artisan pw2d:products:apply-evaluations pw2d evaluations.json
→ rescan (extension) → sweep if needed → regenerate
```

## 3. Data / schema changes

None. No migration. One pricing-map entry. `ai_usage.model` already accepts any string.

## 4. Tests (Pest; every path below)

- **T1:** valid scored row; valid ignored row per reason; missing `name`/`brand`/`ai_summary` when scored;
  `reason` present when scored → invalid; unknown reason; score 0 / 101 / non-numeric; `features` not a
  map; `wrong_category` accepted.
- **T2 regression:** the entire existing `ProcessPendingProduct` and `RescanProductFeatures` test suites
  pass unchanged (the extraction is invisible). Plus: `wrong_category` → rejection row + detached +
  `status null` + `is_ignored` unchanged; `applyFeatureScores` shared by both jobs (one test each).
- **T3:** export shape for pending+failed; `--status=processed` contains no stored scores/brand; anchors
  count; brands are tenant-scoped; products of another tenant never appear; empty backlog → file with
  `count: 0` and exit 0.
- **T4:** happy path writes product/brand/feature values and an `ai_usage` row with
  `model = 'claude-code-session'`, `estimated_cost_usd = 0`, `tenant_id` = tenant; ignored path; merged
  path (matchProduct faked to return an existing id); rejected path; row for a processed product →
  skipped; row for another tenant's product → skipped; invalid row → error + nothing written for it +
  exit 1; `--dry-run` → zero writes, full table; running the same file twice → second run all `skipped`.
- **T5:** from-file diff on 3 products produces the expected agreement numbers; gate pass/fail output.

`php artisan test` baseline at time of writing: 737 passed / 21 skipped. Zero regressions.

## 5. Order of work

T1 → T2 (with the regression suite green after each) → T3 → T4 → T5 → T6. T1+T2 are one builder pass;
T3+T4 a second; T5+T6 a third. Reviewer after T2 (the extraction) and after T4 (the write path).

## 6. Out of scope (recorded)

- Making the session the **first** path (import without Gemini dispatch). Needs a `bouncer.mode` switch and
  the T5 gate passed. Decide after two real sessions.
- Session-side **rescans** (`RescanProductFeatures` re-scoring) — same pattern, later spec.
- Replacing `matchProduct()` dedup with an in-session judgment.
- Any Claude API transport (Spec 037 §5) — the schema here is designed so that route needs no new schema.
- Filament UI for uploading an evaluations file — the console command is the interface; the operator is
  already in a terminal.
