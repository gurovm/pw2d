# Archived 2026-10-19 — completed in the session of 2026-10-19

Detail: `docs/summaries/2026-10-19-rescans-never-updated-price-bands-budget-pick-replaced-drawer-off.md`

- [x] **Price bands go stale after rescans — DEPLOYED 2026-10-19 (`93df98d`)** — root cause: the rescan's price-change
  check ran after `ListingHealthService::apply()` re-saved the offer, so `wasChanged()` was always false (167 rescans
  on 10-15, 0 recalcs). Fix = capture before `apply()` + nightly `products:recalculate-tiers` at 03:20 + log line.
  Prod recalc 10-19 (27 products, backup `/root/backups/products-price-tier-before-recalc-2026-10-19.sql`) → Spec 029
  addendum 2026-10-19
- [x] **Stop auto-opening the Customize drawer — DEPLOYED 2026-10-19 (`b71ae3e`)** — all devices; teaser + manual open
  kept; verified live (no auto-open, manual open works, console clean) → Spec 025 addendum 2026-10-19
- [x] **c2d semi-automatic: budget pick replaced — FRESH** — Dedica Maestro Plus ($394, above the $250 budget line,
  held the slot through a stale band) → Breville Bambino ($250); owner-approved draft
  `docs/drafts/2026-10-19-semi-auto-budget-pick-draft.md`, saved behind selection + price + style guards; backup
  `/root/backups/landing-semi-auto-before-budget-swap-2026-10-19.sql`
