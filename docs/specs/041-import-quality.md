# Spec 041 — Import quality: right product type, clean names, real model identity, verified picks

**Status:** APPROVED 2026-10-04 — owner took all three recommendations (see *Decisions*).
**Must ship before the next import** (podcast-studio-mics top-up, cold-brew-makers top-up).

## Goal

Four linked import defects lead to bad picks on live `/best/` pages. Every figure below was measured on
prod on 2026-10-04 (read-only):

| # | Defect | Evidence (prod, 2026-10-04) |
|---|---|---|
| 1 | **Wrong product type gets accepted.** The Gemini Bouncer has no "wrong type" rule. Rule A covers accessories only, and the prompt says "when in doubt, SCORE". | 09-21 ergonomic import: ~11 of 47 accepted were not standalone keyboards (mice, a numpad, combos, switchless kits); the first dry-run chose a mouse as the budget pick. 08-28 lavalier import: ≥15 shotgun mics. Both imports ran through Gemini (`ai_usage` 08-25 → 10-04: only `evaluate_product` / `gemini-3.1-pro-preview`, no session rows). Rule B also leaks: **17 live products** have brand `Generic`/`Unbranded` (16 on coffee2decide; none is a pick). |
| 2 | **Names are cut-down Amazon titles.** `FinalizeProductEvaluation:86` throws the AI's name away when it is shorter than 20 characters and uses the raw title instead. Every example name in the prompt is under 20 characters (`Shure MV7+`, `Sony WH-1000XM5`, `Rode NT-USB Mini`). | **563 of 1,268 live names (44%)** are a prefix of their offer's raw Amazon title. 88 don't start with their brand (`UWP-D`, `Advantage2 Ergonomic Keyboard`, `K70 PRO TKL RGB Tenkeyless Mechanical Wired Gaming`); some end in `-` or `\|`. Product page title = `{name} {category} — AI Review & Match Score`, so these are the live titles. 2 of the 77 live picks have a brandless name. |
| 3 | **The same-model pick guard groups different products together, and misses real duplicates.** `SelectLandingPagePicks::modelKey()` uses the first token containing a digit as the product's identity. Once two products have keys, the key alone decides. | Live pools: pw2d mechanical keyboards puts **113 of 203** products in shared groups. `Razer:v3` merges 13 different keyboards (BlackWidow V3, Ornata V3, Huntsman V3 Pro…); `Razer:v4` merges 8. `Jabra:evolve2` merges the 30/50/55. `1Zpresso:1zpresso` merges all 6 grinders (the brand name became the key); `Hario:v60` merges 9; `Chemex:8` (from "8-Cup") merges two models. Missed duplicates: products with no digit fall back to string similarity (47 of 128 ergonomic, 78 of 135 semi-automatic), e.g. 4× Breville Oracle Jet colourways and 3× Logitech Wave Keys. Rejected candidates are not logged. |
| 4 | **Picks don't require a health check.** `ListingHealth::isPurchasable()` treats a NULL `condition`/`listing_flags` as clean, so an offer nobody ever checked looks the same as one checked and clean. | Today **0** of the 77 live picks are unverified, and only 2 live products have no checked offer. Nothing is wrong now: this guards every Tier-3 import, which lands with `health_checked_at = NULL`. Until now only a process rule enforced it. |

**Root of 2 and 3:** nothing stores what the product *is*. The name has to act as both display text and
identity, and both rules are guessed from it. The fix is an explicit `model` field that the Bouncer writes at
import. The name then becomes display text only.

## Design

### Part 1 — Product type gate

- **New column `categories.bouncer_notes`** (text, nullable). Per category, in plain words: what does **not**
  belong, and what counts as a different model. Example (productivity-ergonomic-keyboards):
  *"Exclude mice, numpads, keyboard-and-mouse combos, keycap sets, and barebones or switchless kits. Different
  layout (full-size, TKL, 75%) or generation (V2, Gen 2) = different model."* Category-level judgment
  belongs to the owner and Claude; the pipeline applies it per product. Claude drafts all 11, the owner
  approves the wording, and the notes can be edited in Filament.
- **New Stage 1 rule D in `BouncerRules::text()`: WRONG PRODUCT TYPE.** It applies when the product is clearly a
  different kind of product than `{category}` or matches the category notes. It returns
  `{"status":"ignored","reason":"wrong_category"}`. `FinalizeProductEvaluation` already routes this reason
  to `AiCategoryRejection` and a detach (Spec 039), so no new finalize code is needed. A false positive is
  recoverable: the product is detached, not deleted or hidden.
- Rule D sits in the shared text, so **`BouncerRules::sessionAddendum()` is now redundant and is deleted**.
  `ExportPendingProducts:135` uses `text()` only.
- The category notes go into the prompt directly after rule D, under `Category-specific rules:`. If the notes
  are empty, that line is left out.
- **Deterministic generic-brand guard** in `FinalizeProductEvaluation`: if the brand normalises to
  `generic | unbranded | no brand | unknown`, treat the product as `ignored / generic_white_label`, whatever
  the AI returned. Rule B leaked 17 times, so this check moves into code.

### Part 2 — Names

- **The Bouncer returns a new field, `model`:** the model identity a buyer would name, **without** brand,
  colour, size, material, finish, bundle contents, pack count or region. It **keeps** tier (Pro, Max, Mini,
  Plus, X, SE), generation (V3, Gen 2, 5th Gen) and form factor (TKL, 75%). The prompt carries worked
  examples taken from the table above, e.g. `BlackWidow V3` ≠ `Huntsman V3`; `Evolve2 50` ≠ `Evolve2 55`;
  `V60 Dripper` for every V60 size and material; `Oracle Jet` for every colourway.
- **NAME RULE amended:** the name must start with the brand exactly as returned in `brand`. Category nouns
  ("Manual Coffee Grinder", "Wireless Mechanical Keyboard") come out, because the page title already
  appends the category.
- **Remove the < 20-character fallback.** The AI name is always used. Only if it holds nothing beyond the
  brand is it rebuilt as `"{brand} {model}"`. The raw Amazon title is never a name source again.
- **New `App\Support\ProductNameShaper`** replaces `capProductName()` (all deterministic, run after the AI):
  1. drop leading bracketed junk (`【Iron Gray】`);
  2. cut at the first `,` `(` `|` `【`;
  3. remove trailing connectors (`-` `–` `|` `/` `&` `+`) and trailing stopwords;
  4. brand prefix: if the name starts with the brand (case-insensitive) → replace that span with the canonical
     brand spelling (`SE ELECTRONICS V7` → `sE Electronics V7`); if the brand appears nowhere → prepend it;
     if the brand appears later in the name → leave it;
  5. cap at 8 words.
- **Existing names are not touched by this build.** Renaming changes live page titles, so that is open
  question 1.

### Part 3 — Model identity for the pick guard

- **New column `products.model`** (string 120, nullable). Written by finalize from `$eval->model()`, shown
  and editable in Filament (`ProductResource` form, plus a searchable table column).
- **Identity in `SelectLandingPagePicks`:** when both products have a `model`, the pair is a duplicate if
  `brand_id` matches **and** `normalize(model)` matches (lowercase, alphanumerics only), and that decides.
  When either `model` is NULL, the **current** logic runs unchanged (`modelKey()`, then the similarity
  fallback). Deploying therefore changes no page selection on its own; pages change only as models are
  backfilled, and each backfill shows its page impact first (below).
- **Log every duplicate rejection** (`Log::info`, both product ids, the key that matched, and the
  path: model, heuristic or similarity).
- **Backfill (one-time, run in session by Claude, not by the pipeline):** Claude reads each category's live
  products (id, brand, name, first raw title) on prod and writes `{product_id: model}` JSON per category.
  - **New command `pw2d:products:apply-models {tenant} {file} {--dry-run}`.** It validates that every id
    belongs to the tenant and is live. `--dry-run` prints (a) every group of 2 or more products that will
    count as the same model, and (b) **page impact**: inside a rolled-back transaction, it writes the models,
    re-runs `SelectLandingPagePicks` for the category, and diffs against the stored picks. Without the
    flag, it writes `model` through `Product::update()`. The observer ignores `model`
    (`ProductObserver:38-39` reacts to `is_ignored`/`category_id` only), so no job fires.
  - The owner reviews the multi-member groups only (expected: tens of groups, not 1,268 rows).
- **Retiring the heuristic** is a follow-up line. Once
  `SELECT COUNT(*) FROM products WHERE model IS NULL AND is_ignored=0 AND status IS NULL` returns 0,
  delete `modelKey()`, `tokenize()` and the `MODEL_TOKEN_*` constants.

### Part 4 — Picks need a health check

- **New `ListingHealth::isPickEligible(ProductOffer $offer): bool`** = `isPurchasable($offer) &&
  $offer->health_checked_at !== null`. Used by **both** `SelectLandingPagePicks::hasEligibleOffer()` and
  `AuditLandingPageFreshness::hasEligibleOffer()`, so selection and audit can't disagree.
- `isPurchasable()` itself is **not** changed: it also drives `Product::bestOffer` (prices across the site),
  and an unchecked offer must keep showing a price.
- Add `health_checked_at` to `ListingHealth::OFFER_HEALTH_COLUMNS`. If a caller fails to select it, the value
  reads as NULL, which counts as unchecked and excludes the offer (fails closed, unlike the 08-15 condition
  bug, which failed open).
- When the pool is below `MIN_PICKS`, the exception message adds "N products await a health check" if that is
  why. The owner then sees "rescan first", not "pool too small".

## File structure

| File | Change |
|---|---|
| `database/migrations/2026_10_xx_add_import_quality_columns.php` | `categories.bouncer_notes` text null; `products.model` string(120) null. `down()` drops both. No index (both are read in PHP over a bounded category pool). |
| `app/Support/BouncerRules.php` | `text(string $categoryName, ?string $categoryNotes = null)`: rule D, notes block, `model` in the JSON shape, amended NAME RULE. Delete `sessionAddendum()`. |
| `app/Services/AiService.php` | `evaluateProduct(..., ?string $categoryNotes = null)` passes the notes through. |
| `app/Jobs/ProcessPendingProduct.php` | Passes `$category->bouncer_notes`. |
| `app/Actions/ExportPendingProducts.php` | `rules` = `BouncerRules::text($category->name, $category->bouncer_notes)`. |
| `app/Support/ProductEvaluation.php` | Optional `model` (trimmed, capped at 120, **never throws when missing**: in-flight jobs and old fixtures have none). `model(): ?string`. |
| `app/Support/ProductNameShaper.php` | **New.** `public static function shape(string $name, string $brand): string`. |
| `app/Actions/FinalizeProductEvaluation.php` | Remove the < 20-char guard and `capProductName()`; use `ProductNameShaper`; write `model`; generic-brand guard. |
| `app/Support/ListingHealth.php` | `isPickEligible()`; `health_checked_at` in `OFFER_HEALTH_COLUMNS`. |
| `app/Actions/SelectLandingPagePicks.php` | Model-first identity, rejection logging, `isPickEligible`, clearer `MIN_PICKS` message. |
| `app/Actions/AuditLandingPageFreshness.php` | `hasEligibleOffer()` → `isPickEligible`. |
| `app/Console/Commands/ApplyProductModels.php` | **New.** `pw2d:products:apply-models`. |
| `app/Filament/Resources/CategoryResource.php`, `ProductResource.php` | `bouncer_notes` textarea; `model` input and column. |
| `app/Models/Category.php`, `Product.php` | Add the new columns to `$fillable`. |

## Tests (Pest)

- **Prompt snapshot** (`AiServicePromptSnapshotTest`): re-capture the fixture on purpose, plus one case with
  notes and one with no notes (no notes line in the output).
- **`ProductNameShaper`:** table-driven, using the **real prod names above** as inputs (`UWP-D`+Sony → `Sony UWP-D`;
  `MVL Lavalier Microphone for iPhone & Tablet -`+Shure → no trailing `-`, brand-first;
  `【Iron Gray】KINGrinder K7 …` → `Kingrinder K7 …`; `RK ROYAL KLUDGE S70 …` unchanged brand position).
- **Finalize:** a short AI name (`Shure MV7+`) is kept (regression for the < 20 guard); a brand-only name →
  `{brand} {model}`; `Generic` brand → ignored; `wrong_category` from the **Gemini** path → rejection + detach.
- **Picker:** 13 "Razer … V3" names with distinct models → no false merge; 4 "Breville Oracle Jet …" names with
  model `Oracle Jet` → one pick; mixed pair (one model NULL) → old path; an offer with
  `health_checked_at = NULL` → not eligible in **both** Select and Audit (one shared fixture, so they can't
  drift); `MIN_PICKS` message names the unchecked count.
- **apply-models:** wrong-tenant id rejected; `--dry-run` writes nothing (assert after) and reports groups + page diff.

## Multi-tenant impact

Both columns sit on tenant-scoped tables (`categories`, `products` use `BelongsToTenant`). No new table. The
apply command takes `{tenant}`, initialises tenancy, and rejects any id outside it. Validation must
use an explicit `where('tenant_id')`, because `Rule::exists` bypasses the global scope (2026-08-21 audit
finding).

## Rollout

1. Build (one build, one deploy). Run `php artisan test`.
2. **Prompt calibration before deploy**, through `AiService::evaluateProduct` on prod data (~46 calls,
   ≈ $0.85, within the Gemini daily cap): the ~11 hidden ergonomic non-keyboards, the ≥15 shotgun mics, and
   20 known-good products from the same categories. **Pass:** ≥ 90% of known-wrong come back
   `wrong_category`/`accessory_or_bundle`, **0** known-good are ignored, every returned `model` reads
   right. *What this would fail to see:* a type it never sees here. So the standing step "read every accepted
   title after an import" stays until two imports in a row come back clean.
3. `/deploy` on the owner's go. Job code changes, so `queue:restart` is required and fresh worker PIDs must be
   checked (2026-08-28 lesson).
4. Fill the 11 `bouncer_notes` (owner-approved text).
5. Model backfill per category: Claude drafts, `--dry-run`, owner reviews the groups and page impact, then apply.
   In the same pass Claude lists the **live** products the category notes now exclude (2026-10-04: keyboard
   combos and membrane boards are still live in both keyboard categories) — owner decides hides, per record.
   A page whose picks change goes into the normal content path (rebuild → owner review → publish), never
   straight to live.
6. Then the podcast-studio-mics and cold-brew-makers top-ups proceed.

## Dependencies

Spec 039 (`wrong_category` finalize path, export), Spec 034 (picker, brand cap), Spec 031 (cadence), Spec 037
(cost figures). No new packages.

## Out of scope / follow-ups (→ backlog)

- **93 live products carry a rejection row for their own category** (podcast mics 52, ergonomic 15,
  mechanical 12, headsets 9, lavalier 5 — prod 2026-10-04). Some rejections were right and the product was
  never removed (MK550 / POP Keys combos, G213 / Ornata membrane boards); some are wrong (Shure SM58 2-Pack is
  a live pick; Jabra Evolve2 serves the remote-worker preset). Harmless to SERP top-ups —
  `BatchImportController` only refreshes price/rating for a tracked offer — but the **single-product import**
  (`ProductImportController:129-134`) renames and re-slugs a tracked product from the raw title, sets
  `pending_ai`, and finalize then re-slugs it again and detaches it if a rejection row exists. Any
  single-import of a live product therefore changes its URL (no slug redirects exist).

- **Merge real duplicates.** Identical rows exist: Turtle Beach Stealth 600 ×3, Keychron C1 ×7, Oracle Jet ×4,
  Jura Z10 family. Once models exist, `brand_id + model + colour` makes them queryable. That is a data-cleanup
  job for `pw2d:merge-duplicates`, not the picker.
- Delete the `modelKey()` heuristic once every live product has a model (see Part 3).

## Decisions (owner, 2026-10-04)

All three recommendations accepted: (1) rename only the 88 brandless/junk-ended names now — Claude drafts old → new in `docs/drafts/`, owner reviews, slugs unchanged; full rename later as a one-tenant experiment; (2) hide the 17 `Generic`/`Unbranded` products; (3) combos and switchless kits are excluded in the keyboard notes.

### Original questions

1. **Rename existing products?** 563 live names are raw-title cuts, and renaming changes their page titles
   (product pages bring ~70% of impressions). **Recommended:** fix only the 88 brandless or junk-ended names
   now (owner-reviewed list, slugs unchanged). Try the full rename on one tenant later as a measured SEO
   experiment.
2. **Hide the 17 `Generic`/`Unbranded` products?** None is a pick. Hiding is per record, through the model,
   backed up first. **Recommended:** yes.
3. **Category notes:** keyboard-and-mouse combos and switchless kits are written as *excluded*, matching the
   09-21 hides. Confirm, or name any category where kits belong.

## Build notes (2026-10-04)

Built in one pass; suite 858 → 905 passing (21 skipped, unchanged), verified by the architect. Deviations,
all accepted:
1. The shaper drops a standalone ` +` only — a `+` attached to a token stays (`Shure MV7+`).
2. Brand checks are whole-word (`Rode` is not "present" in `Rodecaster Pro II` → `Rode Rodecaster Pro II`).
3. A brand-only AI name with no `model` stays brand-only (no raw-title fallback left to use).
4. Model-first identity needs `brand_id` on both sides; otherwise the old path runs.
5. `apply-models` takes a flat `{product_id: model}` file, derives categories, aborts the whole file on any
   invalid id or blank model, and re-reads rows after the dry-run rollback before writing.
6. `evaluateProduct()` takes `$categoryNotes` as its last parameter (after `$tenantId`).

## Prompt calibration (2026-10-04)

48 live cases exported read-only from prod (pw2d: lavalier, ergonomic, mechanical), run locally through the
built `AiService::evaluateProduct()` on the prod model (`gemini-3.1-pro-preview`) with the owner-approved
category notes (`docs/drafts/2026-10-04-bouncer-notes.md`). Raw title as the product name, exactly as an
import sees it. ~48 calls ≈ $0.89; not in prod `ai_usage` (ran locally).

- **Known-wrong: 27 / 27 caught**, all `wrong_category` — 12 shotgun / on-camera / podcast mics in lavalier;
  11 mice, trackballs, combos, desktop sets, a keypad and DIY split kits in ergonomic; a membrane board, a
  headset, a USB mic and a barebones kit in mechanical.
- **Known-good: 20 / 21 scored.** The one ignore is **#3022 Keychron K8 HE TKL White** (ergonomic,
  `preset:programmer` pick) — the gate applied the approved ergonomic note ("gaming-first boards sold on
  rapid trigger or Hall-effect switches belong in Mechanical Gaming Keyboards"). Not a prompt fault: the
  note and a live pick disagree, and the same keyboard (black, #2761) is the overall pick on mechanical.
  Owner decision.
- **Names and models read right on all 20**: brand-first, no category nouns (`Shure GLXD14+/93` where prod
  stores the brandless `GLXD14+/93 Dual Band Pro Digital W…`); models separate tiers and generations
  (`Mic 2` / `Mic 3`, `Q2 Max`, `GMMK 3 Pro HE`, `Advantage360 Professional`) and drop colour
  (`Wave Keys` for "Wave Keys Rose").

Verdict: **pass** on catch rate and model quality; the single false ignore traces to note content, not the rule.
