# Runbook — Bouncer overflow in a Claude Code session (Spec 039)

**When to use:** after an import, when Gemini has stopped processing — products sit at `pending_ai` with
`429` rate-limit errors in the log, or at `status = 'failed'` after retries. Typical trigger: the admin
model's daily cap (~250 evaluations, see memory `gemini-daily-cap`).

**When not to use:** as a scheduled or unattended job. This path runs only with the operator in the
session. It uses the Claude Code subscription interactively, the same way landing-page prose is written.

## Sequence

```
1. Import (extension) as usual. Let Gemini drain what it can.
2. Check the backlog:
     mysql> SELECT status, COUNT(*) FROM products WHERE status IN ('pending_ai','failed') GROUP BY status;
     mysql> SELECT COUNT(*) FROM jobs;            -- MUST be 0: a queued Gemini job would re-process (and overwrite)
                                                 -- a product the session just finalized. apply-evaluations refuses
                                                 -- to run while jobs are queued (--force overrides, do not use it casually)
3. Export:
     php artisan pw2d:products:export-pending pw2d <category-slug>
     → storage/app/bouncer/pw2d-<slug>-<timestamp>.json   (read-only; nothing changes)
4. Claude evaluates (see "How Claude evaluates" below) → evaluations.json
5. Dry run — must report errors 0:
     php artisan pw2d:products:apply-evaluations pw2d evaluations.json --dry-run
6. Apply:
     php artisan pw2d:products:apply-evaluations pw2d evaluations.json
7. Confirm the rows landed:
     mysql> SELECT model, COUNT(*), ROUND(SUM(estimated_cost_usd),4) FROM ai_usage
            WHERE purpose='evaluate_product' AND created_at >= CURDATE() GROUP BY model;
     -- expect a 'claude-code-session' row at cost 0.0000
8. Rescan the category in the extension (the new products have health_checked_at = NULL).
9. php artisan pw2d:landing-pages:audit → dry-run selection → regenerate if the page is stale.
```

`apply-evaluations` is safe to re-run: products that are no longer `pending_ai`/`failed` are skipped, so
a partially applied file can simply be applied again.

## How Claude evaluates

The export file carries everything needed: `rules` (the same gate rules Gemini gets, plus the
`wrong_category` paragraph), the category's `features`, `brands` (existing brand spellings for this
tenant), `anchors` (already-scored products with their scores — calibration, not templates), and
`products`.

Work in **batches of ~20 products, one subagent per batch (`model: opus`), all subagents given the identical brief**.
The brief is the export's `rules` + `features` + `anchors` + `brands` + the batch's `products`, and this
output contract:

```
Return ONLY a JSON object: {"evaluations": [ ... one per product, same order ... ]}

Scored product:
  {"product_id": <id>, "name": "Brand Model (≤ 60 chars, ≤ 8 words)", "brand": "Normalized brand",
   "ai_summary": "Two blunt sentences.", "price_tier": 1|2|3|null,
   "features": {"<feature name exactly as given>": {"score": 1-100, "reason": "one sentence"}, ...}}

Ignored product:
  {"product_id": <id>, "status": "ignored",
   "reason": "accessory_or_bundle" | "generic_white_label" | "renewed_or_refurbished" | "wrong_category"}

Rules that matter most:
- Score from world knowledge of the specific model. 50 = average. Budget brands never score 80+ on
  quality features. Unknown model → infer from brand tier + price, default 40–50. Create contrast: a weak
  or irrelevant feature scores 20–40.
- Use the anchors to calibrate the scale for THIS category; do not copy their numbers.
- If the product is a real device but belongs in a different category (a shotgun mic in a lavalier
  category), use "wrong_category" — do not score it.
- Brand: if an entry in `brands` is clearly the same brand, use that exact spelling.
- Every feature listed for the category must appear in `features` (use null only if truly not applicable).
- No markdown, no commentary, valid JSON only.
```

Merge the batches into one `{"evaluations": [...]}` file. Validate before applying — the `--dry-run`
prints every row's outcome; fix or drop `error` rows, never hand-edit product ids.

**Budget (measured 2026-08-28):** ~50–58k tokens per 17-product subagent batch ≈ **3k tokens per product**
all-in; a 91-product backlog ≈ 6 batches ≈ 300k tokens and ~5 minutes of wall time with batches in parallel.
Plan it inside one 5-hour window.

## Calibration brief (mandatory — learned 2026-08-28)

Round 1 of the lavalier calibration scored a uniform **~10 points below** the site's stored scale on every
feature while agreeing on brand (100%) and keep/ignore (98%). The site's scale is *not* "50 = average":
Gemini's stored scores cluster high (47% of them 80–100). Every scoring brief must therefore carry a
`calibration` block, derived from the category's stored means (`export-pending` anchors show them):

- **Bands on this site:** 88–95 category-leading feature on a top-brand flagship · **78–87 solid mainstream
  product from a reputable brand (the most common band — use it freely)** · 65–77 decent budget-brand
  product or a weak feature on a good one · 50–64 no-name gear · 20–49 only for a genuinely poor or
  missing capability.
- **Conventions:** passive wired lavaliers (no battery) get Battery Endurance 90–99 ("nothing to run
  out", SmartLav+ anchor = 95); Noise Isolation is the one feature kept low (unprocessed omni capsules
  40–60); "budget brands never 80+ on quality features" still holds; wired lavaliers are in-category.
- **Anchors are the scale**, not templates. Unknown model → brand tier + price, default 55–65.

With that block, round 2 on the same 50 products came back: ignore 100%, brand 100%, bias +1.7, MAD 7.1
(84% of scores within ±10; batches within 2 points of each other). The residual is dominated by Gemini's
own inconsistency (it scored three passive wired lavs' Battery Endurance at 20 and two others at 95–99).

## Trust gate (Spec 039 T5)

Until the session path has passed the replay gate, it is the **overflow** path only — Gemini first.
To run the gate:

```
php artisan pw2d:products:export-pending pw2d <slug> --status=processed --limit=50   # blind export
# Claude evaluates the 50 exactly as above → calibration.json
php artisan pw2d:ai:eval-model pw2d --from-file=calibration.json
```

**Overflow-path pass mark (owner decision 2026-08-29):** `is_ignored` agreement ≥ 95 %, brand exact-match
≥ 98 %, mean signed bias within ±3, feature-score mean absolute delta ≤ 8. (The stricter ≤ 5 rule remains
for Spec 037 model swaps.) Lavalier round 2 passed: 100 % / 100 % / +1.7 / 7.08. Record every run in
`docs/tasks/todo.md` under Spec 039. Two passing runs on different categories before proposing session-first
(Spec 039 §6). Scorer model: calibrated 2026-08-29 on the same 50 products — Fable 5 MAD 7.08, Opus 5 6.61, Sonnet 5 7.58 (all pass;
verdicts and brands equal on equal footing). **Default: Opus 5 (`model: opus` on every scoring subagent — owner decision 2026-08-29; Fable's subscription
allowance is half of Opus's and is reserved for planning).** Sonnet 5 passes but used more tokens per batch, not fewer. Any other model needs its own run.

## What can go wrong

| Symptom | Cause | Do |
|---|---|---|
| dry-run shows `error: unknown feature` | a subagent renamed a feature | fix the key to the exact category feature name |
| dry-run shows `error: missing feature "X" for category "Y"` | a subagent omitted a category feature key entirely (not even an explicit `null`) | add the key; use `null` if the feature is genuinely not applicable |
| dry-run shows `skipped: status processed` | Gemini finished it meanwhile | fine — leave it skipped |
| apply exits 1 | at least one `error` row | fix those rows, re-run; applied rows are skipped automatically |
| apply exits 2, `Refusing to apply: N job(s) still queued` | step 2's `SELECT COUNT(*) FROM jobs` was skipped, or a job was queued after the check (worker restart, `queue:retry`, another import) — a queued `ProcessPendingProduct` job ignores product status and can overwrite what this apply just finalized | wait for the queue to drain and re-run; `--force` overrides but only if you have confirmed no product in this file overlaps with a queued job |
| no `claude-code-session` row in `ai_usage` | apply ran with `--dry-run`, or the pricing-map entry is missing (row would show cost NULL + a warning in `laravel.log`) | check `config/services.php` pricing map |
| page still stale after regenerate | products unchecked → rescan was skipped | step 8 before step 9 |
