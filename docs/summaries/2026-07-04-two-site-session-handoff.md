# Session Handoff — 2026-06-07 → 2026-07-04 (pw2d on-page push complete + coffee2decide launch)

**Audience:** the next Claude session picking up SEO/product work on pw2d.com + coffee2decide.com
**Owner:** Michael (gurovm@gmail.com)
**Date written:** 2026-07-04

---

## TL;DR

Everything code-shaped that on-page SEO can offer is now SHIPPED on pw2d (Specs 023 preset content,
024 CWV, 025 above-fold UX), and coffee2decide went **from dormant to fully launched** (restructured,
~360 scored products, full content stack, GSC connected, clean sitemap). pw2d's metrics grew strongly
(276 → 1,849 impressions/28d; 0 → 6 clicks) but the 3 target preset queries sit in post-deploy
re-evaluation churn around pos 9–11. **Three independent signals now point at the same constraint —
domain authority + per-page content depth**: (1) the pos-10 plateau, (2) impression whiplash after
changes, (3) GSC "Crawled – currently not indexed" rationing 128 pw2d product pages that are
technically perfect.

**THE decision is due ~Jul 10–12:** run `/seo-status pw2d`. If target queries settled above the
plateau → on-page validated, start engagement measurement. If still ~10 → **pivot to off-page/authority
(not code)**; optional parallel code track = Spec-026 candidate (product-page content depth).
**Don't write new on-page specs before that verdict.**

Two session skills exist and encode all of this: **`/seo-status`** and **`/audit-scrape`**. Use them.

---

## The two sites at a glance

| | pw2d.com | coffee2decide.com |
|---|---|---|
| Products (live) | ~660 | ~360 (espresso 190 + slow-coffee 169) |
| Categories | 5 leaves (mics, 2×keyboards, headsets, lavalier) | 6 leaves under 2 hubs (2 espresso + 4 new slow-coffee) |
| Preset pages w/ 023 content | 20 | 24 |
| GSC | connected, mature data | connected 2026-07-03, data starts ~Jul 6-7 |
| GA4 | working | **PENDING: owner must grant SA Viewer** (PERMISSION_DENIED on smoke test) |
| Status | waiting on Jul-10 verdict | waiting on first data ~Jul 25 |

---

## pw2d metric trajectory (28d GSC, the table to extend)

| Metric | 06-05 base | 06-13 | 06-19 | 06-26 | **07-03** |
|---|---|---|---|---|---|
| Pages w/ impressions | 97 | 133 | 158 | 191 | **209** |
| Impressions | 276 | 516 | 928 | 1,553 | **1,849** |
| Clicks | 0 | 1 | 1 | 4 | **6** |
| Avg position | 17.4 | 15.2 | 13.7 | 17.1* | 16.4* |

\* composition artifact — new long-tail pages enter at pos 30–50 and drag the impression-weighted
average; judge the TARGET queries, never the aggregate.

**Target preset queries (the thesis):** "best mechanical keyboard for streamers" 10.4 → 10.0 → **9.1**
but impressions collapsed 129→27 right after the Jun-19 deploys (023+024+025 same day) — classic
re-evaluation churn ("Google dance", 1–3 weeks). remote-worker ~10.6; minimalist fell out of top
queries. TOO NOISY at the 07-03 check — hence the Jul-10 re-check.

**GSC indexing report (checked 07-04, all explained, NO action needed):**
- 404 ×232: our deliberate slug-rename cleanup (see below) — correct terminal state, ages out.
- "Alternate page with proper canonical" ×101: `?limit=`/param variants consolidating — by design.
- **"Crawled – currently not indexed" ×128: index rationing**, not a bug (verified: pages live, in
  sitemap, fetch OK, canonical accepted; even DJI Mic 2 / Apple Magic Keyboard rationed). Indexed
  product pages DO rank ("redragon k580 vata review" pos ~5–9), so the bar is clearable.
  Optional micro-move: hand-"Request Indexing" ~10 flagship products.

---

## What shipped this session (chronological)

1. **Spec 022 deploy gap fixed** (Jun 7) — handoff claimed 022 deployed; prod was 1 commit behind.
   Lesson: merged-to-main ≠ serving traffic.
2. **Spec 023 — preset-aware content depth** (Jun 19): `presets.seo_content` JSON,
   `AiService::generatePresetContent`, `pw2d:generate-preset-content` command, preset intro +
   preset-first-deduped FAQs render, FAQPage merge in SeoSchema, Filament editors. Multi-agent build
   (builder→frontend→tester→reviewer, 36 tests, SHIP/0 blockers). **Hotfix:** maxOutputTokens 2000→8192
   (thinking models eat the budget → MAX_TOKENS truncation; caught by dry-run gate).
3. **Spec 025 — above-fold UX** (Jun 19): products grid moved above deep content (new
   `partials/compare-content.blade.php`); Customize-drawer backdrop un-blurred (re-rank now visible);
   auto-open per-category-per-session (`sessionStorage`, `initAutoOpen()` x-data method). **Two prod
   bugs found & fixed:** (a) `$categoryName` used in x-init attr before its `@php` (render-fatal,
   caught by tester's HTTP tests); (b) top-level `const` in x-init + STALE published livewire.js
   (old bundled Alpine wraps x-init in `return(...)`) — fix: x-data method + `vendor:publish
   --tag=livewire:assets --force`, now a permanent deploy step. `preset_applied` PostHog event added.
4. **Spec 024 — CWV render cut** (Jun 26–27): `renderLimit=6` + `revealMore()` (x-intersect sentinel,
   CLS-safe skeletons) + **`schemaProducts()`** — the review-caught BLOCKER: schema/meta must read
   displayLimit (ItemList stays 12) while cards render 6. Results: HTML 185→152KB (18%), PSI mobile
   Perf 81 / **CLS 0.015** / SEO 100. LCP 3.1s unchanged (gated by render-blocking + LCP image, NOT
   cards) → parked as **F35**, gated on the Jul-10 verdict.
5. **Skills:** `.claude/commands/seo-status.md` + `audit-scrape.md`.
6. **AI models upgraded** (Jul 4): site=`gemini-3.5-flash`, admin=`gemini-3.1-pro-preview`,
   image=`gemini-3-pro-image` (verified against live models endpoint). **CRITICAL OPS RULES:**
   (a) Tier-1 gives preview models ~250 req/day and the 3.x previews SHARE the pool — on bulk-scrape
   days (>200 products) flip admin to `gemini-2.5-pro` (1K RPD) first, revert after; (b) after ANY
   .env/model change: `php artisan config:clear && php artisan queue:restart` — workers cache config
   in memory; (c) 429-killed jobs mark products `status='failed'` (no failed_jobs row!) — requeue via
   tinker: reset to pending_ai + `ProcessPendingProduct::dispatch((int)$id, (int)$categoryId)`.
7. **coffee2decide launched** (Jul 3–4):
   - Restructured: `slow-coffee-accessories` → parent hub + 4 leaves (manual-coffee-grinders,
     gooseneck-kettles, pour-over-drippers-brewers, cold-brew-makers), each with own features/presets/tiers.
   - Scraped ~180 slow-coffee products through the quota storm; Bouncer kill rates healthy (25–44%,
     all kills verified correct — white-label junk).
   - Catalog surgery: 24 wrong-category detaches (incl. **11 electric grinders = seed stock for a
     future "Electric Burr Grinders" leaf**), 7 variant merges (Cosori, Chemex ×4, Kalita) with
     AiMatchingDecision cache entries so future scrapes map to canonicals.
   - Content: 021 guides (4 leaves) + 023 preset content (24 presets), 0 errors.
   - Coverage audit done: canon present (Comandante C40, 1Zpresso K-Ultra, Toddy, Hario V60/Switch,
     Chemex, Kalita 185, Fellow Stagg EKG Pro). Known gaps NOT on Amazon (owner checked): Fellow
     Stagg EKG standard, 1Zpresso JX-Pro/J-Max, Origami, Kalita 155, Hario Mizudashi → future
     **Whole Latte Love per-offer ingestion** candidates.
   - GSC connected (smoke-tested OK), sitemap submitted, 396 clean URLs.
8. **Slug/name hygiene** (Jul 4): AI sometimes kept full Amazon marketing titles as "clean" names →
   190+ char slugs. Data-fixed 178 c2d + 272 pw2d rows (pre-crawl window for c2d; pw2d's 7 live ones
   all had zero impressions — safe without redirects). **Pipeline fix deployed** (`capProductName()`
   in ProcessPendingProduct + NAME RULE in evaluateProduct prompt + `Str::words(title,8)` stub slugs
   in all 3 import endpoints). Also fixed: **sitemap leaked detached (category_id=null) products** —
   `whereNotNull('category_id')` added to SitemapController.
9. **Division of labor agreed (owner decision):** pipeline automates per-request + per-product loops
   (search, Bouncer, ingestion matching); **Claude does per-category + per-audit judgment** (SEO
   content for high-stakes surfaces, post-scrape audits, dedup sweeps). Content drafting BY Claude
   (vs API commands) deferred until practiced 2–3 times — don't skillify prematurely.

Prod HEAD at session end: `6eee1a7`. All tests green (397 passed / 0 failed).

---

## THE decision tree (next session's likely first move)

**~Jul 10–12 → `/seo-status pw2d`:**
1. Target queries (streamer / remote-worker / minimalist) settled **above** the plateau (< ~8) →
   on-page validated. Close F35. Start engagement measurement (PostHog — readable once clicks exist).
2. Still **~10 after 3+ weeks** post-023 → on-page is NOT the lever. **Pivot to off-page/authority**:
   backlinks, content marketing, `/best-X-2026` landing pages (the one code-shaped authority play).
   Optional parallel code track: **Spec-026 candidate — product-page content depth** (expand the
   2-sentence ai_summary into structured review: pros/cons, who-for, vs-alternatives; ~660 products,
   ~$5–8 AI, plan around the 250/day quota or flip to 2.5-pro). Targets both long-tail rankings and
   the 128-page index rationing.
3. Impressions still whip-sawing → churn not settled; wait one more week. Don't spec.

**~Jul 25 → `/seo-status coffee2decide`:** first read. Its early `gsc_top_query` data doubles as the
category-selection signal for slow-coffee leaves #5–6 (French press, AeroPress, moka, scales, or the
electric-grinders seed). Baseline to beat: zero (fresh site).

---

## Standing policies (unchanged, load-bearing)

- **Amazon Associates deferral + no price in schema** — memories `amazon-associates-strategy` +
  `seo-schema-policy`. Never propose price/priceCurrency, sync-offer-prices scheduling, or PA-API.
- **Deploy strictly via `/deploy`** — never auto-deploy. NOTE: deploy.md now includes the Livewire
  asset republish (step 5). **Verify owner added the queue:restart step (9b)** — suggested, not
  confirmed. Until then, run `php artisan queue:restart` manually after deploys that touch jobs.
- `.claude/commands/*.md` edits are guard-blocked for Claude — owner applies, or explicit permission.
- Tinker mass-updates: ALWAYS scope precisely (live-only filters!) — this session a 7-row intent
  became a 272-row write (harmless, but see lesson).

## Open items

- [ ] **GA4 for coffee2decide**: owner grants `pw2d-seo-reader@pw2d-407419.iam.gserviceaccount.com`
      Viewer on the GA4 property (smoke test = PERMISSION_DENIED). Also per-tenant Settings needed:
      `ga_measurement_id` + `posthog_key`/`posthog_host` (coffee2decide has NO settings rows → no
      frontend analytics fire at all until set; recommended separate PostHog project).
- [ ] Jul-10 verdict → path choice (see tree).
- [ ] F35 (LCP pass: render-blocking ~1.1s, LCP image 155KB, unused JS 187KB) — parked, gated on verdict.
- [ ] F33 rsi-sufferer preset still ~pos 44 — re-check at next status.
- [ ] pw2d 404 count (232) should decline over weeks; if GSC sitemap "processing error" persists → resubmit.
- [ ] WLL ingestion for the 6 gap flagships (not on Amazon) — only if kettle/grinder pages start ranking.
- [ ] Engagement measurement — BLOCKED until clicks exist (compare pages get ~3-6 visits/wk; PostHog
      pipeline ready: local .env `POSTHOG_PERSONAL_API_KEY`, project 133580, HogQL query pattern in history).
- [ ] Spec 024 footnote: initial product links halved (6 vs 12) — watch internal-linking effect, don't revert.
- [ ] Q/L backlog + P3 etc. in todo.md untouched.

## Hard lessons added this session (full versions in docs/lessons.md + this doc §7)

1. Thinking models count reasoning against maxOutputTokens — never budget tight (2000 died; 8192 works).
2. Tier-1 Gemini preview models: ~250 RPD, SHARED across 3.x previews. Empirically verify "fresh
   bucket" theories via logs before relying on them.
3. Queue workers cache config in memory — model/env changes need `queue:restart` (and jobs killed by
   429 storms end as product `status='failed'`, invisible to failed_jobs).
4. No bare `const`/`let` in Alpine x-init + republish Livewire assets after composer bumps.
5. PHPUnit/Livewire tests never execute Alpine JS — browser-console checks are part of frontend QA.
6. Schema must be decoupled from render windows (ItemList vs renderLimit — the B24-1 blocker).
7. "Crawled – currently not indexed" on technically-perfect pages = authority/quality rationing, not a bug.
8. Scope tinker writes to exactly the intended rows; state intended vs actual row counts when reporting.
9. GSC aggregate position is impression-weighted — long-tail growth *worsens* it while nothing declines.
10. Pre-index windows are the free-fix windows (slugs, sitemap composition) — act inside them.

## Quick reference

```bash
# The two skills (encode all procedures + caveats)
/seo-status pw2d          # weekly checkpoint vs baselines
/seo-status coffee2decide # after ~Jul 25
/audit-scrape coffee2decide {category-slug}   # after any scrape

# Prod
ssh root@209.97.153.234 'cd /var/www/pw2d && php artisan pw2d:seo:status'

# Models (.env on prod): AGENT_SITE_MODEL=gemini-3.5-flash,
# AGENT_ADMIN_MODEL=gemini-3.1-pro-preview, AGENT_IMAGE_MODEL=gemini-3-pro-image
# Bulk scrape day: flip admin to gemini-2.5-pro; config:clear + queue:restart; revert after.

# Content generation (per tenant / category / preset, all support --dry-run)
php artisan pw2d:generate-compare-content {tenant} [--category=] [--regenerate]
php artisan pw2d:generate-preset-content {tenant} [--category=] [--preset=]

# Local suite
php artisan test   # 397 passed / 9 skipped as of 6eee1a7
```

**Prior checkpoint docs:** `docs/summaries/2026-06-13-seo-status-checkpoint.md` (the metric trail),
`docs/summaries/2026-06-06-seo-session-handoff.md` (previous era). Specs 023/024/025 + reviews in
`docs/specs/` + `docs/reviews/`.

## When the next session starts

- Owner says "check SEO status" → run `/seo-status pw2d`, apply the decision tree above, log the
  verdict in the checkpoint doc. **Don't propose new on-page specs before the verdict.**
- Owner scraped something → `/audit-scrape {tenant} {category}`.
- Owner asks about coffee2decide before ~Jul 25 → data is still accruing; check
  `pw2d:seo:status` health only, don't over-read early rows.
- If the verdict is "authority": the conversation shifts to off-page strategy (backlinks, linkable
  assets, landing pages) — mostly founder work; the code-shaped pieces are landing pages + Spec-026.
