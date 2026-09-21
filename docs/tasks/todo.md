# Tasks — active work only

One line per item: **name** — gist → where the detail lives. Deferred work is in `backlog.md`; finished
work and session narratives go to `archive/` (moved by `/summary`). Full pre-2026-09-21 history:
`archive/2026-09-21-todo-monolith-snapshot.md`.

## Owner

- [ ] **Weekly picks run + SEO check** — due ~2026-09-28, both tenants (two extension runs), then `/seo-status` → memory `maintenance-cadence`
- [ ] **Monthly sweep: all six c2d categories** — last checked 08-16 → 08-21, overdue; ~40 min a week, oldest first → `docs/specs/031-content-maintenance-cadence.md`

## Content & maintenance — next, in order

- [ ] **pw2d podcast-studio-mics: top-up → rescan → rebuild** — page STALE, category last swept 08-14 and never topped up; add broadcast and USB mics, not more handhelds; clears the one unchecked straggler (Shure SM7dB + MVX2U) → `docs/tasks/2026-08-22-pw2d-tier3-topup.md` §5
- [ ] **pw2d lavalier: rebuild only** — premium pick (Shure GLXD14+) carries a high-price flag; pool swept 08-31, so no re-sweep; single-scan the one unchecked offer (#4649 Hollyland Lark MAX 2) first → `docs/summaries/2026-09-21-session-handoff.md`
- [ ] **c2d: surgical price patch on four pages** — gooseneck-kettles, manual-coffee-grinders, pour-over, super-automatic: `price_drift` only, selection unchanged; re-price the text and re-stamp snapshots, after that category's sweep → snapshot "2026-09-21 — weekly picks run"
- [ ] **c2d cold-brew-makers: full path** — dead premium pick, overall pick +40%, thinnest pool on either site (44 buyable); top-up before the rescan → same section
- [ ] **Read every accepted title after an import** — standing step until the import-quality spec ships; on 09-21 the first dry-run picked a mouse as the budget keyboard → `docs/summaries/2026-09-21-session-handoff.md` §Findings 5

## Specs & engineering — next

- [ ] **Import quality (spec candidate, before the next import)** — four linked defects: the Bouncer accepts the wrong product type (mice, numpads, combos, switchless kits in keyboard categories); product names are not forced into "Brand Model" shape; the same-model pick guard false-merges and false-splits (`modelKey()`, open since 08-21, live on pages); picks do not require a health check, so a never-verified listing reads as clean → snapshot "Audit 2026-08-21" H-A, "2026-08-20" findings, "2026-09-21" import review
- [ ] **Product page content depth (Spec 028 candidate)** — product pages carry ~70% of impressions and clicks and produced every store click of the last 28 days; promoted to "next spec" on 08-17 and never written → snapshot "SEO checkpoint 2026-08-17"
- [ ] **Surface nightly pull failures** — both data bugs found on 09-21 were invisible: errors go to cron's /dev/null and `pw2d:seo:status` stayed HEALTHY; log them and flag a tenant whose latest GSC date lags the other → `docs/summaries/2026-09-21-session-handoff.md` §Findings 3

## SEO — for the ~2026-09-28 check

- [ ] **Do page-one impressions hold a fourth week, and do clicks move?** — pw2d ≥ 400/wk, c2d ≥ 800/wk; two more flat-click weeks would justify title/snippet work → `docs/summaries/2026-06-13-seo-status-checkpoint.md` UPDATE 2026-09-21
- [ ] **First store-click trend read** — Google clicks → store clicks per tenant, now stored nightly (Spec 040); baseline c2d 5 / pw2d 1 per 28 days
- [ ] **c2d traffic jumped ~5× on 09-17** — GA4 sessions 2–9 a day → 19–33; source unchecked (organic, referral or bot)
- [ ] **pw2d: ~1,500 GA4 sessions in 08-24 → 09-06 against 85 PostHog visitors** — almost certainly unfiltered bots; check source and country before quoting any pw2d session figure
- [ ] **Headsets remote-worker page: position 6.4, 93 impressions, 0 clicks** — look at the live snippet; observation only until the pattern holds two more weeks
- [ ] **First engagement read with c2d included** — c2d only sends PostHog events from 2026-09-21, so not before ~10-05 → memory `posthog-eu-cloud`
