# Session Summary — 2026-10-05 (evening) — No outside site links to coffee2decide, and its shared template reads as a generic AI tool

**Commits:** `25b2f9a` (draft Specs 044 + 045) · summary commit below · **Suite:** not run (no code touched)
**Prod:** unchanged — still `aaa2d2e`; `origin/main` is ahead by docs only
**Prod writes:** none (read-only `SELECT`s and public page fetches)
**Spec:** [044-off-site-links.md](../specs/044-off-site-links.md) (draft) · [045-per-site-design.md](../specs/045-per-site-design.md) (draft)

> **The one-line lesson:** before asking strangers to link to us, the page they land on has to survive a skeptical
> click — today coffee2decide's About page says "About Pw2D", its contact is a pw2d.com address, and its home page
> shows no coffee.

## 1. What changed

- **Spec 044 (draft) — off-site links from zero.** The July authority verdict ("pivot to off-page, founder-led") was
  never planned; this is the plan. Phases: 0 trust pages + coffee mail + backlink tool → 1 "your product won" emails
  to the brands of FRESH guides (semi-automatic first) → 2 journalist-request platforms → 3 optional Reddit /
  Home-Barista presence → 4 a data page at month 3. ~2 h/week of owner time; Claude researches and drafts, the owner
  writes in his own words and sends. Explicit "won't do" list (bought links, exchanges, guest-post farms, AI posts).
- **Spec 045 (draft) — a design of its own per site.** Diagnosis from screenshots; theme layer = extended design
  tokens in tenant `data` + per-tenant Blade overrides (`resources/views/themes/{tenant_id}/` prepended to the view
  finder); mockups before code; staged rollout, least-searched pages first, product/compare pages last.
- **Owner decision → memory `two-to-three-sites-max`:** one person can run 2–3 sites, not many; per-site design is
  fine, logic/data/URLs stay shared. Indexed in `MEMORY.md`.
- **Lesson added** (`docs/lessons.md`): headless screenshots mislead about layout; check a figure's window against
  when tracking began.

## 2. What was measured (all prod or live site, 2026-10-05)

- **Referrers to coffee2decide.com** (PostHog EU, `$pageview`, c2d host; c2d tracked only since 09-21, so ~14 days):
  google 31 people, direct 23, bing 2, duckduckgo 2, Google app 2. **Zero visitors from any other website.**
- **Trust pages, live:** `/about` h1 "About Pw2D" with generic AI-pitch copy and no person; `/contact` →
  `support@pw2d.com` (hard-coded, `pages/contact.blade.php:14`); **coffee2decide.com has no MX record** (pw2d.com
  uses ImprovMX). DNS for both is DigitalOcean.
- **Catalog (prod, `status IS NULL AND is_ignored = 0`):** 454 live coffee products — semi-automatic 188,
  super-automatic 63, manual grinders 58, pour-over 51, gooseneck 51, cold brew 43. Offers on those: Amazon 394,
  Whole Latte Love 141, Clive Coffee 36.
- **Guide picks by brand** (prod `landing_pages.picks`): the outreach target list for Spec 044 Phase 1 — semi-automatic:
  Dalla Corte Mina, ECM Mechanika Max II + Estetika, La Marzocco GS3 MP + Linea Mini, Bezzera BZ13, De'Longhi Dedica
  Maestro Plus; the other five guides are in the spec. Every guide lists **two `overall` roles** (not investigated).
- **Data limits for a "study":** semi-automatic features are six AI-assessed scores (228 values each), not specs like
  boiler type; there is **no price-history table**.
- **Per-site design today = 8 `tenant()` values in views** (3 colours, logo, `brand_name`, `name`, hero headline +
  sub-headline). Home shows no product above the fold; one font (Inter); amber / lime / green / indigo / purple
  accents beside the caramel brand; prices as "$$$" on compare cards and product pages while the guide shows
  "~$8,500"; the compare drawer auto-opens over a third of the desktop page. The guide page is the most credible.
- **No job, command, mail or notification renders Blade** (grep) — a per-request theme view path cannot leak across
  tenants through the finder cache.
- **Community / platform rules (web, 10-05):** Home-Barista bans promoting a blog or business; site link allowed in
  the profile; signature link after 90 days and 15 posts, non-commercial. HARO's successor Connectively shut in late
  2024; Featured relaunched the HARO brand in April 2025; Source of Sources is free.

**Corrected in session:** the big blank areas in the home screenshot were not a defect — `.hero { min-height: 65vh }`
in a 2,400 px-tall window. The cut-off text in the 390 px "mobile" shot is likely headless Chrome's minimum window
width, not a site bug. Spec 044 first said "last 30 days" for referrers; c2d tracking began 09-21 — fixed.

## 3. What is still unknown

- **Backlink count** — no tool yet (Search Console Links, Ahrefs Webmaster Tools are Phase 0 owner steps).
- **r/espresso and other subreddit rules** — reddit.com blocked fetching; read each sidebar before posting.
- **Real-phone rendering** of the home page — the Chrome extension was not connected; headless cannot emulate a phone.
- **Owner answers that size both plans:** real name on About and in pitches? ~2 h/week realistic? does he make
  espresso himself (decides Spec 044 Phase 3)? approve 2–3 coffee design directions (recommended: yes, coffee only)?
  keep the logo and brown?
- **Order of work** proposed: Spec 045 stage 1 (home + About) before Spec 044 brand emails, so people who follow an
  email land on a coffee site. Not yet agreed.
- Queue empty, 0 failed jobs today. No `/tmp` scratch files on prod.
