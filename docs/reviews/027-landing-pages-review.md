# Review: Spec 027 — "Best X" Landing Pages (T4 pass)
**Date:** 2026-07-18
**Reviewer:** reviewer agent
**Status:** Approved with comments — **SHIP** (no blockers; 4 SHOULD-FIX items recommended before the 4-page rollout)

Scope: all uncommitted Spec 027 changes on top of `0c17162` — migration, `LandingPage` model,
`SelectLandingPagePicks`, `GenerateLandingPage` command, `LandingPageController`,
`AiService::generateLandingPageContent`, `SeoSchema::forLandingPage` + `buildListItem` extraction,
sitemap, routes, `ProductCompare::publishedLandingPage`, landing Blade views, compare-content link.

---

## Critical Issues (BLOCKER)

None.

---

## SHOULD-FIX

### S1. Cache-invalidation hook depends on ambient tenancy instead of the row's own `tenant_id`
`app/Models/LandingPage.php` (`booted()` + `cacheKey()`): the `saved`/`deleted` hooks call
`tenant_cache_key(...)`, which resolves the tenant from the *ambient* tenancy context
(`tenant('id') ?? 'central'`). If a `LandingPage` is ever saved outside an initialized tenant
context (tinker on central, a future queued job, a Filament path where the `TenantSet` bridge
didn't fire), the hook forgets `tcentral:landing:{slug}` and `tcentral:sitemap:xml` — and the real
tenant's 1h view-model cache and 10m sitemap cache go stale silently. The model already carries
`tenant_id`; the key should be derived from it deterministically:

```php
public function cacheKey(): string
{
    return 't' . ($this->tenant_id ?? 'central') . ":landing:{$this->slug}";
}
// and in booted(): Cache::forget('t' . ($page->tenant_id ?? 'central') . ':sitemap:xml');
```

Low effort, removes an entire class of "why is prod stale" bugs. (The docblock even acknowledges
the assumption — better to not need it.)

### S2. Title/H1 pick-count can contradict the rendered list
`LandingPage::title` computes "The {N} Best …" from `count($this->picks)` — the **stored** JSON.
`LandingPageController::buildViewModel()` then filters out deleted/detached/ignored picks. When a
pick is skipped at render, the H1, `<title>`, ItemList `name`, and breadcrumb leaf all claim e.g.
"The 7 Best…" while only 6 cards render (until cache expiry + regeneration). For a page whose
entire pitch is "ranked by data," an off-by-one count is a credibility ding and a schema/content
mismatch. Fix: derive N from the *filtered* picks in the view-model (pass a count into the title,
or compute the title string in `buildViewModel()` from `$picks->count()`), keeping the accessor as
the fallback for admin/list contexts.

### S3. Raw `{!! !!}` on AI-generated content — apply the existing allowlist sanitizer
`resources/views/landing/show.blade.php` (`{!! $page->intro !!}`),
`landing/_pick-card.blade.php` (`{!! $pick['body'] !!}`),
`landing/_faqs.blade.php` (`{!! $faq['answer'] ?? '' !!}`),
`landing/_methodology.blade.php` (`{!! $page->methodology_note !!}`).

Content originates from the admin model via a trusted command and is only editable in admin
contexts, and the compare page already renders preset/buying-guide prose raw — so this is
convention-consistent, not a blocker. But the codebase *already has* a better convention:
`compare-content.blade.php:119` renders AI content through
`strip_tags($html, '<p><br><ul><ol><li><strong><em><h3><h4><a>')` + `javascript:` href stripping.
Apply the same allowlist to `intro`, `body`, and FAQ `answer`. `methodology_note` is prescribed
plain-text by the prompt and validated only as "string" — render it with `{{ }}`, not `{!! !!}`.
This closes the stored-XSS-via-prompt-injection path for ~4 lines of change.

### S4. `SeoSchema::forLandingPage` contract violated by its only caller (minor N+1 at cache build)
The docblock says the page "Must have `category` (with `parent`) eager-loaded," but
`LandingPageController::buildViewModel()` loads category/parent/presets via
`$page->category()->with([...])->first()` — which does **not** set the relation on `$page`. Inside
`forLandingPage()`, `$page->category` lazy-loads category again and `$category->parent` lazy-loads
parent — 2 redundant queries, plus the schema is built from a *different* model instance than the
one the view receives. Only fires once per hour per page (cache build), so impact is small, but
it's a one-liner: `$page->setRelation('category', $category);` before calling `forLandingPage()`.

### S5. Rollout plan gap: no Filament `LandingPageResource`
Spec §8 (admin) is not implemented (`app/Filament/**/LandingPage*` — no files). Spec §10's rollout
("Owner reviews drafts **in Filament** → publish") is currently impossible; publishing works only
via `--regenerate --publish` (which also re-burns an AI call) or raw DB. Either ship the minimal
resource (list + status toggle is enough for v1) or amend the rollout doc to a command-driven
publish step. Not blocking the code under review — but it blocks the spec's own launch sequence.

---

## NIT

1. **Slug derivation** — `GenerateLandingPage` uses `Str::slug($category->name)` instead of
   `$category->slug`. Identical in practice today, but diverges whenever a category slug was
   hand-set, and breaks the intuitive `/compare/{slug}` ↔ `/best/{slug}` pairing. Prefer
   `$category->slug`. (A tenant-scoped unique-constraint SQL error is also the failure mode for a
   name-collision — rare, but an ugly crash vs. a friendly message.)
2. **Prompt role label leaks slugs** — `AiService::generateLandingPageContent` builds
   `'Best for ' . str_replace('preset:', '', $role)`, i.e. "Best for gaming-typing" (hyphenated
   slug). The model may echo the hyphenated form into headlines. Pass the preset display name
   (or `Str::headline()` it) for cleaner prose.
3. **Dead scoring branch in `_pick-card`** — `$product->feature_scores` is never attached by
   `LandingPageController` (no scoring runs on the render path), so the `sortByDesc('score')`
   branch and the "{score}/100" chip can never execute. Either run picks through the scorer at
   cache-build (nice: makes highlights match the compare page) or drop the branch.
4. **All-picks-filtered edge** — if every pick is skipped (category swept), the page still serves
   200 with an empty ranked list and an empty `ItemList.itemListElement`. Consider 404/`noindex`
   below a minimum renderable-pick threshold (e.g. < 3).
5. **`ProductCompare::publishedLandingPage` + `$landingPage->title`** — the compare-content link
   adds 2 queries per compare render (page lookup + lazy category load inside the `title`
   accessor). Both are cheap indexed lookups; if you want them gone, `->with('category')` or
   `setRelation('category', $this->category)` on the computed result.
6. **`SeoSchema::forLandingPage` FAQPage** assumes `question`/`answer` keys exist; a malformed
   hand-edit (future Filament repeater) would throw at cache build. The Blade partial guards —
   the schema builder should too (`array_filter` on both keys).
7. **Migration** — `unique(['tenant_id', 'category_id'])` with nullable `tenant_id`: MySQL permits
   duplicate NULL rows, so central-context duplicates are theoretically possible. Consistent with
   every other tenant table, so noted only for the record.

---

## Checklist verification (what tests can't see)

- **Tenant scoping — PASS.** Migration: string `tenant_id` FK → `tenants.id`, all three indexes
  lead with `tenant_id` (`unique(tenant_id, category_id)`, `unique(tenant_id, slug)`,
  `index(tenant_id, status)`), `down()` present. Model has `BelongsToTenant` + `tenant_id` in
  `$fillable`. Controller adds an **explicit** `where('tenant_id', tenant('id'))` on top of the
  global scope (the safety-net pattern) and 404s when tenancy isn't initialized — same as
  `SitemapController`. Sitemap/compare-link queries ride the global scope under initialized
  tenancy; on the central domain the compare-link query is constrained by `category_id`, and
  categories are tenant-owned, so no cross-tenant leak path exists. Cache keys:
  `t{tenant}:landing:{slug}` — no tenant/slug collisions (but see S1 for the invalidation side).
- **N+1 — PASS** (with S4's 2-query nit at cache build). Render path is fully served from the 1h
  cached view-model; the view-model loads picks in one `whereIn` with
  `brand`, `offers.store`, `featureValues.feature` eager-loaded, covering the pick card's
  brand/price/affiliate/highlight accesses. Preset role-label lookup is a single `mapWithKeys`
  over the eager-loaded `presets`, not per-pick. `publishedLandingPage` is `#[Computed]` (memoized
  per request), not a public property.
- **Schema policy — PASS.** `forLandingPage` emits ItemList + BreadcrumbList + FAQPage only. No
  `offers`, `price`, or `priceCurrency` keys anywhere in the landing schemas; the shared
  `buildListItem()` keeps the aggregateRating-only rule. Canonical is the permanent `/best/{slug}`
  route (no year token), `ogType=article` per spec §3. On-page price is `estimated_price`
  (rounded, `~$` prefixed) — policy-compliant; command table also uses `estimated_price`.
- **Spec 026 regression — PASS.** `buildListItem()` is a verbatim extraction: rated path emits
  nested Product with name/url/offer-image/description/brand/aggregateRating (incl.
  bestRating 5 / worstRating 1), unrated (or `reviews_count = 0`) path emits URL-only ListItem.
  `buildItemListSchema()` now delegates with identical inputs/ordering. Behavior is locked by
  `SeoSchemaTest` §1235–1381 (URL-only, full-nested, mixed-positions, zero-review-count cases),
  all passing in the 433-test run — I found no structural or key-order deviation from the 0c17162
  behavior.
- **AI boundary — PASS.** Only `AiService::generateLandingPageContent` touches
  `$this->gemini->generate`, with `maxOutputTokens: 8192` (the 023 truncation lesson is cited
  in-code), admin_model, 120s timeout. Response validation is thorough: fence-stripping,
  `is_array` decode check, key presence, exact pick count, per-index product_id order match,
  string-typed faqs, string methodology_note. Command wraps the call in try/catch with logging.
- **Command — PASS.** `tenancy()->initialize` → `try { process() } finally { tenancy()->end() }`.
  `--dry-run` is read-only (pick selection + table + prompt summary, returns before any AI call or
  save). Missing tenant / non-leaf category / <5 picks all produce clean FAILURE exits with
  messages. `--regenerate` preserves slug and doesn't silently unpublish; `--publish` explicit.
- **Blade — PASS** (given S3). No hardcoded brand colors beyond the established sitewide
  Amazon-orange CTA + `rgba(255,153,0,…)` hover-shadow convention (identical classes exist in
  `product-compare.blade.php` and `similar-products.blade.php`); everything else uses
  `tenant-primary`/`tenant-secondary`/`tenant-text`. No Alpine at all (native
  `<details>/<summary>` FAQ — good call), so no bare `const`/`let` risk. Images: `width`/`height`
  set (CLS), `loading="eager" fetchpriority="high"` on pick #1, `loading="lazy"` on the rest;
  `aria-label` on the price CTA; breadcrumb/landmark aria in place. Mobile-first stacking.
- **Caching — PASS** (given S1/S2). 1h view-model TTL per spec §6; sitemap key
  (`sitemap:xml`, 600s) forgotten on save/delete; slug edits can't serve stale content (old-slug
  DB lookup 404s before cache read).
- **Edge cases — PASS.** Deleted picks vanish from the `whereIn`; detached/ignored/pending picks
  explicitly excluded; `estimated_price === null` hides the price block and the CTA guards on
  `affiliate_url`; empty `faqs` skips both the section and the FAQPage schema; year is computed at
  render (`date('Y')` in the lazy `title` accessor), so January rollover updates title/H1 even
  inside a cached view-model; categories with no presets simply yield no `preset:*` roles
  (weightless presets skipped via the `empty($weightMap)` guard).
- **Pick selection — PASS.** Eligibility gate matches spec §4 (processed, not ignored, attached,
  ai_summary + resolvable image via the site's standard `image_url` chain). "Best Overall" uses
  the exact `ProductScoringService::scoreAllProducts` path with all-50 weights — verified
  identical to `ProductCompare`'s defaults (`amazonRatingWeight = 50`, `priceWeight = 50`,
  per-feature 50). Budget/premium via `price_tier` 1/3; presets by `sort_order`, max 3, weighted
  raw-value sum, already-picked skipped; fill to 7; abort under 5.

## Praise

- The deterministic-picks / AI-prose-only split (spec §2's "critical design rule") is genuinely
  enforced in code — `SelectLandingPagePicks` is pure data, and the AiService validator *rejects*
  any AI attempt to reorder or swap product_ids. That's the honest-E-E-A-T claim made structural.
- `buildListItem()` extraction is exactly how shared-schema reuse should look: single source of
  truth for the 026 rating gate, zero behavioral drift, locked by existing tests.
- The no-Livewire decision executed cleanly — native `<details>` FAQs instead of reflexive Alpine,
  correct LCP hints on the #1 pick, one cached view-model per page. This will be the fastest page
  type on the site, as intended.
- AiService response validation is the most rigorous in the file — per-index ID matching against
  the deterministic pick list is a great guard against silent model drift.
- Regeneration semantics (`--publish` explicit, live pages never silently unpublished, slug
  preserved) show real care for the "permanent URL accrues links" strategy.

## Verdict

**SHIP.** No blockers. S1–S4 are each ≤10-line fixes and should land before the 4-page rollout;
S5 (Filament resource or rollout-doc amendment) must be resolved before spec §10's owner-review
step can happen as written.
