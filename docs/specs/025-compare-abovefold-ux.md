# Spec 025 — Compare-Page Above-Fold UX: Surface Products + Customize Discoverability

**Status:** Draft (awaiting build)
**Author:** Lead Architect
**Date:** 2026-06-19
**Closes:** two UX issues surfaced post-Spec-023 deploy (owner screenshot + report)
**Type:** Frontend (Blade + Alpine.js) — no backend/schema changes

---

## 1. Why

Spec 023 added valuable SEO content depth, but it exposed/created two UX problems on the compare
page — the product grid is the page's core feature and it's now buried:

**Issue 1 — products pushed below the fold.** `product-compare.blade.php` stacks the entire header
block (image + H1 + `seo_description` + long intro + "How to Decide" tabs + "How We Rank" methodology)
ABOVE the product grid (grid starts ~line 392). On desktop that's a full screen of prose before any
product; worse on mobile. Two consequences: (a) users don't see products without scrolling; (b) the
signature dynamic re-ranking is invisible — see Issue 1b.

**Issue 1b — re-ranking is invisible while customizing.** The Customize drawer
(`comparison-header.blade.php`) opens with a full-screen backdrop that **blurs + dims the whole page**
(line 59: `fixed inset-0 bg-gray-900/40 backdrop-blur-[2px]`). So when a user drags a priority slider,
the product grid that's supposed to visibly re-rank is hidden behind a blur. The site's main "magic"
(instant re-rank) gives no visible feedback.

**Issue 2 — Customize is undiscoverable.** The drawer auto-opens once, gated on
`localStorage['app_customize_seen']` (`comparison-header.blade.php:4`) — i.e. **once ever per browser**.
After that, new users never learn that re-ranking — the core feature — exists.

## 2. Decisions (owner-approved)

- **Issue 1:** Products up, deep content below. Keep H1 + short hook above the grid; move intro +
  tabs + methodology below it.
- **Issue 2:** Auto-open the Customize drawer **once per session** (not once ever). Default granularity
  = session-global, implemented behind a single clearly-marked key constant so escalating to
  per-category (or per-page) is a one-line change. Rationale for the conservative default: auto-popping
  a modal on every preset toggle nags users who are already mid-customization. The existing
  `posthog.capture('customize_modal_opened')` event lets engagement data drive any escalation.

## 3. Changes

### 3.1 Issue 1 — reorder (`resources/views/livewire/product-compare.blade.php`)

**Keep above the grid** (the compact header, ~lines 55-90): category image, H1, and the
`$activePreset->seo_description` short hook (line 88-90). This keeps the keyword-rich H1 + a 1-2
sentence intent line above the fold (good for both UX and SEO).

**Move below the grid:** the intro block (lines 92-101), the "How to Decide" tabs block (lines
103-~230), and the "How We Rank" methodology block (lines ~234-250). The FAQ partial is already
included below the grid — the relocated content should sit with it (intro + tabs + methodology, then
FAQs; or whatever reads best — builder's discretion, but all deep content below the grid).

**Implementation:** extract the moved blocks verbatim into a new partial
`resources/views/livewire/partials/compare-content.blade.php` and `@include` it after the product grid
(near the existing FAQ `@include`). Preserve every Alpine binding (`x-data="{ expanded, activeTab }"`),
the `$sections`/`$defaultTab` PHP, the `{!! $introContent !!}` (preset-first) logic, and all Tailwind
classes — this is a relocation, not a redesign. Pass `$category` and `$activePreset` to the partial.

Do NOT change the schema/`SeoSchema` — the content's DOM position doesn't affect the JSON-LD, and
below-fold prose ranks fine.

### 3.2 Issue 1b — make re-ranking observable (`comparison-header.blade.php`)

Soften the backdrop (line 55-61) so the product grid stays legible and visibly re-ranks while the
drawer is open:
- **Remove `backdrop-blur-[2px]`.**
- Lighten the dim substantially (e.g. `bg-gray-900/40` → `bg-gray-900/10`, or remove the tint and keep
  only a click-catcher). Keep the click-to-close behaviour (`@click="showPreferences = false"`).

Goal: with the 400px right drawer open, a desktop user sees the grid on the left re-rank live as they
drag. On mobile the drawer is wider (`max-w-[82vw]`) but the top product row should still be partially
visible and re-rank — combined with Issue 1's reorder, the first products sit right under the header.
Builder: verify the drawer doesn't `overflow-hidden`-clip the body scroll such that the grid can't be
seen; do not lock background scroll while the drawer is open (or if it must, ensure the top of the grid
is in view first).

### 3.3 Issue 2 — auto-open once per session (`comparison-header.blade.php:3-11`)

Change the `x-init` gate:
- Replace `localStorage` with `sessionStorage` on the seen-check + set (lines 4-5) so auto-open fires
  once per browser **session** instead of once ever.
- Define the storage key as a single clearly-named constant at the top of the `x-init` (e.g.
  `const AUTO_OPEN_KEY = 'app_customize_autoopen'`) with a comment documenting the escalation:
  *"append `:{{ $category->slug }}` to make it per-category, or drop the guard for every page."* Wire
  the category slug into the component so per-category is a trivial switch (pass `:categorySlug` prop or
  reuse `$categoryName`).
- **Respect the existing `$autoOpen` prop** (`product-compare.blade.php:53` passes
  `:autoOpen="!$selectedProductSlug"`): only auto-open when `@js($autoOpen)` is true, so the Customize
  drawer never pops over an already-open product detail view. If `$autoOpen` is currently unused in the
  component, wire it in here.
- Keep the post-paint delay (currently 3000ms) but tighten to ~1500ms so it feels responsive yet lands
  after first paint. Keep the `app-open-sidebar` event → `showPreferences = true` path.
- Keep the teaser (ping/bounce) branch for same-session subsequent pages (the `else` branch), and the
  `posthog` event on manual open. Add a `posthog.capture('customize_modal_autoopened', {...})` when the
  auto-open fires, so we can measure auto-open vs manual engagement.

## 4. Tests (`@tester`)

Most of this is Alpine/JS behaviour (sessionStorage, backdrop, auto-open) verified manually — but add
the regression guards that PHPUnit/Livewire CAN assert:
- **DOM order (Issue 1):** an HTTP/Livewire render of a compare page asserts the product grid markup
  appears BEFORE the "How We Rank" / methodology text in the rendered HTML (guards against the content
  drifting back above the grid). Pick a stable marker string from each region.
- The deep content (intro, How-to-Decide, methodology, FAQs) is still PRESENT in the rendered output
  (moved, not dropped) — for both a preset and a no-preset render.
- No regression: existing `Compare` suite stays green (`php artisan test --filter='Compare|Seo'`).
- Manual QA checklist (document in the PR, not automated): (a) fresh session → land on compare → drawer
  auto-opens after ~1.5s; (b) drag a slider → top products visibly re-rank (no blur); (c) close, reload
  same page → no auto-open, teaser shows; (d) new session → auto-opens again; (e) open a product detail
  → drawer does NOT auto-open over it.

## 5. Rollout

1. Local `php artisan test --filter='Compare|Seo'` green.
2. Deploy via `/deploy` (frontend-only: pull + `npm run build` + `optimize:clear` + fpm restart; no
   migration).
3. Manual QA on prod per the checklist above, especially the re-rank-visible-while-dragging behaviour
   on both desktop and mobile.

## 6. Risks / notes

- **Don't regress SEO:** the content moves in the DOM but stays on the page; the H1 + hook stay above
  the fold. No JSON-LD change. Confirm the 3-block schema count is unchanged after deploy.
- **Mobile drawer width:** at `max-w-[82vw]` the drawer covers most of a phone; the reorder (products
  directly under the header) is what makes the top row visible above the drawer. If QA shows the grid
  is still hidden on mobile, consider a shorter/bottom-sheet variant — but try the simple backdrop +
  reorder fix first; do not over-engineer a responsive drawer rewrite in this spec.
- **Scope discipline:** this spec is the inline-sliders option's lighter cousin — it does NOT move
  sliders inline (that was the rejected option). Keep to reorder + backdrop + session auto-open.
