# Session Summary — 2026-10-24 — Ubuntu had already patched the PHP RCE (40 h exposure, no sign of a break-in); our own stars passed the Spec 042 test; three coffee guides rebuilt, two of them around one product that tops every score

**Commits:** `7fb6d53` (approved guide drafts) · `839aed6` (SEO checkpoint UPDATE 2026-10-24) · `35ace37` (lesson, backlog,
tasks) · this summary
**Suite:** not run — no code changed this session
**Prod:** no deploy; prod runs `b71ae3e`, `origin/main` is ahead by docs only — all code is deployed
**Prod writes (each confirmed with the owner, backup first):** one PHP-FPM config file + reload; three `landing_pages`
rows (semi-automatic, manual grinders, gooseneck) — table in §1
**Spec:** 042 (Rollout 4 read — met), 031 (cadence), 030 (audit)

> **The one-line lesson:** the picker places each product once, so when one product tops every score (the 1Zpresso
> K-Ultra, the Brewista Artisan) the "best for" slots are really "if not that one" slots — the page has to say so,
> and the slot texts have to say when the placed product is the better buy.

## 1. What changed

**PHP RCE (CVE-2026-109618/109619), checked because MadData patched its servers on 10-22.**
pw2d prod runs Ubuntu's own PHP, not ondrej's: `8.3.6-0ubuntu0.24.04.13` carries
`debian/patches/CVE-2026-109618_109619.patch` (built 10-19, embargoed). Unattended-upgrades installed it 10-23 06:23 UTC
and PHP-FPM restarted at 06:23:41 — no action was needed. Exposure: disclosure 10-21 14:00 → 10-23 06:23 ≈ 40 h
(MadData's manual patch: ~19 h). Mac's Homebrew PHP was already 8.4.27 (desk-de session).

| Prod write | Backup / undo |
|---|---|
| `/etc/php/8.3/fpm/conf.d/99-pw2d-security.ini` → `session.upload_progress.enabled = 0`; `php-fpm8.3 -t` passed, graceful reload 13:35:32 UTC; FPM reports Off; home pages 200 before/after, multipart POST 405 before/after | new file, nothing overwritten · undo: delete the file, `systemctl reload php8.3-fpm` |
| Semi-automatic guide (row 8): budget Breville Bambino → **KitchenAid KES6503** + five one-line edits, from `docs/drafts/2026-10-24-semi-auto-budget-pick-draft.md` | `/root/backups/landing-semi-auto-before-budget-swap-2026-10-24.sql` (INSERT for row 8) · delete row 8, run the file |
| Manual grinders guide (row 4): full rewrite after the sweep, from `docs/drafts/2026-10-24-grinders-guide-draft.md` | `/root/backups/landing-grinders-before-rebuild-2026-10-24.sql` · delete row 4, run the file |
| Gooseneck guide (row 9): full rewrite after the sweep, from `docs/drafts/2026-10-24-gooseneck-guide-draft.md` | `/root/backups/landing-gooseneck-before-rebuild-2026-10-24.sql` · delete row 9, run the file |

All three saves ran through a piped PHP script (nothing written to the server) behind four guards — live row equals
the page the draft was built on · picks equal a fresh `SelectLandingPagePicks` run, in order · every price equals its
snapshot · `ProseStyle::violations()` empty — then the audit: all three FRESH, live pages 200 with the new text.

- **Weekly picks runs, both tenants** (owner, extension via US VPN): c2d 42/44, pw2d 34/35. The 3 Amazon errors
  (Bambino 1277, Takeya 2904, Kinesis Advantage360 1097) were fine on a re-check in the VPN profile; the Bambino came
  back at $299.95 (was $249.95), out of the budget band → the semi-automatic swap.
- **Category sweeps (owner):** manual grinders 58/58, 0 errors; gooseneck 51, 1 error (Chefman 2246, not a pick).
- **New picks.** Semi-automatic budget: KitchenAid KES6503, $229.95 at Whole Latte Love (a **sale** price; regular $349).
  Grinders: Timemore Chestnut S3 overall ($160), 1Zpresso K-Ultra premium ($260), Comandante C40 MK3 espresso ($350);
  P2, Xlite, C5 ESP PRO ($90 → $110), Exagrind unchanged. Gooseneck: Cocinare **Artisan** overall ($60 — a different
  kettle from the old Cocinare pick, imported 08-21 an hour before the page was built and never price-checked until
  today), Grosche Marrakesh budget ($35, stovetop), Mecity 7th ($35, electric); Timemore Fish Smart $100 → $130.
- **SEO check** → [checkpoint UPDATE 2026-10-24](2026-06-13-seo-status-checkpoint.md) (tables live there).
- **Docs:** lesson "diagnosed extension errors from a browser that does not see what the extension sees"; backlog
  "Server & runtime" (OPcache preload + the upload-progress note); memory `maintenance-cadence` 10-24 entry.

## 2. What was measured

All prod, 2026-10-24 unless dated.

- **Compromise sweep, window 10-21 14:00 → 10-23 06:23 UTC (read-only):** 0 new or modified `.php` under `/var/www`
  (compiled views excluded); 0 PHP inside non-`.php` files in `public/` and `storage/app`; no `www-data` processes
  beyond nginx/FPM/queue workers, crontab unchanged since 2026-05-14, no `www-data` tmp files, no outbound FPM
  sessions; 0 FPM child crashes, 0 `Allowed memory size`, 0 kernel segfault/OOM; 0 5xx/499 on POST.
- **One scanner inside the window:** `23.180.120.171`, ~930 POST/PUT/PATCH to 123 common PHP paths, 10-22
  03:00–03:11 UTC (first seen 10-21 23:48), all 404/405, never returned. POSTs per day: ~190–300 baseline, 447 on
  10-21, **1,052 on 10-22**, 192 on 10-23.
- **Price-band fix (deployed 10-19) verified:** nightly `products:recalculate-tiers` 10-20 → 24, `fixed: 0` every
  night; today's rescans each logged `RecalculateCategoryPriceTiers: done`. Follow-up closed.
- **Guide audit after both picks runs (14:17 UTC):** 8 of 11 guides STALE. After today's three saves: c2d
  semi-automatic, super-automatic, cold brew, grinders, gooseneck FRESH; pour-over price-only (Bee House back in stock
  $34.99; Melitta cone $15 → $18.99, Melitta 42oz $35 → $49.97); all five pw2d guides STALE — podcast mics (V7 MK and
  SM7dB both without a buyable price), mechanical keyboards (NuPhy Air75 HE "Currently unavailable", GMMK 3 PRO HE no
  price), ergonomic (Kinesis Freestyle Edge `high_price`), lavalier (selection), gaming headsets (price only:
  Beyerdynamic MMX 300 PRO $380 → $229).
- **One product tops every preset (computed from `feature_preset` weights, sum of score × weight):** grinders — the
  K-Ultra ranks first on espresso-pro, daily-brewer and traveler; gooseneck — the Brewista ranks first on enthusiast,
  casual and tea-drinker. Presets skip already-placed products (`SelectLandingPagePicks.php:268`), and premium is
  filled before them.
- **Semi-automatic bands:** tier 1 $129–$240, tier 2 $256–$800 (in-stock, unflagged) — the budget line sits between.
- **SEO (detail in the UPDATE):** Spec 042 acceptance met on both tenants (c2d product star CTR 0.89% → 1.66%, pw2d
  0.92% → 0.95%); pw2d page-one product impressions ~65/day → ~27/day since ~10-13, stored rows equal the GSC API;
  c2d 20 clicks in 4 days; first `/vs/` impressions 10-20 (GO vs Silvia pos ~8); c2d affiliate estimate ≈ $13/month.
- **OPcache preload warnings:** 276 "Can't preload unlinked class" lines since 10-23, present at every FPM start
  before today's reload — pre-existing, speed only.

**Corrected or falsified this session:**
- "Two of the picks-run errors are items that don't ship to Israel" — the Claude-in-Chrome tab is the owner's
  logged-in Israel profile; the extension runs through a US VPN logged out. Withdrawn; lesson added.
- The first picks dry-run keyed picks by role and merged the guide's two "overall" slots, hiding the out-of-stock
  X-Ultra. Re-run as an ordered comparison.
- "Add a buffer around the budget line" (proposed, then withdrawn): the Bambino moved 20% and the KitchenAid's sale
  ends at +50%; a buffer only absorbs small moves.
- The 10-19 warning "Bambino sits 5¢ under the line — watch for flapping" came true within five days.

## 3. What is still unknown

- **Whether anyone exploited the 40-hour PHP window.** "No evidence", not "not exploited": CVE-2026-109619 leaves no
  file trace, and the nginx log keeps no request bodies.
- **Why pw2d product pages are slipping off page one** (c2d, same deploys, is fine). One more week before acting →
  todo "Next check questions".
- **When the KitchenAid sale ends** — the semi-automatic budget slot moves again then; its Amazon offer (1428,
  $349.99) is unchecked since 10-05.
- **The OPcache preload gaps** — which classes, and whether preload is worth keeping → backlog.
- The Stariver kettle (2255) came back with no price; the Chefman (2246) errored — neither is a pick.
