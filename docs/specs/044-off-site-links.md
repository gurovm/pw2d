# Spec 044 — Off-site links: getting other sites to point to coffee2decide

**Status:** DRAFT 2026-10-05, waiting for the owner's answers (see Open questions). Coffee first; pw2d follows only if
it works here.

## Goal

Other sites link to coffee2decide.com and send it visitors. Two reasons:

1. **Authority.** The 2026-07-10 SEO verdict found an authority cap (three pages stalled at the same ~10 position band
   with every on-page lever shipped) and pivoted to "off-page, founder-led, Claude supports". It was never planned.
2. **Referral visitors.** People buying $1–4k machines research on forums and Reddit before they search.

Starting point: no social accounts, no audience, an owner with a day job. Sized at **~2 hours a week of owner time**;
Claude does the research, drafting and tracking. Links move rankings over months, not weeks.

## Baseline (verified 2026-10-05)

- **Referrers (PostHog, coffee2decide.com, queried as "last 30 days" but c2d is tracked only since 2026-09-21, so
  ~14 days):** google 31 people, direct 23, bing 2, duckduckgo 2, Google app 2. **No other website sent a single
  visitor.**
- **Backlinks:** unknown — no tool connected yet (Phase 0).
- **What we can honestly say about ourselves:** 454 live products in 6 categories (semi-automatic 188, super-automatic
  63, manual grinders 58, pour-over 51, gooseneck kettles 51, cold brew 43), offers from Amazon (394), Whole Latte
  Love (141) and Clive Coffee (36).
- **Guide freshness:** only the semi-automatic guide is FRESH; the other five coffee guides are STALE (`todo.md`).
- **Trust pages are not ready:** coffee2decide.com/about reads "About Pw2D" with generic AI-pitch copy and no person
  behind it; /contact gives `support@pw2d.com` (hard-coded in `pages/contact.blade.php:14`); coffee2decide.com has
  **no MX record**, so no address at the domain receives mail (pw2d.com forwards through ImprovMX).
- **Data limits for a "study":** feature values are our AI-assessed 0–10 scores (Espresso Quality, Steam Wand, Workflow,
  Heat-Up, Build, Maintenance), not specs like boiler type; there is no price-history table.

## What we will not do

- Buy links or link packages, "write for us" guest-post farms, link exchanges, private blog networks, mass directory
  submissions. All are Google link spam and can sink a young domain.
- Post AI-written answers or comments. Claude prepares the facts and a skeleton; the owner writes the post in his own
  words (memory `ai-content-style-bar`). Communities ban AI posts and recognise them.
- Drop links to our site in community threads. Home-Barista forbids promotion of a blog or business; a site link is
  allowed in the profile, a signature link only after 90 days and 15 posts and never commercial. Reddit: read each
  subreddit's sidebar rules before the first post; default assumption is no links to our own site.
- Claim expertise we do not have. Every pitch says what we are: a site that compares N machines across three stores.
- Point anyone at a STALE page. A brand or reporter who clicks through must find a live pick with a working price.

## Phases

### Phase 0 — Be someone a stranger can check (week 1)

Anyone who gets an email or reads a quote clicks "About" first.

**Build (small, `builder` + `tester`):**
- `resources/views/pages/about.blade.php` → `@includeFirst(['pages.about.' . tenant('id'), 'pages.about.default'])`;
  current copy moves to `pages/about/default.blade.php`, new `pages/about/coffee2decide.blade.php`.
- `resources/views/pages/contact.blade.php` → address from `tenant('contact_email')`, fallback `support@pw2d.com`.
- No migration (tenant `data` JSON). Setting `contact_email` on the coffee tenant is a prod write: backup + owner OK.
- Tests: `/about` on each tenant shows its own brand name; `/contact` shows the tenant's address or the fallback.

**Coffee About copy (draft in `docs/drafts/`, owner review, style contract):** who runs it; what the site does; how
picks are chosen — **only what the code does** (read `SelectLandingPagePicks` and
`ProductScoringService::scoreAllProducts` first, lesson 2026-10-05); what we do not do (no paid placements; prices
are estimates; store links may later earn a commission — disclose before joining any program).

**Owner (~20 min):**
- Add coffee2decide.com in ImprovMX (as pw2d.com) + its two MX records in DigitalOcean DNS → `hello@coffee2decide.com`
  forwards to Gmail. Sending *as* that address can wait; personal Gmail with a signature is fine at first.
- Search Console → Links → note the top linking sites (2 min).
- Ahrefs Webmaster Tools (free for verified sites, imports from Search Console) → backlink count + new-link alerts.

### Phase 1 — "Your product won" emails (starts now with semi-automatic, then follows every rebuild)

When a guide is FRESH, email the brands of its picks: the product was named best overall / premium / budget in our
guide, here is the page and a badge if they want to share it. No request for a link, no payment, no exchange.

- **First wave — semi-automatic (FRESH 10-05):** Dalla Corte (Mina), ECM (Mechanika Max II, Estetika), La Marzocco
  (GS3 MP, Linea Mini), Bezzera (BZ13), De'Longhi (Dedica Maestro Plus). For Italian brands, the US importer or
  distributor is often the better contact — Claude finds it per brand.
- **Next waves ride the overdue sweeps:** super-automatic (Philips, Jura, De'Longhi), manual grinders (1Zpresso,
  Kingrinder, Comandante, Wacaco, Timemore ×3), gooseneck (Cocinare, Cosori, Brewista, Fellow, Mecity, Bonavita),
  pour-over (Clever, Melitta, Fellow, Kalita, Zero Japan, OXO), cold brew (OXO, County Line Kitchen, Hario, Takeya,
  Yama, Service Ideas, Cuisinart). Small and mid brands answer; the big ones rarely do — same template either way.
- **Claude:** contacts, one email per brand, one static badge per role (image, no code). **Owner:** reads, sends
  (~5 min each).
- **Win:** a link from a brand's press/reviews page, or a social share that mentions us.

### Phase 2 — Answer reporters' questions (~3 × 15 min a week)

Free daily digests of journalists looking for sources: **Source of Sources** (free, started by HARO's founder),
**Featured** (relaunched the HARO brand in 2025; free tier; verifies identity), **Qwoted** (free tier, limited pitches).
Subscribe with the coffee address, answer only coffee / kitchen / home / shopping requests.

- **Claude:** filters the week's requests, drafts an answer grounded in our data (e.g. what a $1,500 machine gets you
  over a $500 one, from the semi-automatic pool). **Owner:** rewrites in his voice, sends.
- Needs a real name. Expect roughly one published quote per 10–20 answers; a published quote usually carries a link.

### Phase 3 — Be present where buyers ask (optional; only from real experience)

r/espresso, r/superautomatic, r/JamesHoffmann, r/Coffee, Home-Barista. Answer buying questions for 8–12 weeks with no
links; the site sits in the profile. After that, link a page only when it answers the exact question, and say "I run
this site". The likeliest source of the first referral visitors, and Reddit threads are what AI answers quote.

- **Claude:** a weekly list of open buying questions our data can answer, with the facts. **Owner:** writes the answer.
- Skip this phase if the owner does not use this gear himself — answers without experience read as marketing.

### Phase 4 — One page worth citing (month 3+, decided at the 3-month review)

Candidate: "What more money buys in an espresso machine" — 188 swept machines by price band, stated plainly as our own
scores. A price-trend piece (tariffs) needs price history recorded from now on; that is a separate decision, not part
of this spec. Possible outlets: coffee bloggers, r/dataisbeautiful (original data with a source is allowed there).

## Rhythm and measurement

- **Weekly (~2 h owner):** reporters' digests 45 min · brand emails after any rebuild 30 min · optional community
  answers 45 min.
- **Monthly, in `/seo-status`:** referring sites (Ahrefs Webmaster Tools + Search Console Links), non-search referral
  visitors (PostHog `$referring_domain`, query used for the baseline above), "coffee2decide" brand queries in GSC.
- **Log:** `docs/outreach/log.md`, one line per contact — date, organisation, what was sent, page, outcome. Organisation
  names only, no personal email addresses in git.

## Reviews

- **6 weeks (~2026-11-16, same day as the VS pilot gate):** ≥ 3 referring sites that are not directories or scrapers;
  ≥ 1 published quote or brand mention; the first non-search referral visitors.
- **3 months (~2027-01-04):** Phase 4 yes/no; is Phase 3 worth its hours; roll out to pw2d?

## Multi-tenant impact

Only Phase 0 touches code: a per-tenant About partial and a per-tenant contact address in tenant `data`, with the
current copy and address as the fallback, so pw2d is unchanged.

## Open questions (owner)

1. **Real name** on the About page and in pitches, or a pen name? Phase 2 needs a real one; Phase 1 works far better
   with one.
2. **Time:** is ~2 h a week realistic? If less, keep Phases 0–1 only.
3. **Experience:** does the owner make espresso / own any of this gear? Decides whether Phase 3 runs.
