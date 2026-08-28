# Spec 038 — AI usage log fix bundle (pre-top-up)

**Status:** APPROVED by owner 2026-08-28 ("go"). Builder pass → tester pass → `/deploy` → headsets rescan.
**Origin:** Spec 037 T1 audit findings A1–A5 (`docs/tasks/todo.md`, section "Spec 037 T1 audit") plus two
findings from the 2026-08-28 log check (same file, section "2026-08-28"). Read both sections first.

## 0. Why now

`ai_usage` on production has **zero rows** — no AI call has run since T1 deployed. That means the
critical attribution bug (A1) can still be fixed with nothing lost. The next pw2d import will write
~600 rows/category; after that, un-attributed rows are permanent. Deploy this before any import.

Second reason: production `.env` runs `gemini-3.1-pro-preview` (admin) and `gemini-3.5-flash` (site).
Neither is in `config/services.php` → `gemini.pricing`, so every row would record `estimated_cost_usd = NULL`.

## 1. Scope — four changes, all small

### B1 — tenant attribution on queued AI calls (audit A1) + null-model hardening (audit A2)

**A1.** Thread the tenant explicitly from the jobs to the usage record. No tenancy initialization in jobs.

| File | Change |
|---|---|
| `app/Services/GeminiService.php:33` | `generate(string $prompt, array $config = [], ?string $model = null, string $purpose = 'unspecified', ?string $tenantId = null)` — forward `$tenantId` to **every** `$this->usage->record(...)` call (line 92 and the record-before-`MAX_TOKENS` path). |
| `app/Services/AiService.php:29` `evaluateProduct(...)` | Append `?string $tenantId = null` as the **last** parameter; pass it to `generate()` at :95. |
| `app/Services/AiService.php:106` `rescanFeatures(...)` | Same: append `?string $tenantId = null`; pass at :128. |
| `app/Services/AiService.php:323` (inside `matchProduct`) | Forward the already-resolved `$tenantId` (line 261) to `generate()`. |
| `app/Jobs/ProcessPendingProduct.php:74` | Pass `$product->tenant_id` to `evaluateProduct()` (matches how :108 already passes it to `matchProduct`). |
| `app/Jobs/RescanProductFeatures.php:82` | Pass `$product->tenant_id` to `rescanFeatures()`. |

`AiUsageService::record()` already accepts `?string $tenantId` and falls back to `tenant('id')` — keep
that fallback for the user-facing (request-context) callers. Do **not** add `BelongsToTenant` to `AiUsage`
and do **not** call `tenancy()->initialize()` anywhere in the jobs (it would flip `handle()` to
global-scope semantics and break the deliberate `withoutGlobalScopes()` calls).

The other 10 `generate()` call sites in `AiService` are request-context (Livewire/Filament/console with
tenancy initialized) — leave them; the `tenant('id')` fallback covers them.

**A2.** A null model string currently escapes the never-throw guarantee (`TypeError` at the argument
boundary of `record(string $model, …)`).

- `GeminiService.php:36`: `$model = $model ?? (string) config('services.gemini.site_model');`
- `AiUsageService::estimateProductEvaluationCost()`: `(string) config('services.gemini.admin_model')`.
- `AiUsageService::record()`: compute cost in its **own** guarded step so a pricing failure yields a
  null-cost row, never a missing row and never an exception:

```php
try {
    $cost = $this->estimateCost($model, $inputTokens, $outputTokens, $thinkingTokens);
} catch (\Throwable) {
    $cost = null;
}
// then the existing try { AiUsage::create([... 'estimated_cost_usd' => $cost]) } catch (\Throwable $e) { ... }
```

### B2 — price map completeness + diagnosable log lines (audit A4, finding 1)

`config/services.php` → `gemini.pricing`: add, from Spec 037 §1.1,

```php
'gemini-3.1-pro-preview' => ['input' => 2.00, 'output' => 12.00],
'gemini-3.5-flash'       => ['input' => 1.50, 'output' => 9.00],
```

Do not add the image model (`generateCategoryImage()` bypasses `GeminiService`; that is the tracked T1 gap).

In `AiUsageService::record()`:
- When `estimateCost()` returns null **because the model is absent from the pricing map**, emit
  `Log::warning('AiUsageService: model not in pricing map', ['model' => $model, 'purpose' => $purpose])`
  **once per model per service instance** (an instance-level `array $warnedModels`). The row is still
  written with null cost — that behaviour is unchanged and test-pinned.
- The failed-write catch block becomes `Log::error` and carries `tenant_id`, `input_tokens`,
  `output_tokens`, `thinking_tokens`, `exception` (`get_class($e)`) and `error` (`getMessage()`), so a
  lost row can be reconstructed from the log.

### B3 — guard-ignored products must not stay `pending_ai` (finding 2)

When `ListingHealthService::apply()` returns `ACTION_FLAGGED_CONDITION` for a **brand-new** product, the
import correctly ignores it and does not dispatch `ProcessPendingProduct` — but leaves
`status = 'pending_ai'` forever. Fix at the three sites, in the existing non-dispatch branch:

| File | Branch |
|---|---|
| `app/Http/Controllers/Api/BatchImportController.php:208` | the `ACTION_FLAGGED_CONDITION` branch (product was just `Product::create`d) → `$product->update(['status' => null]);` |
| `app/Http/Controllers/Api/ProductImportController.php:203` | whenever `$listingOverride === ACTION_FLAGGED_CONDITION` → same. **Corrected 2026-08-28 after review (M1):** originally "brand-new only"; wrong, because `:134` writes `pending_ai` on every re-import and `ProcessPendingProduct` overwrites `status` on every outcome, so there is no in-flight-job risk. |
| `app/Services/OfferIngestionService.php:302` | the created-new-product path when `$action === ACTION_FLAGGED_CONDITION` → same. Also when the dispatch is skipped because `$category->features->isEmpty()`? **No** — leave that case alone; it is a different (pre-existing) question and out of scope. |

Keep the three edits at the import sites; do not move this into `ListingHealthService::apply()` — it is
shared with the refresh/rescan paths and should stay status-agnostic. (Earlier wording here cited an
"in-flight job" risk; that was wrong — see the M1 correction in the table above.)

**Data fix**, idempotent migration `2026_08_28_000001_clear_status_on_guard_ignored_products.php`:

```php
DB::table('products')->where('status', 'pending_ai')->where('is_ignored', true)->update(['status' => null]);
```
`down()` is a no-op (the previous state was a bug). Production has 28 such rows (21 pw2d, 7 c2d).

## 2. Tests (Pest, sqlite in-memory, `RefreshDatabase`; follow the patterns in
`tests/Feature/AiUsageInstrumentationTest.php` and `tests/Unit/AiUsageServiceTest.php`)

Builder writes these with the code; tester extends afterwards.

1. **A1 regression (must fail before, pass after):** fake the Gemini HTTP response, dispatch the real
   `ProcessPendingProduct` for a product with `tenant_id = 'acme'` **without** initializing tenancy →
   `AiUsage::sole()->tenant_id === 'acme'` and `purpose === 'evaluate_product'`. Same for
   `RescanProductFeatures` → `rescan_features`. And `matchProduct()` called with an explicit `$tenantId`
   and no tenancy → row carries that tenant.
2. **Rewrite the inverted pair** flagged by audit A3: `AiUsageInstrumentationTest.php:152-170` must not
   initialize tenancy before an `evaluate_product` call; `AiUsageServiceTest.php:171-179` null case must
   use a purpose that is genuinely central/console, not `sweep_category`.
3. **A2:** `record()` called via a `generate()` where `config('services.gemini.site_model')` is null does
   not throw and still records a row with `model === ''`. `estimateProductEvaluationCost()` with null
   admin model returns null (no exception). A pricing entry that is a string (malformed) → row written,
   cost null.
4. **B2:** `gemini-3.1-pro-preview` and `gemini-3.5-flash` produce exact expected costs (exact-value
   assertions, not "not null" — the Spec 037 lesson). Unpriced model → null-cost row **and** one warning;
   a second call with the same model on the same instance logs no second warning.
5. **B3:** batch import of a payload row with `condition: 'renewed'` → product exists with
   `is_ignored === true`, `status === null`, **no** `ProcessPendingProduct` dispatched (`Queue::fake()`).
   Same for `POST /api/product-import` (new product) and `OfferIngestionService` (created path). A clean
   row still dispatches and keeps `pending_ai`.
6. **Migration:** seed one `pending_ai + is_ignored` product and one `pending_ai + not ignored`, run the
   migration → only the first is cleared.

Run `php artisan test`; baseline is 710 passed / 21 skipped. Zero regressions.

## 3. Out of scope

- Spec 037 T2/T3, index changes, `AiUsage` query scopes (A5 — next pass), `generateCategoryImage()`
  bypass, `MassPrunable`, the `$category->features->isEmpty()` non-dispatch case in `OfferIngestionService`.

## 4. Deploy notes

- Adds one migration (data-only). `/deploy` runs `migrate --force`. No extension change, no endpoint
  change, no `.env` change.
- After deploy the owner runs the **headsets rescan** in the extension (pw2d tenant); the first
  `ai_usage` rows should show `tenant_id = 'pw2d'`, `purpose` in (`rescan_features`, `evaluate_product`,
  `match_product`), non-null cost. Check:

```sql
SELECT tenant_id, purpose, model, COUNT(*), SUM(estimated_cost_usd IS NULL) null_cost,
       ROUND(SUM(estimated_cost_usd), 4) cost
FROM ai_usage GROUP BY tenant_id, purpose, model;
```
  Any `tenant_id = NULL` row from a job purpose, or any `null_cost > 0`, means the fix did not land.
