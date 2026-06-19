# Spec 023 — Preset-Aware Compare Content Depth

**Status:** Draft (awaiting approval)
**Author:** Lead Architect
**Date:** 2026-06-19
**Closes:** the pos-10 ceiling on preset compare pages (data from `docs/summaries/2026-06-13-seo-status-checkpoint.md` + 2026-06-19 check)
**Depends on:** Spec 021 (category-level intro/methodology/faqs), Spec 022 (top_query data that revealed this)

---

## 1. Why (the data)

Two weeks of post-deploy GSC data identified the exact bottleneck. The site's top organic
queries are **preset-specific, high-intent buying queries**, and they rank at the bottom of page 1:

| Query | URL | Impr (14d) | Position | Clicks |
|---|---|---|---|---|
| best mechanical keyboard for streamers | `/compare/mechanical-gaming-keyboards?preset=streamer` | 133 | 10.4 | **0** |
| best gaming headset for remote workers | `/compare/gaming-chat-headsets?preset=remote-worker` | 53 | 11.0 | **0** |
| best keyboard for streamers | `/compare/mechanical-gaming-keyboards?preset=streamer` | 23 | 13.5 | 0 |
| best minimalist keyboard | `/compare/productivity-ergonomic-keyboards?preset=minimalist` | 3 | 10.3 | 0 |

**Root cause (architectural):** `SeoSchema::forLeafCategory()` already emits **preset-specific
meta** (title / description / canonical with `?preset=`), but the **page body and FAQPage schema
are category-level only**. `generateCompareContent` produces ONE `intro` / `methodology` / `faqs`
per *category*; the same text renders regardless of `?preset=`. Google ranks the preset URL for a
use-case query ("...for streamers") but the page serves generic category content that doesn't match
the use-case intent. That mismatch is what caps these pages at pos ~10.

**Thesis:** Give each high-traffic preset its own use-case-specific content block + FAQs, so the
preset URL's *body* matches the query's intent. This is the precise, data-driven content-depth lever
— sharper than uniformly lengthening the generic intro.

## 2. Scope

**In scope**
- A per-preset content store: `intro` (use-case paragraph) + `faqs` (use-case Q&A).
- AI generation of preset content via `AiService` (domain method) + an artisan command.
- Render preset content on the compare page when `?preset=X` is active.
- Emit preset FAQs in the `FAQPage` JSON-LD when a preset is active.
- Admin edit surface (Filament) for preset content.

**Out of scope (explicitly deferred)**
- Generic (no-preset) category content expansion beyond Spec 021 — separate follow-up if needed.
- Core Web Vitals / page weight → **Spec 024** (the sequenced fast-follow).
- Any price/Offer schema change — frozen by `seo-schema-policy` memory.

## 3. Data model

`Preset` already owns `seo_description` (precedent for per-preset SEO data). Co-locate the new
content there rather than nesting a slug-keyed map in `category.buying_guide` (avoids slug-key
fragility, keeps preset SEO data on the preset).

**Migration:** add nullable JSON column `seo_content` to `presets`.

```
presets.seo_content  JSON NULL
  → { "intro": "<p>...</p>", "faqs": [ {"question": "...", "answer": "..."}, ... ] }
```

- `@builder`: add `seo_content` to `Preset::$fillable` and cast `'seo_content' => 'array'`.
- Migration must have `up()` + `down()`. No `tenant_id` needed (inherited via `category_id` → already
  `BelongsToTenant`). Confirm `Preset` uses `BelongsToTenant`; if not, that is a separate bug, not this spec.

## 4. AI generation

**New domain method** `AiService::generatePresetContent(Preset $preset): array` returning
`{ intro: string, faqs: list<{question,answer}> }`. Model = `admin_model` (same as Spec 021).

Prompt must receive: category name, preset **name** (the use-case, e.g. "Streamer", "Remote Worker"),
the preset's top-weighted features (from `feature_preset` pivot — name the 2-3 highest weights), and the
top 5 products *as ranked under this preset's weights* (so the copy references the actual winners a
visitor sees). Output rules:
- `intro`: ONE-TO-TWO `<p>` elements, **180-280 words**, addressing the specific use-case
  ("If you're buying a mechanical keyboard **for streaming**, the things that matter most are...").
  Must name the preset's priority features. 9th-grade reading level, no fluff.
- `faqs`: **3-4** entries, each question phrased as a use-case search query a buyer would type
  ("Are louder switches bad for streaming?"). Answers 40-100 words, concrete.

Reuse Spec 021's validation pattern (key presence, faqs shape, string types). Reuse the markdown-fence
strip. **Note:** the double-strip dead-code flagged in the handoff (`AiService` strips fences that
`GeminiService::generate` already stripped) — do NOT copy it forward; call `gemini->generate` and trust
its output, matching whatever 021 settled on.

**New command** `pw2d:generate-preset-content {tenant} {--category=} {--preset=} {--dry-run}`:
- Resolves tenant, initializes tenancy.
- Iterates presets (optionally filtered by `--category` slug and/or `--preset` slug, where preset slug
  = `Str::slug($preset->name)`), calls `generatePresetContent`, writes `preset.seo_content`.
- `--dry-run` prints would-be content without saving. Per-preset try/catch so one failure doesn't abort
  the batch (log + continue, non-zero exit if any failed — mirror the `pw2d:seo:pull` exit-code rule).
- Cost guard: log estimated calls before running (one `admin_model` call per preset).

## 5. Rendering (`@frontend`)

`ProductCompare` already resolves `activePresetSlug` from the URL. Resolve the active `Preset` model
(match `Str::slug($preset->name) === $activePresetSlug` within the category's presets) and expose it to
the view as a computed `activePreset`.

In `resources/views/livewire/product-compare.blade.php`:
- **Intro block (~line 92):** when `activePreset?->seo_content['intro']` is present, render it
  **in place of** the category `buying_guide.intro` (preset content is more specific). Fall back to the
  category intro when no preset is active or the preset has no content yet. Use `{!! !!}` (HTML is
  AI-generated `<p>` only — same trust boundary as Spec 021's intro; do not introduce user input here).
- **FAQ partial (`partials/compare-faqs.blade.php`, included ~line 508):** when a preset is active and
  has `seo_content['faqs']`, render the **preset FAQs first**, then the category FAQs (dedupe by
  question string). Keep the existing Alpine accordion markup.

No layout/visual redesign — reuse existing components. Mobile-first, existing Tailwind tokens.

## 6. Schema (`@builder`, `app/Support/SeoSchema.php`)

`forLeafCategory()` already branches on `$activePresetSlug` for meta. Extend the **FAQPage** emission
(currently reads `buying_guide.faqs` ~line 331):
- When a preset is active and has `seo_content['faqs']`, emit those (merged with category faqs, preset
  first, deduped) as the `FAQPage`.
- Meta description when preset active: prefer `preset.seo_description` → preset `seo_content` intro
  (stripped, truncated) → existing category fallback chain. (Aligns the existing preset-meta branch
  with the new content.)
- **Load-bearing policy assertions stay:** no `price`/`priceCurrency` anywhere. Do not touch the Offer
  block.

## 7. Tests (`@tester`)

Follow the handoff's hard lesson: **schema tests must use full HTTP render (`$this->get()`), not just
`Livewire::test()`**, because the `schemas[0]` bug lived in the layout and HTTP-level tests are what
catch it.

- `AiServiceTest`: `generatePresetContent` returns valid shape; throws on missing keys / malformed faqs
  (mock Gemini transport only — no live API, per standards).
- `GeneratePresetContentTest` (command): writes `seo_content` for matching presets; `--dry-run` saves
  nothing; `--category`/`--preset` filters scope correctly; one bad preset doesn't abort the batch;
  exit code reflects failures.
- `SeoSchemaTest` (HTTP render): `/compare/{slug}?preset={p}` emits FAQPage containing the preset's
  questions; without preset, falls back to category faqs; **asserts absence of `price`/`priceCurrency`**.
- `ProductCompare` render test: preset intro renders when present; falls back to category intro when not.
- Factories: extend `PresetFactory` with a `seo_content` state. Use `RefreshDatabase`.

## 8. Admin (`@builder`, Filament)

Expose `seo_content` on the preset edit surface (wherever presets are managed — likely a
`PresetResource` or a relation manager under `CategoryResource`). Two fields: a rich/textarea `intro`
and a repeater for `faqs` (question + answer). This lets an editor hand-tune the AI output, matching the
Spec 021 pattern for `buying_guide`.

## 9. Rollout

1. Migrate (`seo_content` column).
2. `pw2d:generate-preset-content pw2d --dry-run` → review copy quality for the 3 proven-winner presets
   first (streamer, remote-worker, minimalist), then run for real.
3. Deploy via `/deploy` (never auto). Verify: `curl .../compare/mechanical-gaming-keyboards?preset=streamer`
   shows the preset intro in HTML and the preset FAQs in the FAQPage JSON-LD.
4. **Measure against the bottleneck:** watch the streamer/remote-worker queries' position over 2-3 weeks.
   Success = those queries cross pos 10 → top 5 and CTR moves off 0%.

## 10. Risks / judgment calls

- **Preset slug derivation:** there is no `slug` column on `presets`; the URL uses `Str::slug(name)`.
  The builder MUST use the same derivation everywhere (render match + command filter) or the join breaks.
  If two presets in a category slugify to the same string, that's a pre-existing latent bug — flag it,
  don't paper over it.
- **Cost:** one `admin_model` call per preset. With ~5 categories × ~4 presets ≈ 20 calls ≈ ~$0.30 one-time.
  Acceptable; the command logs the count first.
- **Don't over-build:** if a preset has no `seo_content`, everything falls back to category content —
  the feature degrades gracefully and can be rolled out per-preset.
