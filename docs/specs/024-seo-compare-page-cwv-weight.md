# Spec 024 — Compare-Page Core Web Vitals: Initial-Render Weight Cut (F31)

**Status:** Approved — building (2026-06-26)
**Author:** Lead Architect
**Date:** 2026-06-19 (refreshed 2026-06-26)
**Closes:** F31
**Sequenced as:** fast-follow to Spec 023 (both push pos-10 preset pages toward the click-earning top 5;
023 = content/intent match, 024 = page-experience ranking signal)

> **2026-06-26 refresh — read before building:**
> - **Rationale reinforced:** the Jun 26 engagement check found compare pages get ~3-6 real pageviews/week
>   — on-site engagement can't be measured until clicks exist, and clicks need the pos-10→top-5 climb.
>   CWV is a *traffic-driving* (ranking) lever whose payoff does NOT require existing traffic, unlike
>   engagement work. That makes it the correct next move while 023's content settles.
> - **Line numbers in this spec are STALE.** Spec 025 (deployed `97cac8b`) reordered the compare page:
>   the product grid now sits directly under the H1/hook (genuinely above the fold — good for this spec),
>   and the deep content (intro/tabs/methodology) moved into `partials/compare-content.blade.php` BELOW
>   the grid. The builder/frontend MUST read the CURRENT blade, not trust any line ref here.
> - **Gotcha — `displayLimit` normalization:** `ProductCompare::mount()` forces `displayLimit` to
>   `max(12, …)` rounded to a multiple of 12. So you CANNOT just lower `displayLimit` to 6 — introduce a
>   SEPARATE initial render-window (e.g. `renderLimit = 6`) that caps how many of the scored products are
>   emitted in the initial server HTML, with a wire-driven reveal that raises it; leave `displayLimit` and
>   the "Load more" semantics intact.
> - **Real HTML-weight cut, not hide-with-CSS:** the deferred cards must be ABSENT from the initial server
>   response and rendered on a later Livewire round-trip (x-intersect → wire reveal). Rendering 12 and
>   `x-show`-hiding 6 does NOT reduce HTML transfer / LCP — it must be a server-side render-count cut.

---

## 1. Why

Google uses INP/LCP (Core Web Vitals) as ranking signals. The compare page is the site's proven SEO
surface (Spec 023 data) — every CWV gain compounds across the exact pages already ranking pos ~10. F31
flagged the initial render at ~167 KB.

**Current state (verified in code):** `ProductCompare::visibleProducts()` already fetches full data for
only `displayLimit = 12` products (not all 200+) — good. The "Load more" path increments `displayLimit`
by 12. Product images already use `loading="lazy"` (blade ~line 416). So the weight is **12 fully-rendered
product cards inline in the initial HTML**, not an unbounded list. The lever is reducing the *initial*
server-rendered card count without changing UX.

## 2. Scope

**In scope**
- Render the first **6** cards server-side (above-fold); defer cards 7–12 to a lazy-loaded chunk that
  hydrates on scroll/idle, so initial HTML roughly halves while the visible result set is unchanged.
- Measure before/after: HTML bytes, LCP element, INP on slider interaction.
- Verify no regression to scoring, H2H Arena mode, pinned-product staging, or the existing "Load more".

**Out of scope**
- Any change to scoring logic or `scoredProducts`.
- Content / schema (that's Spec 023).
- Image pipeline (already WebP + lazy).

## 3. Design (`@frontend` + `@builder`)

Two viable mechanisms — builder/frontend pick based on what composes cleanly with the existing
Livewire computed properties; do NOT regress the score-sync in `visibleProducts()`:

**Option A — split computed + `wire:init` (preferred):**
- Add `initialLimit = 6`. `visibleProducts()` renders the first `initialLimit`.
- A deferred section (below the 6th card) uses Livewire lazy loading (`@lazy` / `wire:init` calling a
  method that bumps the rendered window to 12) triggered by an Alpine `x-intersect` sentinel just below
  the fold. Net: 6 cards in initial HTML, next 6 stream in as the user scrolls — before they reach the
  existing "Load more" button.
- Must preserve: H2H Arena mode (renders pinned set — exempt from the 6-cap), pinned-staging order,
  and `match_score` / `feature_scores` attachment.

**Option B — `@defer`/placeholder skeletons:** render 6 real cards + 6 lightweight skeleton placeholders
that swap to real cards on intersect. Slightly more markup, smoother CLS. Builder's call.

Either way: **no Cumulative Layout Shift** — deferred slots must reserve height (skeleton or min-height)
so inserting real cards doesn't shift the page (CLS is itself a CWV).

## 4. Tests (`@tester`)

- `ProductCompare` test: initial render contains 6 product cards; after the lazy trigger, 12; "Load more"
  still increments by 12 beyond that.
- H2H Arena mode still renders the full pinned set (not capped at 6).
- Pinned-staging order preserved; `match_score` present on every rendered card.
- A full HTTP render test asserting the initial document is meaningfully smaller (assert card count, not
  a brittle byte threshold).
- `RefreshDatabase`, factories with explicit product `slug`.

## 5. Verification / rollout

1. Local: `php artisan test --filter=Compare` green.
2. Deploy via `/deploy`.
3. Measure on prod: PageSpeed Insights / CrUX on `/compare/mechanical-gaming-keyboards?preset=streamer`
   (the highest-traffic page) before vs after. Target: initial HTML transfer down ~40-50%, LCP improved,
   no CLS introduced.
4. Confirm the page still passes the Spec 022/023 schema checks (3 JSON-LD blocks, preset FAQs).

## 6. Risks

- **CLS regression** is the main danger of lazy-inserting cards — height reservation is mandatory.
- **Don't double-count with "Load more":** the existing `displayLimit` UX must still work; the lazy chunk
  is an *initial-render* optimization layered beneath it, not a replacement.
- Keep it simple. If `wire:init` lazy loading proves fiddly with the score-sync, Option B (skeletons) is
  the lower-risk fallback. Do not over-engineer an intersection framework.
