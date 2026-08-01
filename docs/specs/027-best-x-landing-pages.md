# Spec 027 — "Best X" Landing-Page System (the code-shaped authority play)

**Status:** DRAFT — awaiting owner approval
**Origin:** 2026-07-10 checkpoint verdict — pw2d's constraint is off-page/authority. Landing pages are the one code-shaped piece: linkable, intent-matched listicle pages built from our scoring data.
**Numbering note:** this takes 027; "product-page content depth" (ex Spec-026-candidate, briefly logged as 027 in the checkpoint doc) becomes the 028 candidate.

## 1. Why

- pw2d preset/compare pages plateau at pos ~10-15 (authority cap). "Best X" listicle queries are the highest-volume commercial intent in both niches, and *listicle-shaped pages earn links* in a way interactive tools don't — bloggers link "The 7 Best…", not "a comparison tool".
- coffee2decide's first GSC read (2026-07-19) confirms demand concentration: "best superautomatic espresso machine" is the top query surface — currently landing on the compare page at pos ~64.
- The compare page serves *tool* intent; the landing page serves *answer* intent. Distinct pages, cross-linked, no cannibalization: the landing page targets "best X (2026)" queries, the compare page keeps "compare X" and preset queries.

## 2. What a landing page is

A **static-feeling, fast, data-driven listicle** for one leaf category:

1. **H1 + intro** — "The {N} Best {Category} in {YEAR}", intro explains the data-driven method (we scored {count} products on {features} — the honest E-E-A-T story), "Updated {Month Year}" badge from `generated_at`.
2. **Ranked picks** (5-8), each with a **role**: Best Overall, Best Budget, Best Premium, and "Best for {Preset}" for the category's top 2-3 presets. Each pick renders: image, name, estimated price (`estimated_price` accessor — rounded, policy-compliant), 2-3 AI-written paragraphs (why it won, who it's for, tradeoff), feature-score highlights (from `ProductFeatureValue`), CTA → product page + affiliate link, secondary link → compare page with the relevant preset.
3. **"How we ranked"** methodology block (static partial, links to compare tool).
4. **FAQs** (AI-generated, category-specific, NOT duplicating compare-page/preset FAQs — the generation prompt receives existing FAQ questions as an exclusion list).

**Critical design rule — picks are chosen by DATA, not by the AI.** The generation command selects products deterministically (scoring queries below); the AI only writes prose *about* the pre-selected picks. Keeps the "data-driven" claim honest and the page regenerable.

## 3. URL & freshness strategy (decision + rationale)

- **URL:** `/best/{slug}` (e.g. `/best/super-automatic-espresso-machines`) — **year NOT in URL**.
  Rationale: links accrue to one permanent URL across years; the year lives in title/H1/content and updates on regeneration. A `/best-x-2026` URL would orphan earned backlinks every January — the one asset this whole initiative exists to build. Google matches "best x 2026" queries via title/content, not URL tokens.
- **Title:** "The {N} Best {Category} in {YEAR} — Ranked by Data" (year = current year at render; content refresh via `--regenerate` keeps prose aligned; picks re-derive from live scores at each regeneration).
- Canonical self; `og:type=article`.

## 4. Data model

New table `landing_pages` (single-DB multi-tenant rules apply):

| column | type | notes |
|---|---|---|
| id | bigint pk | |
| tenant_id | string FK tenants.id | `BelongsToTenant` |
| category_id | FK categories.id | leaf category, one landing page per category (unique `(tenant_id, category_id)`) |
| slug | string | unique per tenant → index `(tenant_id, slug)` |
| title_template | string nullable | override; default derived |
| intro | text nullable | AI-generated |
| picks | json | `[{product_id, role, headline, body}]` — role ∈ overall/budget/premium/preset:{slug} |
| faqs | json nullable | `[{question, answer}]` |
| methodology_note | text nullable | optional AI one-liner appended to static methodology block |
| status | string default 'draft' | draft/published |
| generated_at | timestamp nullable | drives "Updated {Month Year}" badge |
| timestamps | | |

Indexes lead with `tenant_id` (composite). Render resolves `picks[].product_id` against live products (eager-load `brand`, `offers.store`, `featureValues.feature`); deleted/detached/ignored products are silently skipped at render.

### Pick selection (deterministic, at generation time)
- **Best Overall:** highest default-weighted score (same scoring path ProductCompare uses, default weights), `status IS NULL`, not ignored, has image + ai_summary.
- **Best Budget / Best Premium:** top-scored within `price_tier` 1 / 3.
- **Best for {preset}:** top-scored under that preset's weights, for the category's top presets (by sort_order, max 3), skipping products already picked.
- Fill remaining slots (to N=7 max) with next-highest overall scores. Minimum 5 picks or the command aborts with a warning (category not ready).

## 5. AI generation

- New domain method `AiService::generateLandingPageContent(Category $category, array $picks, array $excludeFaqQuestions): array` — **admin_model**, follows `generatePresetContent()` conventions (JSON response schema, maxOutputTokens 8192 — the thinking-model lesson).
- Input to prompt: category context, per-pick product data (name, brand, ai_summary, feature scores, estimated price, tier), role assignments, exclusion list of existing FAQ questions (category buying_guide + all preset seo_content FAQs).
- Output: `intro`, per-pick `headline` + `body`, `faqs` (4-6), `methodology_note`.
- New command `pw2d:generate-landing-page {tenant} {category-slug} [--regenerate] [--dry-run] [--publish]` — mirrors `pw2d:generate-preset-content` UX. `--dry-run` prints picks + prompt without an AI call (the dry-run gate caught the 023 truncation bug; keep it). Without `--publish`, new pages are created as `draft`.
- **Quota note:** admin_model is `gemini-3.1-pro-preview` (~250 RPD shared pool). One call per page — negligible; no model-flip needed.

## 6. Delivery (routes, controller, rendering)

- Route: `Route::get('/best/{slug}', [LandingPageController::class, 'show'])->name('landing.show');` in the existing web group (tenancy middleware applies transparently).
- **Plain controller + Blade — NO Livewire.** The page is static content; skipping the Livewire payload makes it the fastest page type on the site (this is also the linkable-asset audience's first impression). Controller is thin: resolve published page by `(tenant, slug)` or 404, `Cache::remember` the composed view-model (tenant-scoped key, 1h TTL, forget on LandingPage save via observer or `saved` hook).
- Blade view `resources/views/landing/show.blade.php` + partials; Tailwind with `tenant.*` color tokens / `var(--color-primary)` only. Mobile-first. Reuse existing product-card partial styling where sensible but this page renders server-side data, not Livewire state.

## 7. SEO plumbing

- **SeoSchema:** new `SeoSchema::forLandingPage(LandingPage $page, Collection $pickProducts): array` — title/description/canonical + schemas:
  - `ItemList` of picks — **apply the Spec 026 rating gate**: rated products emit nested Product + aggregateRating; rating-less picks emit URL-only ListItems. NO offers/price. Reuse/extract the 026 item-builder rather than duplicating it.
  - `FAQPage` from page FAQs.
  - `BreadcrumbList` (Home → {Category} → Best {Category}).
- **Sitemap:** add published landing pages to `SitemapController` (same pattern as categories/presets; respects the detached-product/category rules).
- **Internal links:**
  - Compare page → landing page: one contextual link in `partials/compare-content.blade.php` ("Read our ranked guide: The Best {Category} in {YEAR}") — only when a published landing page exists.
  - Landing page → compare page (methodology + per-pick "see how it compares"), product pages, preset deep-links.
- robots: indexable, no special handling.

## 8. Admin

Filament `LandingPageResource` under "Content": list (category, status, generated_at), edit form with intro/picks/faqs editors (JSON repeaters like the Preset seo_content editors), publish toggle, "Regenerate" header action dispatching the command logic. Keep minimal — v1 is command-driven.

## 9. Testing (Pest, RefreshDatabase, central-domain context)

1. Route/controller: published page 200; draft 404; unknown slug 404; tenant isolation (tenant A cannot see tenant B's page).
2. Pick selection: roles assigned per rules; ignored/pending/detached products excluded; <5 picks aborts.
3. Render: deleted product in picks JSON is skipped without error; estimated price shown (rounded), never raw scraped price.
4. Schema: ItemList applies the 026 rating gate (rated → nested Product; unrated → URL-only); FAQPage present; no price keys anywhere (regression guard).
5. Sitemap includes published, excludes draft.
6. Command: `--dry-run` makes zero AI calls; generation path with mocked AiService; FAQ exclusion list passed.
7. Compare-page link renders only when a published landing page exists.

## 10. Rollout (post-build, owner-gated via /deploy)

1. Generate + review DRAFTS for the 4 demand centers: pw2d `mechanical-gaming-keyboards`, `productivity-ergonomic-keyboards`; c2d `super-automatic-espresso-machines`, `manual-coffee-grinders`.
2. Owner reviews drafts in Filament → publish → /deploy → request indexing on the 4 URLs.
3. These 4 pages become the link-target for the data-study/outreach pitches (the founder-led track).
4. Watch: cannibalization check at the +2wk status (compare-page queries should NOT migrate wholesale to landing pages; "best x" queries should).

## 11. Task breakdown

- **T1 (builder):** migration, `LandingPage` model (+`BelongsToTenant`), pick-selection action class, `AiService::generateLandingPageContent`, command, controller + route, sitemap addition, SeoSchema method (extract shared 026 item-builder), cache invalidation.
- **T2 (frontend):** Blade view + partials, compare-page contextual link, tenant-token styling, mobile-first.
- **T3 (tester):** §9 suite.
- **T4 (reviewer):** correctness + tenant-scoping + N+1 + schema-policy compliance pass.

---

## Addendum A (2026-08-01) — owner QA findings on first generated page

1. **White-product images invisible on white card background** (G915 TKL White). Fix: neutral `bg-gray-50` image well + padding; `mix-blend-multiply` still works on light gray. Also: the #1 "overall" role badge rendered as an empty pill — fix label/classes.
2. **Renewed/refurbished listings leak into the catalog** — the #1 pick's Amazon URL is an "Amazon Renewed" listing (title cleaned at import hid it; `ai_summary` said "even if renewed"). Scale: 3 offers by raw_title + 32 products by ai_summary. Defense in depth:
   a. Pick selection excludes products with condition-word markers (offer raw_title OR ai_summary).
   b. Server-side import guards (BatchImport, ProductImport, OfferIngestion) reject condition-word raw titles — extension client filters are version-dependent, server must not trust them.
   c. `evaluateProduct()` prompt: renewed/refurbished/open-box → is_ignored (Bouncer rejection criterion).
   d. `pw2d:flag-condition-products {tenant} [--ignore]` audit command for the existing ~35 rows (owner reviews, then --ignore).
3. **Same product picked twice under variant rows** (Keychron Q6 Max Black / "Q6 Max - Black" as hobbyist AND streamer). Fix: normalized-name similarity guard in SelectLandingPagePicks (skip candidate if ≥0.85 similar to an already-picked product). Root cause remains F29 (merge-duplicates) — run on target categories before prod generation.
