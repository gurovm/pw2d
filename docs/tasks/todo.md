# Tasks — active work only

One line per item: **name** — gist → where the detail lives. Deferred work is in `backlog.md`; finished
work and session narratives go to `archive/` (moved by `/summary`). Full pre-2026-09-21 history:
`archive/2026-09-21-todo-monolith-snapshot.md`.

## Owner

- [ ] **Weekly picks run + SEO check** — last done 2026-10-04 (both tenants); next due ~2026-10-11, two extension runs, then `/seo-status` → memory `maintenance-cadence`
- [ ] **Monthly sweeps overdue on 8 of 11 categories** — all six c2d (last 08-14 → 08-21), pw2d podcast mics (08-14) and lavalier (08-31); ~40 min a week, **high-ticket first** (espresso machines), oldest first only as tie-breaker → spec 031 + memory `content-priority-high-ticket`

## Content & maintenance — next, in order

- [ ] **c2d espresso machines first (owner preference 2026-10-04: high-ticket before cheap)** — semi-automatic (started 10-05): top-up from Whole Latte Love + Clive Coffee listing pages, not Amazon (69 of 166 offers are Amazon; pool barely changed since March) — **10-05: imports done (Clive, WLL 7 pages, Amazon 2×2), pool 135 → 193, titles read, hides/brand merges/renames applied; category rescan running** → model backfill dry-run (`docs/drafts/2026-10-05-semi-auto-models.json`, review in `…-semi-auto-review.md`; flow control = same model, owner to confirm on the dry-run) → apply → rebuild if picks move (page FRESH but the `/best/` page has no Google rows yet); super-automatic: sweep + model backfill + its price patch (Philips 3200 +16%) → memory `content-priority-high-ticket`
- [ ] **pw2d podcast-studio-mics: top-up → rescan → rebuild, unblocked (models done 10-04)** — page STALE; since the 10-04 picks run the premium pick (Shure SM7dB) has no price; category last swept 08-14, never topped up; add broadcast and USB mics, not more handhelds → `docs/tasks/2026-08-22-pw2d-tier3-topup.md` §5
- [ ] **pw2d lavalier: monthly sweep only** — page FRESH again on 10-04 (premium price back to normal), so no rebuild unless the sweep flags a pick; last full sweep 08-31, due since 09-30 → spec 031
- [ ] **pw2d: price-only patches on two pages** — gaming chat headsets (Corsair HS80 MAX $150 → $120) and mechanical gaming keyboards (Glorious GMMK 3 PRO HE $250 → $350, NuPhy Air75 HE $130 → $150); selection unchanged, both swept 09-21 → `pw2d:landing-pages:audit pw2d` 2026-10-04
- [ ] **c2d: surgical price patch on four pages** — gooseneck-kettles, manual-coffee-grinders, pour-over, super-automatic: `price_drift` only, selection unchanged; re-price the text and re-stamp snapshots, after that category's sweep → snapshot "2026-09-21 — weekly picks run"
- [ ] **c2d cold-brew-makers: full path** — dead premium pick, overall pick +40%, thinnest pool on either site (43 live after 8 generics were hidden 10-04); top-up before the rescan → same section
- [ ] **Read every accepted title after an import** — standing step until two clean imports in a row after Spec 041; on 09-21 the first dry-run picked a mouse as the budget keyboard → `docs/summaries/2026-09-21-healthy-status-hid-two-data-bugs-three-pages-rebuilt.md` §2 "Import pollution"

## Specs & engineering — next

- [ ] **Spec 041 model backfill — 1 of 11 done (podcast mics 10-04)** — per category: Claude drafts models → `apply-models --dry-run` → owner reviews groups + page impact → apply; same pass lists live products the notes now exclude; backfill before that category's next rebuild → spec §Rollout 5, `docs/summaries/2026-10-04-import-gate-shipped-and-our-stars-were-amazons-rating.md`
- [ ] **Product page content depth (Spec 028 candidate)** — product pages carry ~70% of impressions and clicks and produced every store click of the last 28 days; promoted to "next spec" on 08-17 and never written → snapshot "SEO checkpoint 2026-08-17"
- [ ] **Head-to-head "A vs B" pages + "Compared with" block (Spec 043)** — **built 10-05, suite 983 green, uncommitted, not deployed**; next: semi-auto model backfill → draft GO vs Silvia + Barista Express vs Pro → owner review → `/deploy` → save + publish; Phase 0 GSC pull done (demand tiny, `docs/drafts/2026-10-05-vs-queries.md`) → Keyword Planner volumes done →  5 c2d pairs approved ( Profitec GO vs Silvia, Jura E4 vs E6, E6 vs E8, E8 vs S8, Barista Express vs Pro) → one build → 6-week gate; after each category's model backfill → `docs/specs/043-vs-pages.md`
- [ ] **Surface nightly pull failures** — both data bugs found on 09-21 were invisible: errors go to cron's /dev/null and `pw2d:seo:status` stayed HEALTHY; log them and flag a tenant whose latest GSC date lags the other → `docs/summaries/2026-09-21-healthy-status-hid-two-data-bugs-three-pages-rebuilt.md` §2 "GA4 undercount", "GSC freeze"

## SEO — for the ~2026-10-11 check

- [ ] **Spec 042 read (~2026-10-25, after Google recrawls)** — URL-inspect the 5 named product pages; REVIEW_SNIPPET impressions + CTR on product pages vs the spec baseline (0.9% with stars / 0.2% without); Phase B (pw2d titles + 88 renames) only after this → spec §Rollout 3–4
- [ ] **Next check questions** — does c2d's page-one climb continue (≥ 1,300/wk) with clicks ≥ 15/wk; do the three silent c2d `/best/` pages appear; affiliate revenue estimate vs the $50/month trigger (baseline c2d ≈ $20) → `docs/summaries/2026-06-13-seo-status-checkpoint.md` UPDATE 2026-10-04, `/seo-status` step 2c
