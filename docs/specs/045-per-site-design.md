# Spec 045 — A design of its own for each site (coffee first)

**Status:** DRAFT 2026-10-05. No direction chosen yet — next step is visual directions for the owner to pick from.

## Goal

coffee2decide should look like a coffee site that a buyer of a $1–8k machine can trust, not a generic AI-shopping
template. Owner, 2026-10-05: the design feels sad and generic and does not show the products; one person can run
2–3 sites like this, not many — so a separate design for each site is affordable, and the "one template, many
tenants" assumption is retired. It also serves Spec 044: brands and reporters who follow an email land here.

## What a visitor sees today (desktop screenshots, 2026-10-05)

- **Per-site customisation is 8 values:** 3 colours, logo, `brand_name`, `name`, hero headline and sub-headline
  (every `tenant('…')` call in `resources/views`). Everything else is the same on both sites.
- **Home:** above the fold — a headline, "✦ The Smart Way to Shop", an "AI SEARCH" box. No machine, no photo, no
  number. "How it works" is three emoji cards in pastel blue / purple / yellow about the tool (Natural Input,
  Priority Sliders, Dynamic Ranking), not about coffee.
- **One font (Inter) everywhere; colour pulls five ways:** caramel brand, bright amber store buttons, lime slider
  knobs, green score bars, an indigo "The Verdict" box, a purple "Best Premium" badge. Reads as a dashboard.
- **Tool words, not coffee words:** "Personal Match Score 80%", "Find My Gear", "objectively rated at 80.3%
  compatibility", "Technical Specifications" above what are scores.
- **Price as "$$$"** on compare cards and the product page — every prosumer machine is "$$$" — while the guide on the
  same site shows "~$8,500" (`estimated_price`, the format memory `seo-schema-policy` allows).
- **The guide page (`/best/…`) is the most convincing page:** editorial copy, real prices, large photos. Build from it.
- **Compare page:** the customise drawer auto-opens on desktop over a third of the page and cuts off the title.
- *Not a defect:* the large blank areas in tall screenshots come from `.hero { min-height: 65vh }`.

## Decision proposed

Two or three sites at most, each with its own look. **Shared:** data, scoring, Livewire logic, URLs, SEO markup.
**Per site:** design tokens and a small set of presentational Blade partials.

## Architecture — a theme layer

1. **Tokens.** Extend tenant `data` with display and body fonts, background, surface, ink, accent, store-button colour
   and radius. The layout already emits colours as CSS variables (`components/layouts/app.blade.php:121`); add the
   rest, and map them in `tailwind.config.js` (`font-display`, `bg-surface`, `text-ink`, …). Replace the hard-coded
   amber / lime / indigo / purple in shared views with tokens (architect rule: never hard-code brand colours).
2. **View overrides.** Prepend `resources/views/themes/{tenant_id}/` to the view finder per request, after tenancy
   initialises. A file at the same relative path overrides the shared view; absent files fall back. No job, command,
   mail or notification renders Blade today (grep 2026-10-05), so the finder's per-process cache cannot leak one
   tenant's view into another's; if a job ever renders views, flush the finder on tenant switch.
3. **Override only presentational partials; logic never forks.** First extract the compare-page product card, guide
   pick card, header, footer and home sections into components, so a theme replaces ~50 lines rather than copying the
   743-line `livewire/product-compare.blade.php`.
4. Spec 044's per-tenant About page becomes `themes/coffee2decide/pages/about.blade.php` — same mechanism, no
   separate `@includeFirst`.

## Coffee direction — chosen from mockups, before any code

2–3 directions on one page (home hero, guide pick card, compare card) for the owner to choose from. Common brief:
warm and product-first; real numbers up front ("188 espresso machines, 3 stores, updated October 2026"); one
store-button colour; a display face for headings; estimated prices instead of "$$$"; coffee words instead of tool
words. Interface copy is content: style contract, owner review.

## Rollout — protect the climb

c2d page-one impressions are rising (151 → 284 a day, 2026-10-04 check). In June, three page changes shipped on one
day were followed by a ~90% impression drop on the affected pages for weeks.

- Keep every URL, h1/h2, structured-data block, internal link and body text. Change the visual layer only.
- Stages a week apart, least search traffic first: (1) tokens + header/footer + home + About; (2) guide pages;
  (3) compare and product pages (~75% of impressions) last. Read each stage in the next `/seo-status`.
- At most two font families, preloaded, `font-display: swap`. Measure LCP on one product page before and after.
- Alpine changes are checked in a real browser console (lesson 2026-06-19); the test suite does not run JS.

## Multi-tenant impact

pw2d keeps today's look until it gets its own direction; the shared views remain the fallback for any tenant.

## Open questions (owner)

1. Retire the many-sites goal — two or three sites at most?
2. Coffee only first, pw2d after (recommended), or both directions now?
3. Keep the current logo and brown, or open to changing them?
