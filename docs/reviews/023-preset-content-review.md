# Review: Spec 023 — Preset-Aware Compare Content Depth

**Date:** 2026-06-19
**Reviewer:** @reviewer
**Status:** Approved with comments (SHIP — zero blockers)

Scope reviewed: `AiService::generatePresetContent`, `GeneratePresetContent` command, `SeoSchema` (FAQPage merge + preset meta chain), `ProductCompare::activePreset`, `Preset` model, migration, `product-compare.blade.php` + `partials/compare-faqs.blade.php`, `PresetResource` + `PresetsRelationManager`.

---

## Verdict

**No blockers. Ship it.** The load-bearing SEO policy (no `price`/`priceCurrency`) is intact, slug derivation is consistent across all three call sites, the XSS trust boundary matches the existing Spec 021 precedent, and tenant scoping holds on both the web and command paths. The findings below are one SHOULD-FIX (a redundant-query cleanup the spec explicitly asked me to flag) and several NITPICKs.

---

## BLOCKER

None.

### Policy assertion (1) — SEO schema: PASS, no regression
The FAQPage merge in `SeoSchema::forLeafCategory` (`app/Support/SeoSchema.php:341-380`) only **appends** a `FAQPage` schema to the `$schemas` array. The Offer block lives exclusively in `forSelectedProduct` (`SeoSchema.php:150-173`) and was not touched — it still emits only `@type`, `availability`, `url`, `seller`, with the policy comment intact. `buildItemListSchema` (`SeoSchema.php:434-447`) still omits price. Grep for `price`/`priceCurrency` in the diff returns nothing in any emitted schema. The preset meta-description chain (`SeoSchema.php:301-311`) only sets the human-readable `description` string, never a schema price. **Policy compliant.**

---

## SHOULD-FIX

### S1 — Redundant preset resolution: the active Preset is queried twice per render
`app/Livewire/ProductCompare.php:520-540` + `app/Support/SeoSchema.php:291-295`

The brief flagged this explicitly, and it is real. On every full render of a preset URL:

1. `ProductCompare::activePreset` (`ProductCompare.php:117-121`) fires `category->presets()->with('presetFeatures.feature')->get()` and slug-matches in PHP.
2. `render()` then passes the **slug string** `$this->activePresetSlug` into `SeoSchema::forCategoryPage` (`ProductCompare.php:527`), and `SeoSchema::forLeafCategory` independently re-runs `Preset::where('category_id', ...)->get()` and slug-matches again (`SeoSchema.php:293-295`).

That is two near-identical `presets` table reads for the same row. `SeoSchema` even **returns** a resolved `activePreset` in its payload (`SeoSchema.php:389`), but the caller discards it and uses the computed instead (`ProductCompare.php:539`). The `#[Computed]` attribute memoizes call (1) within the request, so it does not compound, but call (2) is pure waste.

**Concrete fix (pick one):**
- **Preferred:** pass the already-resolved model into the schema builder instead of the slug. Add an optional `?Preset $activePreset = null` param to `forCategoryPage`/`forLeafCategory`; in `render()` pass `$this->activePreset`. When provided, skip the internal `Preset::where(...)` lookup at `SeoSchema.php:293`. This collapses to a single query and guarantees the body and the schema can never resolve different presets (a latent consistency win on top of the perf win).
- **Alternative:** consume the value SeoSchema already returns — assign `$seo['activePreset']` to a local and pass that to the view instead of re-reading `$this->activePreset`. Removes the computed's query instead of the schema's. (Slightly worse, because the computed is the more reusable surface.)

Not a blocker: it is one extra indexed single-category `get()` on a tiny table, not an N+1 that scales with products. But it is gratuitous and the two independent resolutions are a future divergence hazard.

---

## NITPICK

### N1 — `activePreset` computed eager-loads `presetFeatures.feature` it never uses on the render path
`app/Livewire/ProductCompare.php:117-121`

The computed loads `->with('presetFeatures.feature')`, but the Blade view only reads `$activePreset->seo_content`, `->name`, and `->seo_description` (`product-compare.blade.php:81-99`, `compare-faqs.blade.php:2`). The feature pivot is consumed by `AiService::generatePresetContent` (command path), not by the page render. Harmless (the eager-load is cheap and prevents N+1 *if* the view ever touches features), but it is dead eager-loading on the hot path. Consider dropping `->with(...)` here, or keep it and delete the now-misleading docblock line "avoid N+1 when the view accesses ... features" since the view does not. Low priority.

### N2 — FAQ dedup key differs between SeoSchema and the Blade partial
`app/Support/SeoSchema.php:355-360` vs `resources/views/livewire/partials/compare-faqs.blade.php:10-19`

The schema dedupes by **exact** question string (`in_array($faq['question'], ..., true)`), while the partial dedupes case-insensitively with `mb_strtolower(trim(...))`. So a preset FAQ "Are louder switches bad?" and a category FAQ "are louder switches bad?" would be **merged in the visible accordion but emitted twice in the FAQPage JSON-LD** — a (minor) schema/DOM mismatch Google could flag as FAQ markup not matching visible content. Align them: use the partial's normalized comparison in `SeoSchema` too. Low risk in practice (AI-authored questions rarely collide by case alone) but trivially correct to unify.

### N3 — Command's `flatMap(...)->each(setRelation('category'))` is a side-effect inside a map
`app/Console/Commands/GeneratePresetContent.php:77-85`

Using `->each(fn ($p) => $p->setRelation('category', $category))` to back-link the parent inside a `flatMap` collector works, but mutating-as-you-collect is slightly surprising. The category was already eager-loaded via `Category::...->with('presets...')`, so each preset's `->category` could instead be hydrated by loading presets through the inverse, or just left and accessed via the loop's known `$category`. Purely stylistic; current code is correct and avoids a lazy-load N+1 on `$preset->category->slug` later (`GeneratePresetContent.php:111,122`), which is the right instinct. No change required.

### N4 — `Preset` model missing `declare(strict_types=1)` and PHPDoc on relations
`app/Models/Preset.php`

CLAUDE.md asks for strict types in modern PHP. The new command, AiService method, migration, and SeoSchema all declare `strict_types=1`; `Preset.php` does not (pre-existing — the new code only added `seo_content` to `$fillable`/`$casts`). Out of strict scope for this spec, but worth a follow-up sweep. The `$fillable`/`$casts` additions themselves are correct.

### N5 — Two Filament edit surfaces duplicate the same `seo_content` field schema
`app/Filament/Resources/PresetResource.php:41-69` and `.../RelationManagers/PresetsRelationManager.php:30-57`

The intro textarea + FAQ repeater block is copy-pasted between `PresetResource` and `PresetsRelationManager` (DRY). Consider extracting a shared `static function seoContentSchema(): array` (e.g. on `PresetResource`) and referencing it from both. Cosmetic; both copies are currently in sync.

---

## Confirmations the brief asked for

- **(2) N+1 / query efficiency:** `activePreset` is a single memoized `#[Computed]` query, not per-product or per-render-loop. The redundant *second* resolution inside `SeoSchema` is real and flagged as **S1**. No N+1 introduced.
- **(3) Slug derivation consistency:** `Str::slug($preset->name)` is used identically in all three sites — command filter (`GeneratePresetContent.php:83,110`), `ProductCompare::activePreset` (`ProductCompare.php:121`), and `SeoSchema::forLeafCategory` (`SeoSchema.php:295`). **Consistent.** The intra-category slug-collision risk (two presets slugifying identically) is correctly flagged in code (`GeneratePresetContent.php:79-82`) and Spec 023 §10, and is **not** papered over — confirmed as a flagged pre-existing data risk, not fixed here.
- **(4) XSS / trust boundary:** the `{!! $introContent !!}` render (`product-compare.blade.php:99`) sources from `activePreset->seo_content['intro']` (AI-generated) or `category->buying_guide['intro']` — same trust boundary as Spec 021's existing intro. The only write paths to `seo_content` are the AI command and the admin Filament forms (admin-trust). No end-user input reaches it. The FAQ partial renders `{{ $faq['question'] }}` **escaped** and `{!! $faq['answer'] !!}` **unescaped** (`compare-faqs.blade.php:37,52`); the unescaped answer matches the existing category-FAQ trust boundary (admin/AI-authored only). **Acceptable, consistent with precedent.**
- **(5) Tenant scoping:** `Preset` uses `BelongsToTenant` (`Preset.php:14`), so all `Preset` queries are tenant-scoped by the global scope. Web path: `InitializeTenancyIfApplicable` middleware is global (`bootstrap/app.php:16`). Command path: `tenancy()->initialize($tenant)` wraps `process()` in a `try/finally` with `tenancy()->end()` (`GeneratePresetContent.php:40-46`) — iteration runs inside the initialized context, so the scope applies. Filament fields write `seo_content` on the bound `Preset` record, which is tenant-scoped on save. **No cross-tenant leak.** Note the spec is correct that no `tenant_id` column is needed on the migration — it is inherited via the model trait.
- **(6) Standards:** business logic is in `AiService` (scoring, prompt, validation) and the command, not in Livewire/Blade — the component's `activePreset` is a thin resolver. `declare(strict_types=1)` present on command, AiService, migration, SeoSchema. `$fillable` includes `seo_content` and `'seo_content' => 'array'` cast is set. The new AiService method carries a full PHPDoc with `@param`/`@return`/`@throws`. Validation mirrors `generateCompareContent` (key presence, faqs shape, string types). The spec's "don't copy the double-strip dead-code forward" note is satisfied — `generatePresetContent` (`AiService.php:717-720`) uses the **same single** fence-strip as `generateCompareContent` (`AiService.php:847-850`), so it is consistent with whatever 021 settled on.

---

## Praise

- The slug-collision risk is flagged in-code at the exact decision point with a pointer to the spec, instead of being silently swallowed — exactly the right call for a known latent data risk.
- Graceful degradation is clean: missing `seo_content` falls back to category intro/FAQs at every layer (Blade, schema, meta description), so the feature can roll out per-preset with no broken pages.
- The command's per-preset `try/catch` + `Log::error` + non-zero exit-on-any-failure mirrors the established `pw2d:seo:pull` exit-code contract, and the cost guard logs the call count before spending tokens.
- HTTP-render-level schema testing (per Spec 023 §7) is the right lesson carried forward from the `schemas[0]` layout bug.
