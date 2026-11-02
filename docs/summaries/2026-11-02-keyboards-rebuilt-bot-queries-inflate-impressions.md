# Session Summary — 2026-11-02 — Keyboards guide rebuilt on a cleaned pool; bot queries inflated impressions on both sites; the "out-of-stock picks" gap was a stale label

**Commits:** `61e8a34` (drafts: keyboards clean-up, models, guide) · `2541012` (SEO 10-29 crawl addendum + 11-02 check,
`/seo-status` bot-query step) · `569c97d` (lesson, `stock_status` backlog item, todo) · **Suite:** not run, no code changed
**Prod:** runs `959ec30`, the last code commit; everything after it is docs. Nothing to deploy.
**Prod writes:** nginx log retention 14 → 60 days (10-29) · mechanical keyboards clean-up: 189 models, 11 hides, 5 brands, 2 renames (10-29) ·
`/best/mechanical-gaming-keyboards` saved (11-01). Backups for each are in §1.
**Session ran:** 10-29 → 11-02, with the owner offline for ~24 h (10-30/31).

> **The one-line lesson:** before proposing a fix to a status column, trace how it is written. `stock_status` keeps its last
> value when a payload sends none, so "out of stock" on a priced offer is a stale label, not a gap in the picker. The fix I
> proposed on 10-29 was approved and then cancelled before any code (`docs/lessons.md` 2026-10-29).

## 1. What changed

**Prod writes (each with its undo path):**
- **nginx access logs kept 60 days, not 14** (10-29). `/etc/logrotate.d/nginx`: `rotate 14` → `rotate 60`. Reason: the
  Spec 042 Phase B read on ~11-18 compares pages Googlebot re-fetched after the 10-28 title switch, and 14 days would
  have deleted that list. Backup `/root/backups/logrotate-nginx-before-60d-2026-10-29`; undo = copy it back.
- **Mechanical keyboards clean-up** (10-29, owner-approved draft `docs/drafts/2026-10-29-mech-keyboards-cleanup.md`),
  per record through the model: 189 models, 11 hidden (4 keyboard+mouse bundles, 5 membrane boards, SteelSeries
  Apex 5, the dead Wooting TenZ listing), 5 brand fixes, 2 renames (page addresses unchanged). Live pool 203 → 192.
  Backup `/root/backups/mech-keyboards-products-before-cleanup-2026-10-29.sql`; undo = restore those rows.
- **`/best/mechanical-gaming-keyboards` rebuilt** (landing page id 1, saved 11-01 18:04 UTC after the owner's "yes"). Four
  guards passed (live row = before-snapshot, picks = fresh selection in order, prices = snapshots, no style violations),
  and the audit reads FRESH. Picks: Keychron Q5 Pro gray $120 (overall), Keychron C3 Pro 8K $30 (budget), Keychron Q2
  Max $230 (premium), ASUS ROG Azoth Extreme $400 (pro gamer), Glorious GMMK 3 PRO HE $200 (hobbyist), Razer
  BlackWidow V4 Low Profile $200 (streamer), NuPhy Air75 HE $130 (7th). Row backup, script and log:
  `/root/backups/2026-11-01-mech-keyboards/`; undo = restore `landing-mech-keyboards-before-rebuild.sql`. Text and the
  four editorial calls: `docs/drafts/2026-10-29-mech-keyboards-guide-draft.md`.
- Four scratch files of ours removed from prod `/tmp` on 11-02. None left.

**Owner, 10-29:** "Request indexing" in Search Console for 5 pw2d keyboard product pages: the 4 that FAIL on the old
merchant-listing markup, plus the white K8 HE, which Google didn't know about.

**Repo:**
- `/seo-status` step 2 now checks a page's queries in the GSC API before believing an impressions jump with 0 clicks.
- New lesson: trace a status column before proposing a fix.
- Backlog: `stock_status` sticks after a listing comes back.

**Cancelled, never built:** "the picker lets out-of-stock listings through" (approved 10-29). See §2, "Falsified".

## 2. What was measured

All figures come from prod.
- **Keyboards sweep, 10-29:** 200 of 203 listings checked. The 3 not refreshed were not picks.
- **Keyboards selection after the clean-up:** 3 of the 7 picks stay, and one moves to another slot.
  - Keychron is capped at 3 slots, so the 3 "best for" slots go to other brands, although the Q2 Max tops all three
    rankings on raw score. The cost: the streamer pick (Razer) has the lowest typing score on the page (75).
  - Two picks sit on low prices that look like sales: the Q5 Pro gray at $120 (its other colours cost $220), and the C3
    Pro 8K at $30 (the plain C3 Pro costs $50).
- **Crawl, 10-29:** `?limit=` fetches fell 853 → 170 per two weeks. The freed crawl went nowhere: total real-Googlebot
  fetches 3,898 → ~1,750, while plain product fetches stayed at ~60/day. Detail: SEO checkpoint, "Addendum 2026-10-29".
- **SEO check, 11-02** (GSC through 10-30): templated bot queries ("{brand} {category} review 2026") made 3,561 of
  4,599 impressions on c2d `/best/cold-brew-makers` and 410 of 623 on pw2d `/best/mechanical-gaming-keyboards`. Both
  sites were hit in the same week, so the cause is outside our sites.
  - 28-day clicks: c2d 78 → 103, pw2d 14 → 11.
  - Affiliate estimate: c2d ≈ $17/month, pw2d ≈ $0.3/month. These are what-ifs; the $50 trigger is met 0 of 4 weeks.
  - Detail: SEO checkpoint, "UPDATE — 2026-11-02 check".
- **Guide audit, 11-02 13:20 UTC:** 4 STALE, 9 FRESH. STALE: pw2d podcast mics (pick ineligible, selection drift),
  ergonomic keyboards (pick ineligible, selection and price drift), headsets (price), c2d pour-over (price). Unchanged
  from 11-01. Mechanical keyboards is FRESH.
- **Queue, 11-02:** 0 jobs and 0 failed jobs in the last 4 days.
- **Falsified (our own claim, 10-29):** "out-of-stock listings pass the picker." The extension sends no price from a
  page that says "out of stock" or "currently unavailable" (`content.js` ~879). Ingestion keeps the previous
  `stock_status` when the payload has none (`OfferIngestionService` line 85, and the same pattern in both import
  controllers). So the NuPhy Air75 HE's admin label was left over from an earlier check: the 10-29 sweep read a
  $129.95 buy-box price. The same applies to sE V7 MK. Nothing public reads the column.
- **Also found:** the Wireless keyboard preset is missing from the page by accident. All four presets have sort_order 0,
  so the picker took the first three in database order. The owner was fine with leaving it off.

## 3. What is still unknown

- **Weekly picks runs (both tenants):** the owner was running them on 11-02. As of 13:20 UTC no offer had been checked
  on prod since 10-30. When the result lines arrive, run `pw2d:landing-pages:audit`. Also watch the lavalier DJI Mic
  overall slot, which leads by 0.1.
- **Has the bot burst passed?** Visibility can't be read until it has; next check ~11-07.
- **Do the two sale-like keyboard prices hold?** If they end, the audit flags price drift and the overall or budget slot
  may move.
- **Brand cap:** ASUS / ASUS ROG and Logitech / Logitech G are separate brand rows, so the cap of 3 per brand counts
  them apart. Merging them touches other categories. Not decided; backlog.
- **Presets with equal sort_order:** other categories may also fall back to database order. Not checked; backlog.
- **Drawer funnel read** (due ~11-02) wasn't done this session.
- **`docs/lessons.md` is 26 KB,** past the ~25 KB condense threshold.
