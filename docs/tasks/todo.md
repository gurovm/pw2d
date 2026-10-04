# Tasks — active work only

One line per item: **name** — gist → where the detail lives. Deferred work is in `backlog.md`; finished
work and session narratives go to `archive/` (moved by `/summary`). Full pre-2026-09-21 history:
`archive/2026-09-21-todo-monolith-snapshot.md`.

## Owner

- [ ] **Weekly picks run + SEO check** — last done 2026-10-04 (both tenants); next due ~2026-10-11, two extension runs, then `/seo-status` → memory `maintenance-cadence`
- [ ] **Monthly sweep: all six c2d categories** — last checked 08-16 → 08-21, overdue; ~40 min a week, oldest first → `docs/specs/031-content-maintenance-cadence.md`
- [x] **22 leftover files in prod `/tmp` from 08-28 → 29** — gone on 2026-10-04 check (OS temp cleanup, no reboot since 03-06); the two lost backups were pre-August versions of headsets and lavalier, both superseded → `docs/summaries/2026-09-21-healthy-status-hid-two-data-bugs-three-pages-rebuilt.md` §3

## Content & maintenance — next, in order

- [ ] **pw2d podcast-studio-mics: top-up → rescan → rebuild, after Spec 041** — page STALE; since the 10-04 picks run the premium pick (Shure SM7dB) has no price; category last swept 08-14, never topped up; add broadcast and USB mics, not more handhelds → `docs/tasks/2026-08-22-pw2d-tier3-topup.md` §5
- [ ] **pw2d lavalier: monthly sweep only** — page FRESH again on 10-04 (premium price back to normal), so no rebuild unless the sweep flags a pick; last full sweep 08-31, due since 09-30 → spec 031
- [ ] **pw2d: price-only patches on two pages** — gaming chat headsets (Corsair HS80 MAX $150 → $120) and mechanical gaming keyboards (Glorious GMMK 3 PRO HE $250 → $350, NuPhy Air75 HE $130 → $150); selection unchanged, both swept 09-21 → `pw2d:landing-pages:audit pw2d` 2026-10-04
- [ ] **c2d: surgical price patch on four pages** — gooseneck-kettles, manual-coffee-grinders, pour-over, super-automatic: `price_drift` only, selection unchanged; re-price the text and re-stamp snapshots, after that category's sweep → snapshot "2026-09-21 — weekly picks run"
- [ ] **c2d cold-brew-makers: full path** — dead premium pick, overall pick +40%, thinnest pool on either site (44 buyable); top-up before the rescan → same section
- [ ] **Read every accepted title after an import** — standing step until two clean imports in a row after Spec 041; on 09-21 the first dry-run picked a mouse as the budget keyboard → `docs/summaries/2026-09-21-healthy-status-hid-two-data-bugs-three-pages-rebuilt.md` §2 "Import pollution"

## Specs & engineering — next

- [ ] **Import quality — Spec 041 drafted, needs owner approval + 3 answers** (rename existing names? hide 17 Generic/Unbranded products? kits and combos excluded?) — must ship before the next import → `docs/specs/041-import-quality.md` §Open questions
- [ ] **Build Spec 041** — one build; prompt calibration on ~46 known cases before `/deploy`; then model backfill per category in session, owner reviews groups + page impact → spec §Rollout
- [ ] **Product page content depth (Spec 028 candidate)** — product pages carry ~70% of impressions and clicks and produced every store click of the last 28 days; promoted to "next spec" on 08-17 and never written → snapshot "SEO checkpoint 2026-08-17"
- [ ] **Surface nightly pull failures** — both data bugs found on 09-21 were invisible: errors go to cron's /dev/null and `pw2d:seo:status` stayed HEALTHY; log them and flag a tenant whose latest GSC date lags the other → `docs/summaries/2026-09-21-healthy-status-hid-two-data-bugs-three-pages-rebuilt.md` §2 "GA4 undercount", "GSC freeze"

## SEO — for the ~2026-10-11 check

- [x] **Page-one impressions held a fourth week on both tenants; c2d clicks moved (26 → 39 / 28d), pw2d did not (17 → 15)** → `docs/summaries/2026-06-13-seo-status-checkpoint.md` UPDATE 2026-10-04
- [x] **Store-click trend** — c2d 5 → 12, pw2d 1 → 2 per 28 days; PostHog agrees → `docs/summaries/2026-06-13-seo-status-checkpoint.md` UPDATE 2026-10-04
- [x] **c2d "5× traffic jump" and pw2d GA4 sessions are both bot-inflated** — PostHog sees ~4 real visitors a day per site; quote PostHog, never GA4 sessions → `docs/summaries/2026-06-13-seo-status-checkpoint.md` UPDATE 2026-10-04
- [x] **Remote-worker headsets snippet checked** — title reads "for Remote Worker" (singular); 0 clicks in 187 impressions at ~6.2; folds into the title work below → `docs/summaries/2026-06-13-seo-status-checkpoint.md` UPDATE 2026-10-04
- [x] **First engagement read with c2d** — 53 visitors in 13 days, 7 clicked through to a store → `docs/summaries/2026-06-13-seo-status-checkpoint.md` UPDATE 2026-10-04
- [ ] **pw2d title/snippet work (spec candidate)** — justified now: page-one held three weeks with flat clicks; scope product page titles (~75% of impressions), ship together with Spec 041's 88 renames, baseline product-page CTR first → `docs/summaries/2026-06-13-seo-status-checkpoint.md` UPDATE 2026-10-04
- [ ] **Next check questions** — does c2d's page-one climb continue (≥ 1,300/wk) with clicks ≥ 15/wk; do the three silent c2d `/best/` pages appear → `docs/summaries/2026-06-13-seo-status-checkpoint.md` UPDATE 2026-10-04
