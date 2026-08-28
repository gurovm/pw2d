# Spec 037 â AI model strategy: instrumentation, replay eval, and the `admin_model` migration

**Status:** T1 + T3b SHIPPED 2026-08-22 (code complete, tests green, AWAITING /deploy). T2 + T3 not started â T3 remains gated on T2.
**Author:** Architect, 2026-08-22
**Supersedes:** nothing. **Related:** Spec 031 (cadence), the `generateLandingPageContent` timeout workaround.

---

## 0. Why this exists

The owner asked whether the AI spend justifies switching models â or providers (Claude, ChatGPT).
Investigating produced three findings, in ascending order of importance:

1. **The cost figure everyone quotes is wrong.** `ListProducts.php:39` tells the operator each retried
   product "costs ~$0.03 in Gemini API usage". That string is a hardcoded guess. Measured against the
   real prompt shape, `evaluateProduct()` costs **~$0.0103** â roughly one third.
2. **`admin_model` is the worst-value model on the board.** Gemini 2.5 Pro bills output at **$10.00/M**,
   the highest of any model considered except 3.1 Pro. It is also a generation behind. Gemini 3.7 Flash
   is newer *and* 58% cheaper.
3. **Nothing is measured.** `GeminiService` discards `usageMetadata` entirely. No token count, no cost,
   no per-method attribution has ever been recorded. Every number in this spec â including the ones
   above â is an estimate derived from prompt length. **That is the actual defect.**

The dollar amounts here are small (~$18/quarter today). This spec is justified by **quality, the
landing-page timeout, and observability**, not by the savings.

---

## 1. Cost model

> **Correction 2026-08-28 (architect):** this section priced "current" as **2.5 Pro** from the
> `config/services.php` default. Production `.env` (unchanged since 2026-07-21) actually runs
> `AGENT_ADMIN_MODEL=gemini-3.1-pro-preview` and `AGENT_SITE_MODEL=gemini-3.5-flash`. Using this
> section's own table: real baseline ≈ $0.0132/product, ≈ $4.62/category; T3 (3.7 Flash) saves ~67%, not
> 58%; and `matchProduct()` runs on 3.5 Flash ($1.50/$9.00), not 2.5 Flash, so it is *not* negligible.
> Neither prod model is in the pricing map, so T1 records null cost on prod until B2 (todo 2026-08-28)
> lands. Table below is left as written; rewrite once T2 has measured tokens.

`evaluateProduct()` is the dominant call â one per imported product, on `admin_model`.

| | Tokens | Basis |
|---|---|---|
| Input | **~1,800** | ~5,000 chars of prompt template + `$featureJson` for ~6 features |
| Output | **~800** | 6 feature scores + explanations, `ai_summary`, name, brand, + the 128-token thinking budget |

`matchProduct()` runs on `site_model` (2.5 Flash) at ~$0.001 and is short-circuited by the
`ai_matching_decisions` cache and the no-processed-products-for-brand heuristic. **It is not the problem.
Only `admin_model` is in question.**

### 1.1 Within Gemini

| Model | In $/M | Out $/M | Per product | Per category (~350) | vs. today |
|---|---|---|---|---|---|
| **2.5 Pro** â current | 1.25 | **10.00** | $0.0103 | **$3.59** | â |
| 3.1 Pro | 2.00 | 12.00 | $0.0132 | $4.62 | +29% |
| 3.5 Flash | 1.50 | 9.00 | $0.0099 | $3.47 | â3% |
| **3.7 Flash** â recommended | 0.75 | 3.75 | $0.0044 | **$1.52** | **â58%** |
| 2.5 Flash | 0.30 | 2.50 | $0.0025 | $0.89 | â75% |
| 3.1 Flash-Lite | 0.25 | 1.50 | $0.0017 | $0.58 | â84% |
| 2.5 Flash-Lite | 0.10 | 0.40 | $0.0005 | $0.18 | â95% |

3.7 Flash's $0.75/$3.75 is promotional **through 2026-12-31**; it becomes $1.50/$7.50 on 2027-01-01 â
still cheaper than 2.5 Pro on output, which is where the spend actually sits (78% of per-product cost).

### 1.2 Across providers â the question the owner asked

| Provider | Model | In $/M | Out $/M | Per category (~350) |
|---|---|---|---|---|
| Google | 2.5 Flash-Lite | 0.10 | 0.40 | $0.18 |
| OpenAI | gpt-5-nano | 0.05 | 0.40 | $0.14 |
| OpenAI | gpt-4.1-mini | 0.40 | 1.60 | $0.70 |
| OpenAI | gpt-5-mini | 0.25 | 2.00 | $0.72 |
| Google | 3.7 Flash | 0.75 | 3.75 | **$1.52** |
| **Anthropic** | **Haiku 4.5** | 1.00 | 5.00 | **$2.03** |
| Google | 2.5 Pro (current) | 1.25 | 10.00 | $3.59 |
| OpenAI | gpt-5 | 1.25 | 10.00 | $3.59 |
| **Anthropic** | **Sonnet 5** | 3.00 | 15.00 | **$6.09** |
| **Anthropic** | **Opus 5** | 5.00 | 25.00 | **$10.15** |

**Decision: do not switch providers for the pipeline.** Reasons, in order:

- **Anthropic is the most expensive option at every tier for this workload.** Its cheapest model
  (Haiku 4.5, $1/$5) still costs **34% more than Gemini 3.7 Flash** and **2.8Ã gpt-5-mini**. Anthropic
  has no nano/lite tier; Google and OpenAI both do. For high-volume, low-judgment classification this
  is decisive.
- **OpenAI's nano tier is nominally cheapest**, but the delta versus Gemini Flash-Lite is **$0.04 per
  category**. That is not a reason to migrate anything.
- **The switching cost is real and one-directional.** See Â§5.

### 1.3 Batch API â considered and declined

All three providers offer **50% off** for asynchronous batch. The Bouncer is queue work, so it is a
natural fit: 2.5 Flash + batch would be **$0.45/category, â87%**.

**Declined.** It would split the ingestion path in two (batch for SERP bulk, synchronous for the live
`ingest-offer` path where the operator watches products appear), for a saving of about one dollar per
category. That is the definition of over-engineering under CLAUDE.md. Revisit only if volume grows by
an order of magnitude.

---

## 2. Scope

### T1 â Usage instrumentation *(do this first; it is the only task with no prerequisite)*

`GeminiService::generate()` currently reads `candidates[0]` and throws away `usageMetadata`.

- Capture `promptTokenCount`, `candidatesTokenCount`, `thoughtsTokenCount`, `totalTokenCount`.
- Attribute each call to its **caller method** â `AiService` passes a `string $purpose`
  (`evaluate_product`, `match_product`, `rescan_features`, `landing_page_content`, â¦) through to the
  transport. This is the field that makes the data useful; without it every row is anonymous.
- Persist to a new `ai_usage` table. **`tenant_id` required, composite index leading with `tenant_id`**
  per the architect directive in `project_context.md` Â§11.

```
ai_usage
  id, tenant_id (string FK, nullable â console/central calls have no tenant),
  purpose (string), model (string),
  input_tokens, output_tokens, thinking_tokens (unsigned int),
  estimated_cost_usd (decimal 10,6), created_at
  INDEX (tenant_id, purpose, created_at)
```

- Pricing lives in `config/services.php` as `gemini.pricing.{model}.{input|output}` in $/M so the cost
  column is computed, not hardcoded. An unknown model logs tokens with `estimated_cost_usd = null`
  rather than throwing â **never let accounting break ingestion**.
- Writing usage MUST NOT fail the AI call. Wrap in try/catch, log, continue.

**Acceptance:** after one category import, `SELECT purpose, COUNT(*), AVG(input_tokens),
AVG(output_tokens), SUM(estimated_cost_usd) FROM ai_usage GROUP BY purpose` returns real numbers, and
Â§1's estimates can be replaced with measurements.

### T2 â Replay eval harness

A model swap on the Bouncer is a **quality** decision. Do not ship it on price.

New command: `pw2d:ai:eval-model {tenant} {--model=} {--category=} {--limit=50} {--json=}`

1. Select N products with `status IS NULL`, `is_ignored` either value, that already have
   `ProductFeatureValue` rows â these are the golden set, already human-reviewed in practice.
2. Re-run `evaluateProduct()` against `--model` using each product's **stored offer `raw_title`,
   price, and category feature map** â the same inputs the original call received.
3. Diff against stored output and report:

| Metric | Why it matters |
|---|---|
| `is_ignored` agreement rate | The Bouncer's primary job. **The gate.** |
| Brand normalization exact-match rate | `RÃDE` â `Rode`. Silent failure otherwise. |
| Feature score mean absolute delta | Scores drive pick selection; drift re-ranks pages. |
| Feature score max delta | One wild score moves a pick. |
| `ai_summary` banned/condition-word hits | Reuses `ProductConditionGuard` + the style contract. |
| Mean latency, mean tokens, total cost | Sourced from T1. |

4. **Read-only.** Writes nothing to `products` or `product_feature_values`. `--json=` dumps the full
   per-product diff for eyeballing.

**Ship gate for T3:** `is_ignored` agreement **â¥ 95%**, brand exact-match **â¥ 98%**, feature score MAD
**â¤ 5 points** on a 0-100 scale. Below any of these, the candidate model is rejected and this spec's
T3 does not ship.

### T3 â `admin_model` migration to Gemini 3.7 Flash

Gated on T2 passing. The change is deliberately small:

- `.env` â `AGENT_ADMIN_MODEL=gemini-3.7-flash`. **The rollback is this one line**, which is why the
  existing `config('services.gemini.admin_model')` indirection is worth preserving exactly as-is.
- **`thinking_level` handling in `GeminiService`.** Gemini 3 prefers `thinking_level`
  (`low`/`medium`/`high`) over the `thinkingConfig.thinkingBudget` that `AiService` sets at four sites
  (`:97` budget 128, `:171` budget 0, `:325` budget 128, `:405`/`:457` budget 0). `thinkingBudget` is
  still honoured for backward compatibility, **but the two parameters must never be sent together.**
  Translate at the transport boundary â `AiService` keeps expressing intent as a budget, and
  `GeminiService` maps it per model family. Do not touch the 15+ `AiService` call sites.
- No prompt changes in this task. If T2 shows prompt-level drift, that is a separate spec.

**Blast radius â every `admin_model` caller re-validates, not just the Bouncer:** `evaluateProduct`,
`rescanFeatures`, `extractProductFromText`, `generateCategoryContent`, `generateCategoryImage`,
`generateLandingPageContent`, `generateCompareContent`, `generatePresetContent`.

**Watch for the upside:** `generateLandingPageContent` currently **times out on 2.5 Pro** â this is why
landing-page prose has been hand-authored by Claude since August (see Spec 031 and the
`ai-content-style-bar` contract). A Flash-tier model is materially faster. **If that call starts
succeeding, re-test it explicitly and report** â it would retire a standing manual workaround. It must
still pass the style + grounding contract and the banned/condition-word machine check before any
generated prose is trusted.

---

## 3. Out of scope

- Switching the pipeline to Anthropic or OpenAI (Â§1.2 â declined, with reasons).
- Batch API (Â§1.3 â declined).
- Two-stage cheap-gate architecture (Flash-Lite triage â full scoring on survivors). It would cut
  ~76%, but of a $3.59 base. Not worth the complexity. **Reconsider only if `ai_usage` data from T1
  contradicts the cost model in Â§1.**
- Prompt caching. Input is only 22% of per-product cost; caching the fixed template saves pennies.
- Any change to `site_model`.

---

## 4. A deferred idea worth recording

The measurement in Â§1 makes an option visible that was not obvious before.

`generateLandingPageContent` runs **~11 times per quarter** at roughly 3,000 in / 6,000 out. On Claude
Sonnet 5 that is **~$0.10 per page â about $1.10 per quarter for every landing page on the platform.**

The owner already writes this prose with Claude, by hand, because Gemini's admin model times out on the
call. Formalizing that â Gemini Flash for the high-volume Bouncer, Claude for the low-volume,
quality-dominated content generation â would automate an entire manual session per content cycle for
roughly a dollar.

**Deliberately not in this spec.** It requires a second transport class and a provider abstraction the
codebase does not have today (Â§5), and T3 may make it moot by fixing the timeout. **Decide after T3
reports whether `generateLandingPageContent` succeeds on 3.7 Flash.**

---

## 5. Why a provider switch is expensive here (recorded so it isn't re-litigated)

The `AiService` (domain) / `GeminiService` (transport) split is the right seam and is genuinely
reusable. But the seam **leaks Gemini vocabulary**, so it is not a drop-in swap:

1. `GeminiService` is Gemini-shaped throughout â endpoint URL, `x-goog-api-key`,
   `candidates[0].content.parts[]` with the `thought` filter, `finishReason: MAX_TOKENS`.
2. `AiService` passes `maxOutputTokens` and `thinkingConfig` at 15+ call sites. Those are Gemini
   parameter names crossing the abstraction boundary.
3. Prompts are Gemini-tuned â STAGE 1 gate, IGNORE RULE C, the grounding contract. All of it needs
   re-validation against a different model family, which is exactly what T2 exists to do.
4. JSON is parsed out of raw text. Anthropic and OpenAI both offer native structured outputs, which
   would be a genuine improvement â and a genuine rewrite.

A true provider swap means introducing an `AiTransport` interface and a sibling implementation.
**Changing models within Gemini costs one `.env` line plus the `thinking_level` translation in T3.**
That asymmetry, not the price table, is the strongest argument for the recommendation.

---

## 6. Tests

Per `.claude/rules/standards.md` â Pest, `RefreshDatabase`, factories, no mocks except external APIs.

- `AiUsage` written on a successful call, with correct `purpose`, `tenant_id`, and computed cost.
- Usage-write failure (e.g. unknown model â null cost) does **not** fail the AI call.
- `usageMetadata` absent from the response â logs tokens as null, no exception.
- `thinkingBudget` â `thinking_level` translation for a Gemini 3 model; `thinkingBudget` preserved
  verbatim for a 2.5 model; **the two are never both present in one payload**.
- `pw2d:ai:eval-model` is read-only â assert `products` and `product_feature_values` are byte-identical
  before and after a run.
- Eval command is tenant-scoped and fails cleanly on an unknown tenant (match the existing
  `FlagConditionProductsCommandTest` conventions).
- Gemini HTTP is mocked (`Http::fake()`), the only permitted mock.

## 7. Deploy notes

- T1 adds a migration â `/deploy` runs `migrate --force`. No extension change, no endpoint change, so
  the CLAUDE.md popup/content sync rule is not triggered.
- T3 is `.env`-only on the server plus the `GeminiService` translation. **Do not change
  `AGENT_ADMIN_MODEL` on production until T2 has been run against production data** â the golden set
  only exists there (see the `prod-is-the-only-database` constraint).
- Fix the stale `~$0.03` string in `ListProducts.php:39` in the same pass; drive it from the T1
  pricing config so it can never drift again.
