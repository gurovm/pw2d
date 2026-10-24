# Archived 2026-10-24 — completed in the session of 2026-10-24

Detail: `docs/summaries/2026-10-24-php-rce-auto-patched-stars-kept-three-coffee-guides-rebuilt.md`

- [x] **c2d gooseneck + manual grinders: sweep → regenerate** — DONE 10-24: both swept (gooseneck 51, 1 error on the
  Chefman, not a pick; grinders 58/58) and fully rebuilt from owner-approved drafts
  `docs/drafts/2026-10-24-{gooseneck,grinders}-guide-draft.md`, guards passed, both FRESH; backups
  `/root/backups/landing-{gooseneck,grinders}-before-rebuild-2026-10-24.sql`
- [x] **c2d semi-automatic: budget pick replaced again — FRESH** — Breville Bambino $249.95 → $299.95 (left the
  budget band) → KitchenAid KES6503 ($229.95, Whole Latte Love sale) + five one-line edits, from
  `docs/drafts/2026-10-24-semi-auto-budget-pick-draft.md`; backup
  `/root/backups/landing-semi-auto-before-budget-swap-2026-10-24.sql`
- [x] **Price-band fix follow-up (deployed 10-19)** — DONE 10-24: nightly `products:recalculate-tiers` ran 10-20 → 24,
  `fixed: 0` every night; the 10-24 rescans log `RecalculateCategoryPriceTiers: done` after each price change
  (`fixed: 0`, no band crossed) → summary 2026-10-19 §3
- [x] **Spec 042 read** — DONE 10-24: stars kept on both tenants from our own rating; product star CTR c2d 0.89% →
  1.66%, pw2d 0.92% → 0.95% (−23% over 10-05 → 21, within the ~25% bar); acceptance met → Phase B unblocked →
  SEO checkpoint UPDATE 2026-10-24
- [x] **PHP RCE CVE-2026-109618/109619 on pw2d prod** — already patched by Ubuntu (`8.3.6-0ubuntu0.24.04.13`,
  auto-installed 10-23 06:23 UTC); compromise sweep clean; `session.upload_progress` switched off via
  `/etc/php/8.3/fpm/conf.d/99-pw2d-security.ini` (owner OK, 13:35 UTC)
