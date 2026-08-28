# SEO Status Checkpoint — 2026-06-13

**Context:** First status check after Spec 022 reached prod (deployed 2026-06-07; the
2026-06-06 handoff had marked it shipped but prod was one commit behind — corrected this session).
This checkpoint exists so the **next check (~2026-06-20)** is a clean A/B against today's numbers.

## Verdict: the foundation is working

Every baseline metric improved vs the 2026-06-05 baseline, ahead of the 2–4 week expectation.

| Metric (28d GSC) | Baseline 2026-06-05 | **This check 2026-06-13** | Δ |
|---|---|---|---|
| Pages with impressions | 97 | **133** | +37% |
| Total impressions | 276 | **516** | +87% |
| Total clicks | 0 | **1** | first click ever |
| Avg position | 17.4 | **15.2** | +2.2 better |
| CTR | 0.00% | 0.19% | off zero |

**This row is the new number to beat on 2026-06-20.** One click is noise — the durable signal is
impression growth + position improvement. Tracks the "thesis is right" branch of the handoff decision tree.

## top_query insight (Spec 022 confirmed live)

`gsc_top_query` now populates correctly. The pattern in the data:

**Winners — preset compare pages matching high-intent NL queries:**
- "best mechanical keyboard for streamers" → `/compare/mechanical-gaming-keyboards?preset=streamer` — 27 impr, **pos 10.4**
- "best gaming headset for remote workers" → `/compare/gaming-chat-headsets?preset=remote-worker` — 25 impr, **pos 10.9**

This validates the "Compare with Intelligence" preset concept *as an SEO surface*. CTR is ~0 because
even the winners sit at ~pos 10 (page-1 bottom) — not yet click-earning. Pos 10 → ~5 is where clicks start.

**Isolated gap — same query class ranks badly for ONE category (productivity-ergonomic-keyboards):**
- "best ergonomic keyboards for programmers" → `?preset=programmer` — pos 57
- "best mechanical keyboard for rsi" → `?preset=rsi-sufferer` — pos 47.5
- "ergonomic keyboard for programmers" → pos 44

Parked as **F33** (spec only if the trend holds on 2026-06-20).

## Pipeline health

- GSC: HEALTHY (latest 2026-06-10, normal ~3-day lag; 213 rows/14d)
- GA4: reports **STALE** — but this is a **low-traffic false-positive**, not a failure (cron runs fine,
  GSC + top_query advancing, zero GA4 errors in log; GA4 only writes rows for URLs with sessions, so
  near-zero traffic days produce 0 rows and "latest date" lags). Tracked as **F34**.
- Cron hook present and firing. One transient `RedisException: Connection refused` at 2026-06-11 06:50
  (two log lines, self-recovered) — noted, no action.

## Decision this session

**Wait one more week** (chosen by owner). No on-page work until 2026-06-20 confirms the trend is durable.
If it holds, the data-driven first move is F33 (ergonomic preset content) + F31 (compare-page CWV weight),
both ranking levers aimed at pushing pos-10 / pos-44-57 pages up toward click-earning positions.

## Next-check (2026-06-20) opening moves

1. `php artisan pw2d:seo:status` on prod
2. Re-run the 28d aggregate query (see this session's history) — compare to the table above
3. Re-run the 14d top_query-by-impressions query — did the winners climb? did the ergonomic presets move?
4. If impressions/position still rising → green-light F33 + F31. If flat → reassess off-page (authority).

---

## UPDATE — 2026-06-19 check (trend CONFIRMED, acting)

| Metric (28d GSC) | 06-05 | 06-13 | **06-19** | Trajectory |
|---|---|---|---|---|
| Pages with impressions | 97 | 133 | **158** | ↑ |
| Total impressions | 276 | 516 | **928** | ↑↑ +80% wk/wk |
| Avg position | 17.4 | 15.2 | **13.7** | ↑ +1.5 |
| Clicks | 0 | 1 | 1 | flat |
| CTR | 0% | 0.19% | 0.11% | ↓ (impr grew, clicks flat) |

**Confirmed durable + accelerating.** Now firmly in the handoff's "impressions grow, CTR ~0%" branch.

**Crux:** "best mechanical keyboard for streamers" → `?preset=streamer` went 27→**133 impr (5×)** but
sits at **pos 10.4 with 0 clicks** — bottom of page 1, worst CTR real estate. The bottleneck is no longer
visibility; it's crossing **pos-10 → top-5**.

**Key architectural finding:** winning queries are preset-specific, but page *body content* is
category-level (meta is already preset-aware; content + FAQPage are not). → **Spec 023** (preset-aware
content depth) makes the body match the use-case query. **Spec 024** (F31 CWV) is the sequenced fast-follow.

**F33 (ergonomic gap) partially self-healed** — "best minimalist keyboard" pos 23→10.3; only rsi-sufferer
(pos 44) still lags. Subsumed by Spec 023.

**Decision (owner):** stop waiting, spec both (sequenced). Specs 023 + 024 drafted, awaiting approval to build.

### Next-check (~2026-07-03) — measure the bet
After 023 deploys, watch the streamer/remote-worker/minimalist queries: did position cross 10→top-5?
Did CTR move off 0%? That is the success criterion for the preset-content thesis.

---

## UPDATE — 2026-06-26 check (1 week post-023/025 deploy — TOO EARLY, pages holding not climbing)

| Metric (28d GSC) | 06-13 | 06-19 | **06-26** | Note |
|---|---|---|---|---|
| Pages with impressions | 133 | 158 | **191** | ↑ long tail expanding |
| Total impressions | 516 | 928 | **1,553** | ↑↑ +67% wk/wk |
| Clicks | 1 | 1 | **4** | quadrupled (still tiny) |
| Avg position | 15.2 | 13.7 | **17.1** | ⚠️ COMPOSITION ARTIFACT — 33 new pages entered at pos 34-44 and dragged the weighted avg; NOT a decline on target pages |
| CTR | 0.19% | 0.11% | **0.26%** | ↑ |

**Target preset queries (the thesis test) — HELD at ~pos 10, did NOT climb to top-5:**
- "best mechanical keyboard for streamers" `?preset=streamer`: 10.4 → 10.4 → **10.0** (129 impr, 0 clicks)
- "best gaming headset for remote workers" `?preset=remote-worker`: 11.0 → 10.8
- "best minimalist keyboard" `?preset=minimalist`: 10.3 → **10.0**

**Verdict: TOO EARLY.** Content ranking changes take 2-4 weeks to settle; 023 deployed 06-19 (1 week ago).
Real read is ~2026-07-03/10. Pages are holding on page-1-bottom; content alone hasn't broken the 10→5
plateau in a week. The plateau-at-exactly-10 across 3 distinct pages suggests the 10→5 jump needs the
OTHER levers (page experience / CWV / internal links / authority), not more content → **strengthens the
case to unhold Spec 024 (CWV)**.

### Next-check (~2026-07-03/10)
Same queries. If they cross 10→top-5 → 023 validated. If still stuck at 10 after 3-4 weeks → content is
not the lever for the climb; ship Spec 024 (CWV) and/or pursue internal-linking/authority. Either way,
impressions + indexation momentum (1,553 / 191 pages) remains strong and intact.

---

## UPDATE — 2026-06-27: Spec 024 (CWV) shipped; engagement read blocked; bottleneck decision

**Spec 024 deployed** (prod `170b405`): compare page renders 6 cards (was 12), schema decoupled so
ItemList stays 12; initial HTML 185KB→152KB (18%). PSI mobile: **Perf 81, LCP 3.1s, CLS 0.015, SEO 100**.
CLS 0.015 is excellent (skeleton footprint matched — no reveal shift). LCP barely moved (gated by
render-blocking + LCP image, not card count).

**Engagement check (PostHog, Jun 12-19 vs 19-26) = NOT READABLE.** Compare pages got 3→6 pageviews/week;
the handful of post-period engagement events (5 slider adjusts, 6 opens) are almost certainly the owner's
own QA, not real users. `preset_applied` instrumentation live but 0 events (low traffic). CONCLUSION:
on-site engagement can't be measured until clicks exist — the funnel is starved at the top (impressions
grow, but pos-10 → ~0 clicks → no compare visits). Engagement is GATED on the SEO climb.

**Bottleneck decision (owner, 2026-06-27): WAIT.** Do not chase LCP/CWV bottlenecks now (parked as F35).
Rationale: CWV is a tiebreaker; at pos-10 / low authority the real constraint is authority/off-page.
Let the Jul checkpoint decide. The full on-page push (023 content + 024 CWV + 025 UX) is now SHIPPED;
remaining levers if the climb stalls are off-page (authority/backlinks/landing pages), not more on-page code.

### THE decision point — next check (~2026-07-03/10)
1. Re-run the 3 target preset queries' positions (streamer / remote-worker / minimalist).
2. **Climb (10→top-5):** 023+024 worked → CWV/content validated; close F35; start measuring engagement (PostHog).
3. **Still stuck at 10:** on-page is not the lever → pivot to OFF-PAGE (authority/backlinks/landing pages);
   F35 (LCP pass) only if a focused page-experience push is wanted alongside, but authority is the bigger bet.

---

## UPDATE — 2026-07-03 check: AMBIGUOUS — small climb + impression collapse = mid-re-evaluation

**28d aggregate:** 209 pages / 1,849 impr / **6 clicks** / pos 16.4 / CTR 0.32%. Site-wide momentum intact
but decelerating (+19% wk vs +80% prior weeks). Pipeline fully HEALTHY (GA4 recovered on its own — F34 confirmed
as low-traffic artifact).

**Target queries — neither branch fired cleanly:**
| Query | Jun 19 | Jun 26 | **Jul 3 (14d)** |
|---|---|---|---|
| best mechanical keyboard for streamers | 10.4 | 10.0 | **9.1** — but impr 129→27 |
| best gaming headset for remote workers | 11.0 | 10.8 | **10.6** — impr 32→9 |
| best minimalist keyboard | 10.3 | 10.0 | fell out of top queries |

**Streamer page weekly trend (the tell):** wk23: 92 impr/pos 10.6 → wk24: 131/10.2 → **wk25: 20/12.4 →
wk26: 7/8.1**. Impressions collapsed ~90% right after the Jun 19 deploys (023 content + 025 reorder + 024
render change all hit the same day), while position on the REMAINING impressions went single-digit (8-9).

**Interpretation:** textbook post-change re-evaluation churn ("Google dance") — major page changes trigger
impression/ranking whiplash for 1-3 weeks. Position 8-9 on tiny volume is not a validated climb; impression
collapse right after a deploy is not a validated loss. TOO NOISY TO CALL.

**Secondary signal:** the 023 preset content opened NEW query surface for ergonomic/rsi (4+ query variants
now matching `?preset=rsi-sufferer` / `programmer`) — but at pos 37-47. Content creates the match; rank
still capped. Points the same direction as everything else: **authority is the emerging constraint.**

### Decision (2026-07-03)
- **Wait ~1 more week for the churn to settle** before final on-page verdict (re-check ~Jul 10-12).
- **Regardless of that verdict, begin off-page/authority planning NOW** — both branches of the fork point
  there, it's the long-lead-time work, and every remaining on-page lever is shipped. Off-page = backlinks,
  content marketing, possibly `/best-X-2026` landing pages (the one code-shaped piece).
- F35 (LCP pass) stays parked.

---

## UPDATE — 2026-07-10 check: VERDICT — STUCK. On-page is not the lever; pivot to off-page/authority.

**28d aggregate:** 222 pages / 2,040 impr / **7 clicks** / pos 16.2 / CTR 0.34%. Growth continues to
decelerate (+296 impr wk of Jun-26→Jul-3, +191 this wk). Pipeline: pw2d GSC + GA4 both HEALTHY.

**Trajectory table (extended):**
| Metric | 06-05 | 06-13 | 06-19 | 06-26 | 07-03 | **07-10** |
|---|---|---|---|---|---|---|
| Pages w/ impressions | 97 | 133 | 158 | 191 | 209 | **222** |
| Impressions | 276 | 516 | 928 | 1,553 | 1,849 | **2,040** |
| Clicks | 0 | 1 | 1 | 4 | 6 | **7** |
| Avg position* | 17.4 | 15.2 | 13.7 | 17.1 | 16.4 | **16.2** |

**Target pages, weekly (the verdict data):**
| Page | wk23 | wk24 | wk25 | wk26 | **wk27** |
|---|---|---|---|---|---|
| streamer (impr / wpos) | 92 / 10.6 | 131 / 10.2 | 20 / 12.4 | 14 / 9.0 | **7 / 10.7 (+first click!)** |
| remote-worker (headsets) | 37 / 10.7 | 26 / 10.8 | 1 / 11.0 | 0 | **0** |
| rsi-sufferer | 3 / 48.0 | 6 / 43.8 | 7 / 40.9 | 4 / 42.8 | **12 / 13.0** |

**Why this is now callable (3 weeks post-023/025, 2 post-024 — churn window over):**
1. Streamer never broke ~9 in 5 weeks of observation; impressions collapsed 131→7 and did NOT recover.
2. Remote-worker page lost impressions entirely (2 straight zero weeks) from a stable pos ~10.7 base.
3. rsi-sufferer — the counter-example that proves the rule: 023 content lifted it 48→13, and it is
   arriving at exactly the same ~10-13 band where the other two plateaued. Three independent pages,
   one ceiling. That is an authority cap, not a content gap.
4. All caveats acknowledged: weekly volumes are tiny (5-20 impr), single positions are noisy — but the
   direction (impression loss post-change, no recovery in 3 wks, shared ceiling) is consistent.

### Decision (2026-07-10)
- **VERDICT: pivot to OFF-PAGE/AUTHORITY.** Backlinks, content marketing, linkable assets;
  the one code-shaped play is `/best-X-2026` landing pages. Founder-led; Claude supports.
- **F35 (LCP pass): CLOSE as not-the-constraint** (pages didn't climb, but the blocker is authority —
  0.6s of LCP is not what caps pos 10 → pos 5 on a DA-nothing domain).
- **Optional parallel code track: Spec-027 — product-page content depth** (expand 2-sentence ai_summary
  into structured review; targets long-tail + the 128-page "Crawled – currently not indexed" rationing).
  Renumbered from "Spec-026 candidate" (026 = ItemList schema fix, shipped Jul 5).
- Engagement measurement stays blocked on click volume (7 total).

**coffee2decide (checked same day): PIPELINE BLOCKED — service account lacks permission on BOTH
GSC (`forbidden` on sc-domain:coffee2decide.com) and GA4 (PERMISSION_DENIED).** NO_DATA since connect.
The Jul-3 "smoke-tested OK" claim did not survive contact — either the grant was never saved on the
c2d property or it was made under a different property type. Owner action (5 min): GSC property →
Settings → Users → add `pw2d-seo-reader@pw2d-407419.iam.gserviceaccount.com` (Restricted is enough);
GA4 property → Access management → same address → Viewer. Then backfill:
`php artisan pw2d:seo:pull coffee2decide --gsc-window-days=10 --ga4-window-days=10`.
First readable c2d checkpoint stays ~Jul 25.

### C2D-1 RESOLVED (2026-07-12)
Owner granted GSC Restricted + GA4 account-level Viewer. GSC then worked immediately; GA4 still
PERMISSION_DENIED because the tenant's `ga4_property_id` was WRONG (`properties/15199060859` vs the
real property `properties/544169093` — read off the owner's Analytics admin URL). Fixed via tinker,
re-pulled. Both sources HEALTHY through 2026-07-10; zero days lost (GSC backfilled from Jul 3).
First c2d signal: 28 pages / 173 impressions / 0 clicks over Jul 3-10. Do NOT over-read; first real
checkpoint ~Jul 25.

---

## UPDATE — 2026-07-19 check: pw2d verdict unchanged (monitoring mode). coffee2decide FIRST READ — healthy ramp, entry positions 50-80, first click.

**Pipeline:** all 4 sources HEALTHY (both tenants).

### pw2d (monitoring only — Jul-10 authority verdict stands)
| Metric | 06-26 | 07-03 | 07-10 | **07-19** |
|---|---|---|---|---|
| Pages w/ impressions | 191 | 209 | 222 | **237** |
| Impressions | 1,553 | 1,849 | 2,040 | **2,175** |
| Clicks | 4 | 6 | 7 | **7** |

Deceleration continues (+135 impr/wk). Target queries: rsi-sufferer is now the largest preset surface
(28 impr "rsi keyboard") but drifted 13.0→14.4; streamer 10.7→11.6; everything oscillating in the same
10-15 band. **No reversal, no climb — the authority verdict stands. No new on-page specs.**

### coffee2decide — FIRST READ (data Jul 3–15)
Totals: 57 pages / 400 impr / **1 click** / wpos 57.7.
Weekly ramp: 16 → 205 → 179 (wk28 partial). Healthy crawl-in; at 2 weeks old it has MORE impressions
than pw2d had at its 06-05 baseline (276 over 28d).

**Query→page mapping is CORRECT out of the gate** (the intent architecture works):
| Surface | impr | entry pos |
|---|---|---|
| super-automatic espresso (8+ query variants) | ~170 | 54-65 |
| manual coffee grinders | ~51 | 77-83 |
| semi-automatic espresso | ~19 | 56-65 |
| product pages (Jura Z10, Melitta, Gaggia Velasca) | ~16 | **20-39** |

Notable: product pages enter at pos 20-39 — far better than compare pages (50-80). Long-tail product
queries are the soft entry point on a fresh domain (supports Spec-027-style product content depth later).

**Category-demand signal (for slow-coffee leaves #5-6):** super-automatic dominates demand by ~3x over
everything else; manual grinders solid #2. Early lean: the "Electric Burr Grinders" leaf (11 detached
seed products on hand) complements the espresso demand center. NOT final — re-read at ~Jul 25-Aug 1
with clicks/top_query maturing.

### Decisions (2026-07-19)
- pw2d: stay the course — off-page/authority is the active track (data studies, community, landing pages).
- coffee2decide: no action, let it crawl in. Next read ~Jul 25-Aug 1 doubles as leaf #5-6 selection.
- No new specs from this check.

---

## UPDATE — 2026-08-02 check: pw2d flat (as expected); coffee2decide CLIMBING HARD — weighted position 54→29 in four weeks, first 5 clicks.

**Pipeline:** all 4 sources HEALTHY. GSC data through Jul 30.

### pw2d (monitoring mode, authority verdict stands)
| Metric | 07-03 | 07-10 | 07-19 | **08-02** |
|---|---|---|---|---|
| Pages w/ impressions | 209 | 222 | 237 | **221** |
| Impressions (28d) | 1,849 | 2,040 | 2,175 | **2,199** |
| Clicks (28d) | 6 | 7 | 7 | **6** |

Fully plateaued — impressions flat, target presets oscillating pos 10-27 (rsi surface keeps widening:
"rsi keyboard" 44 impr / 12.4). Consistent with the Jul-10 verdict; the lever remains off-page.
No /best/ rows yet (pages published Aug 1; GSC lag).

### coffee2decide — the story this week
| wk | impr | clicks | wpos |
|---|---|---|---|
| 202626 | 16 | 0 | 54.1 |
| 202627 | 205 | 0 | 59.7 |
| 202628 | 354 | 1 | 50.4 |
| 202629 | 363 | 3 | **39.5** |
| 202630 (partial) | 255 | 1 | **28.9** |

28d: 167 pages / 1,177 impr / 5 clicks. **Weighted position improved ~25 points in 3 weeks while
impressions grew** — a genuine fresh-site climb, far steeper than pw2d's trajectory at the same age
(pw2d needed ~2 months to reach this impression level). First clicks arriving. The 023-content stack +
clean launch hygiene (slugs, sitemap, schema fixed pre-crawl) appear to be paying off on a domain with
no legacy baggage.

### Milestones this period
- 4 landing pages LIVE since Aug 1 (pw2d keyboards ×2, c2d super-auto + manual grinders — c2d pair
  published after the super-auto category sweep removed 4 semi-autos). Attribution marker: any /best/
  data + compare-page query shifts from wk31 onward trace to this launch.
- Spec 027 shipped (commit a35523f) incl. renewed/condition pipeline guards + sitewide tenant-color fix.
- Condition audits pending owner --ignore decision: pw2d 33, c2d 2.

### Decisions (2026-08-02)
- Next check ~Aug 9: first /best/ crawl read + remaining-categories batch decision (generate all,
  publish only credible pick tables).
- c2d leaf #5-6 selection: super-auto demand dominance persists; electric-burr-grinders lean holds.
  Decide at ~Aug 9 with another week of top_query data.
- pw2d: no on-page work; founder off-page track (data studies, outreach) is the active lever.

---

## UPDATE — 2026-08-09 check: /best/ pages crawled-in on BOTH tenants within 2–4 days, zero cannibalization. pw2d flat (verdict stands). c2d consolidating at wpos ~30, CTR 0.54%.

**Pipeline:** all 4 sources HEALTHY (GSC through Aug 6, normal lag).

### pw2d (monitoring mode — authority verdict stands)
| Metric | 07-10 | 07-19 | 08-02 | **08-09** |
|---|---|---|---|---|
| Pages w/ impressions | 222 | 237 | 221 | **239** |
| Impressions (28d) | 2,040 | 2,175 | 2,199 | **2,346** |
| Clicks (28d) | 7 | 7 | 6 | **3** |
| Weighted pos* | 16.2 | — | — | **18.4** |

*wpos worsening is the known composition artifact — new /best/ + widening rsi long-tail enter at pos 19–50.
Clicks 6→3 at these volumes is noise. Target presets: "rsi keyboard" still the widest surface (46 impr /
12.8), "streamer keyboards" 14 / 9.9 — same 10–15 band as the last 8 weeks. No reversal, no climb.

**First /best/ read (published Aug 1, GSC-submitted Aug 2):**
- Both pages impressing by Aug 3 (2–4 day crawl-in — the permanent-URL/sitemap plumbing works).
- `/best/productivity-ergonomic-keyboards`: 5 impr, pos 10–41, top query **"best productivity
  keyboards"** — a NET-NEW query neither preset page held. `/best/mechanical-gaming-keyboards`: 2 impr, pos ~26.
- **Cannibalization: NONE.** Every preset compare page kept its queries at unchanged positions; the /best/
  pages opened new "best X" head-query surface instead. Exactly the designed division of labor.

### coffee2decide — climb consolidating, clicks accumulating
28d: 197 pages / 1,468 impr / **8 clicks** / wpos 37.6 / **CTR 0.54%** (best CTR either tenant has posted).

| wk | impr | clicks | wpos |
|---|---|---|---|
| 202629 | 363 | 3 | 39.5 |
| 202630 | 385 | 1 | 29.8 |
| 202631 (4 of 7 days) | 366 | 3 | **31.8** |

Per-day impressions still growing (~55→~90/day); wpos consolidating around ~30 after the steep 54→29 run —
normal post-sprint digestion, not a stall.

- **`/best/super-automatic-espresso-machines`** crawling in at pos 70–98 on "best super automatic espresso
  machine" — the compare page holds the same query at ~61. Watch which URL Google settles on; /best/ is the
  intended winner. Manual-grinders /best/ page: no rows yet.
- **Product pages are the strongest surface:** 447 impr / wpos 21.5 / 3 clicks in 14d — long-tail model-number
  queries (ecam35075si, psa3228/41 @ pos 7.9, ep2330/10 @ 11.0, Snap cold brew @ 10.8). **Strongest evidence
  yet for Spec-028 (product content depth).**
- **Demand by surface (14d):** super-auto 132 · manual grinders 83 (+1 click) · semi-auto 44 · cold-brew 18
  (but its PRODUCTS pull big impressions: Snap 5-gal pos 10.8–12.6, OXO 64.9) · pour-over 18 · kettles 7
  (tiny volume but pos 9.3).

### Decisions (2026-08-09)
- **Remaining-categories batch: recommended GO.** The gate data is in: /best/ pages index fast, open net-new
  query surface, and cannibalize nothing. Generate all 7 remaining categories (pw2d mics/headsets/lavalier;
  c2d semi-auto/kettles/pour-over/cold-brew), publish only credible pick tables (<5 picks = command aborts).
  Owner reviews drafts in Filament before publish, per runbook.
- **c2d leaf #5–6: electric-burr-grinders lean CONFIRMED** — grinders are the #2 demand surface with a click,
  and 11 seed products are detached and waiting. Second slot still open (no new demand signal for a specific
  6th leaf; cold-brew/pour-over already exist as leaves).
- **Spec-028 (product content depth) case keeps strengthening** — gated behind the evaluateProduct grounding
  guardrail + ~32 polluted ai_summaries cleanup.
- pw2d: authority verdict unchanged; founder off-page track remains the lever.

---

## UPDATE — 2026-08-17 check: coffee2decide BREAKOUT (best week on every axis). pw2d clicks 3→10 but **product pages, not preset pages** — authority verdict stands. Cannibalization watch CLEARED.

**Pipeline:** all 4 sources HEALTHY. GSC through 2026-08-14 (normal 3-day lag), GA4 through 08-16.
Week 202632 is **6 of 7 days** — per-day rates below are adjusted where it matters.

### The headline finding: product pages are the click engine on BOTH tenants

28d by surface:

| Tenant | Surface | Pages | Impr | Clicks | wpos | CTR |
|---|---|---|---|---|---|---|
| c2d | **product** | 194 | **1,289** | **10** | **18.8** | 0.78% |
| c2d | compare | 24 | 770 | 5 | 45.1 | 0.65% |
| c2d | best | 1 | 51 | 0 | 59.2 | 0% |
| pw2d | **product** | 232 | **1,790** | **8** | **17.8** | 0.45% |
| pw2d | compare | 15 | 564 | 1 | 22.9 | 0.18% |
| pw2d | best | 2 | 31 | 1 | 38.4 | 3.23% |

**Product pages = 68% of impressions, 72% of clicks, and the best weighted position on both tenants.**
Third consecutive checkpoint where this strengthens; it is no longer a hint. Every pw2d product click
came from a model-number query at pos 4.7–8.8 (`aoc gk330` ×4 clicks, `ymdk sofle`, `ymdk corne4x6`,
`mistel md600`). Same on c2d (`kingrinder k7 review`, `mhw-3bomber r3 pro review`, coletti crag ×3).

### coffee2decide — breakout week
| Metric | 07-19 | 08-02 | 08-09 | **08-17** |
|---|---|---|---|---|
| Pages w/ impressions | — | 197 | 197 | **221** |
| Impressions (28d) | — | 1,468 | 1,468 | **2,112** |
| Clicks (28d) | 1 | 5 | 8 | **15** |
| Weighted pos | 54 | 37.6 | 37.6 | **29.3** |
| CTR | — | 0.34% | 0.54% | **0.71%** |

| wk | impr | clicks | wpos | days |
|---|---|---|---|---|
| 202630 | 385 | 1 | 29.8 | 7 |
| 202631 | 564 | 5 | 30.7 | 7 |
| 202632 | **839** | **6** | **23.7** | 6 |

~140 impr/day vs ~80 the week before, and wpos broke below 25 for the first time. Best week on
impressions, clicks, and position simultaneously. Not a composition artifact — the compare and
product surfaces both improved.

### pw2d — clicks moved, but not on the target pages
| Metric | 07-19 | 08-02 | 08-09 | **08-17** |
|---|---|---|---|---|
| Pages w/ impressions | 237 | 221 | 239 | **251** |
| Impressions (28d) | 2,175 | 2,199 | 2,346 | **2,402** |
| Clicks (28d) | 7 | 6 | 3 | **10** |
| Weighted pos | — | — | 18.4 | **19.2** |

**Target preset queries — unchanged for a ninth week.** Zero clicks across all of them.

| Query | Path | Impr | Pos (08-17) | Pos (prior) |
|---|---|---|---|---|
| rsi keyboard | ergonomic?preset=rsi-sufferer | 15 | 12.5 | 12.8 |
| streamer keyboards | gaming?preset=streamer | 8 | 11.3 | 9.9 |
| best ergonomic keyboard for programmers | ergonomic?preset=programmer | 8 | 14.8 | — |
| pro gaming keyboards | gaming?preset=pro-gamer | 8 | 35.6 | — |
| best mechanical keyboard for rsi | ergonomic?preset=rsi-sufferer | 5 | 15.7 | — |

Still the same 10–15 band held since June. **The Jul-10 authority verdict stands for compare pages** —
the 3→10 click recovery is entirely product + /best/, not the presets.

**First /best/ click on pw2d:** `/best/mechanical-gaming-keyboards`, query **"best keyboards for gaming"**
@ pos 11.0 (2026-08-12). The /best/ surface also holds "best gaming keyboards" @ 9.0 and "best mechanical
keyboards 2026" @ 16.7 — head queries no preset page ever reached. 3.23% CTR on tiny volume, but the
designed division of labor is producing.

### Cannibalization watch (opened 2026-08-09) — **CLEARED, no action**
`/compare/super-automatic-espresso-machines` weekly, six weeks:

| wk | impr | wpos |
|---|---|---|
| 202627 | 100 | 63.1 |
| 202629 | 96 | 53.5 |
| 202631 | 87 | 50.8 |
| 202632 | 92 | **48.3** |

The compare page held its volume and its position *improved* 63→48 across the whole /best/ launch
window, while `/best/super-automatic-espresso-machines` added 51 impressions of separate surface
(pos 39–77). Both URLs coexist on the query cluster with no measurable transfer. Watch closed.

### The 8 newer /best/ pages have zero GSC rows — too early, but manual-grinders is on notice
Only the 3 original pages (published Aug 1) have ever impressed. Sitemaps verified correct — all 11
`/best/` URLs present on both domains. The 8 published after the Aug-9 GO have had ~3–5 eligible days
against a 3-day lag, so **not yet a finding**. Exception worth tracking: `/best/manual-coffee-grinders`
has been live since ~Aug 1 with **zero rows in 13+ days**, on c2d's #2 demand surface. Re-check Aug 23;
if still empty while its siblings index, that's a page-level indexing problem, not crawl latency.

### Compare-page cleanup impact — unreadable this week (baseline recorded)
The unbuyable-product filter shipped ~Aug 12–16; GSC ends Aug 14. pw2d weekly impressions for the
affected categories, for next week's diff:

| wk | headsets | mics | lavalier |
|---|---|---|---|
| 202629 | 12 | 111 | 16 |
| 202630 | 9 | 123 | 6 |
| 202631 | 7 | 80 | 6 |
| 202632 (6d) | 5 | 79 | 4 |

Headsets and lavalier were already drifting down *before* the cleanup, so attribution will be muddy.
Judge on 202633–202634.

### Decisions (2026-08-17)
- **Spec-028 (product content depth) is now the highest-leverage unbuilt work.** Three checkpoints of
  converging evidence: product pages own the impressions, the clicks, and the best positions on both
  tenants, and every click is a model-number query landing at pos 4–9. Unblock it — the gate is the
  `evaluateProduct` grounding guardrail + the ~32 polluted `ai_summaries`. Recommend specifying it next.
- **Orphaned `/product/{slug}` decision is now urgent, and the data says NOINDEX not exclude.** Product
  pages are the top click surface, so an orphaned page with no CTA converts a hard-won click into a dead
  end. `noindex` while unbuyable (auto-clearing on a clean rescan) preserves the surface for products
  that come back; sitemap exclusion alone leaves the page indexed and clickable. **Owner call still.**
- **pw2d compare pages: authority verdict unchanged.** No new on-page specs for presets. Founder off-page
  track remains the lever.
- **c2d: no intervention.** It is compounding on its own; do not perturb it mid-climb.
- Cannibalization watch closed.

### Next check (~2026-08-23/24)
1. Did the 8 new `/best/` pages index? Specifically `/best/manual-coffee-grinders`.
2. Compare-page cleanup effect on headsets/mics (weeks 202633–34 vs the baseline above).
3. c2d — does 202633 hold ~140 impr/day, or was 202632 a spike?
4. First PostHog engagement read is now viable on c2d (15 clicks in 28d clears the floor).

---

## UPDATE — 2026-08-24 check: c2d preset-compare pages START EARNING CLICKS — the surface pw2d's authority verdict left for dead. pw2d slipped on every axis. **Positive confirmation of the authority thesis.**

**Pipeline:** 4/4 HEALTHY. GSC through 2026-08-21 (normal 3-day lag), GA4 through 08-23.
Week 202633 is **6 of 7 days** on both tenants — per-day rates used where it matters.

### The headline: the preset architecture works. It just needs a domain that ranks.

The Jul-10 verdict ("on-page is not the lever; pivot to authority") was inferred from a *negative* —
pw2d presets plateaued at pos 10-15 and never converted. This week supplies the **positive** control.
c2d's preset-compare pages sit at pos 6-22 and **earn clicks**:

| Surface | impr | clicks | pos |
|---|---|---|---|
| `/compare/manual-coffee-grinders?preset=beginner` | 6 | **2** | 13.7 |
| `/compare/gooseneck-kettles?preset=tea-drinker` | 2 | **1** | 6.5 |
| `/compare/manual-coffee-grinders?preset=traveler` | 2 | **1** | 13.5 |
| `/compare/pour-over-drippers-brewers` | 2 | **1** | 3.0 |
| `/compare/manual-coffee-grinders` ("compare coffee grinder online 193") | 6 | **1** | 16.8 |

**6 of c2d's 21 clicks came from the compare surface** (CTR 0.79%) versus **1 click on pw2d's entire
compare surface across 495 impressions** (CTR 0.20%). Same code, same preset design, same schema —
opposite outcomes, separated by rank. Specs 023/024/025 were not wasted; they were shipped onto a
domain that could not rank them. **Do not re-open on-page work for pw2d presets on the strength of
this** — the read is that the on-page work is already correct and waiting on authority.

### pw2d — down on every axis
| Metric | 07-10 | 07-19 | 08-02 | 08-09 | 08-17 | **08-24** |
|---|---|---|---|---|---|---|
| Pages w/ impressions | 222 | 237 | 221 | 239 | 251 | **271** |
| Impressions (28d) | 2,040 | 2,175 | 2,199 | 2,346 | 2,402 | **2,367** |
| Clicks (28d) | 7 | 7 | 6 | 3 | 10 | **12** |
| Weighted pos | 16.2 | — | — | 18.4 | 19.2 | **20.2** |
| CTR | 0.34% | — | — | — | — | **0.51%** |

Weekly per-day impressions: 202632 **101/day** → 202633 **73/day** (−28%). First genuine per-day
decline since the series began; page count still rising, so this is thinning per-page demand, not
de-indexation. Clicks 10→12, still essentially all product pages (`aoc gk330` 188 impr / 4 clicks
@ pos 7.5 is 8% of the tenant's impressions by itself).

**Target preset queries — degraded, tenth consecutive week with zero clicks:**
| Query | 08-09 | 08-17 | **08-24** |
|---|---|---|---|
| rsi keyboard | 12.8 | 12.5 | **17.4** |
| streamer keyboards | 9.9 | 11.3 | **14.8** |
| best ergonomic keyboard for programmers | — | 14.8 | **18.6** |
| pro gaming keyboards | — | 35.6 | **35.6** |

Every tracked query moved *down* 2-5 positions. The 10-15 band held since June has become a 15-21
band. Consistent with authority erosion relative to competitors, not with a page-level regression.

### c2d — 202632 was NOT a spike; the climb is holding
| Metric | 08-02 | 08-09 | 08-17 | **08-24** |
|---|---|---|---|---|
| Pages w/ impressions | 197 | 197 | 221 | **229** |
| Impressions (28d) | 1,468 | 1,468 | 2,112 | **2,715** |
| Clicks (28d) | 5 | 8 | 15 | **21** |
| Weighted pos | 37.6 | 37.6 | 29.3 | **26.6** |
| CTR | 0.34% | 0.54% | 0.71% | **0.77%** |

| wk | impr | clicks | wpos | days | per-day |
|---|---|---|---|---|---|
| 202631 | 564 | 5 | 30.7 | 7 | 81 |
| 202632 | 994 | 7 | 24.2 | 7 | **142** |
| 202633 | 807 | 8 | 25.1 | 6 | **135** |

**Answer to last check's Q3: HOLDING.** 135/day vs 142/day is flat, not a retreat. Clicks rose 7→8
on a shorter week. wpos steady at ~25. Product pages remain the engine (1,736 impr / 14 clicks /
wpos 17.7), but compare is now genuinely contributing.

### Q1 — the 8 `/best/` pages: STILL ZERO ROWS. Cause narrowed; it is not a page-level block.
Only **3 of 11** `/best/` pages have ever recorded a GSC row:

| Tenant | Page | created | first row | impr | clicks | wpos |
|---|---|---|---|---|---|---|
| c2d | super-automatic-espresso-machines | 08-01 | 08-02 | 91 | 0 | 53.1 |
| pw2d | mechanical-gaming-keyboards | 08-01 | 08-03 | 24 | 1 | 44.8 |
| pw2d | productivity-ergonomic-keyboards | 08-01 | 08-03 | 19 | 0 | 33.3 |

`/best/manual-coffee-grinders` is now at **23 days with zero rows** — it was put "on notice" on 08-17
and has failed the re-check. The other 7 (created 08-09) are at 15 days.

**Ruled out this session:** HTTP 200 on all sampled pages; all 11 present in both sitemaps; canonical
is self-referential and correct; no `noindex` meta; robots.txt clean. So this is **not** a page-level
block — it is crawl/indexation rationing, the same constraint behind pw2d's 128 "Crawled – currently
not indexed" pages.

**New structural finding — the `/best/` pages are near-orphaned.** Verified by fetching rendered HTML:
neither homepage links to any `/best/` URL, on either tenant. The only internal link found was
`/compare/manual-coffee-grinders` → `/best/manual-coffee-grinders` — i.e. **one inbound internal link
per page, from its own sibling compare page**, which itself sits at wpos 45. This does not by itself
explain why super-auto indexed and manual-grinders did not (both are linked the same way), so it is
not proven as *the* cause — but a one-link, no-homepage-path page is the weakest possible crawl
signal, and it is the only lever here that is code-shaped rather than authority-shaped. Filed as F36.

### Q2 — cleanup impact on headsets/mics: UNREADABLE, and the 08-17 baseline was wrong
The baseline table recorded on 08-17 **does not reproduce**. Actual GSC impressions today:

| wk | headsets (recorded → actual) | mics (recorded → actual) | lavalier (recorded → actual) |
|---|---|---|---|
| 202629 | 12 → 9 | **111 → 4** | 16 → 16 |
| 202630 | 9 → 7 | **123 → 3** | 6 → 6 |
| 202631 | 7 → 6 | **80 → 2** | 6 → 6 |
| 202632 | 5 → 3 | **79 → 2** | 4 → 4 |

Lavalier matches exactly and headsets is within GSC's normal historical revision, but **mics is wrong
by ~25×**. Most likely cause: the 08-17 query matched `%mic%`, which catches every *microphone product
page* slug, not the `podcast-studio-mics` compare page. **The planned cleanup diff cannot be run against
that row.** What is readable: all three compare pages are 2-3 impressions/week and were declining
*before* the Aug 12-16 cleanup. At this volume no attribution is possible now or later — **close the
cleanup-impact watch as unmeasurable** rather than carrying it forward.

### Q4 — PostHog engagement read: BLOCKED on a dead credential
c2d's 21 clicks clear the volume floor, so the read was attempted. `POSTHOG_PERSONAL_API_KEY` in the
local `.env` is well-formed (`phx_` prefix, 52 chars) but PostHog returns `authentication_failed` on
both `us.posthog.com` and `app.posthog.com`. The key has been revoked or expired. **Owner action:
mint a new personal API key** (PostHog → Settings → Personal API keys) and update local `.env`.
Engagement remains unmeasured — now for a credential reason, not a traffic reason. Filed as F37.

### Unplanned finding — all 5 pw2d landing pages are STALE (`selection_drift`), all 6 c2d pages FRESH
`pw2d:landing-pages:audit` at 2026-08-24 13:02 flags every pw2d page and no c2d page. pw2d pages were
last generated 08-14/16; c2d's were regenerated 08-21.

**Verified genuine drift, NOT the H-A phantom-drift failure mode.** Audit finding H-A predicts that a
`modelKey()` false-merge can drop a pool below `MIN_PICKS`, make `execute()` throw, and leave a page
stamped `selection_drift` forever with nothing actually wrong. Checked directly against prod for
`mechanical-gaming-keyboards`: `SelectLandingPagePicks::execute()` returns **7 picks without throwing**,
and 2 of the 7 stored picks genuinely differ (slots 5 and 6: `2742→2799`, `2691→2722`). So the pages
are really 10 days out of date. H-A remains open and unrelated to this.

**Do not regenerate these pages yet.** Per the standing response rule, a drift signal detects but does
not authorise a rebuild, and pw2d's pool is unverified — weekly pick verification has *never* been run
for this tenant. Correct order is verify → sweep → rescan → regenerate.

### Decisions (2026-08-24)
- **pw2d authority verdict: UNCHANGED, and now positively confirmed** by the c2d control. No new
  on-page specs for pw2d presets. Off-page/authority remains the only lever.
- **c2d: no intervention.** Compounding on its own; do not perturb mid-climb.
- **Cleanup-impact watch: CLOSED as unmeasurable** (2-3 impr/week surfaces).
- **`/best/` internal linking → F36**, the one code-shaped play available. Recommend a spec.
- **PostHog key → F37**, owner action, 5 minutes.
- **pw2d landing-page drift feeds the existing Tier-3 top-up run sheet** — verify picks first.

### Next check (~2026-08-31)
1. Did the pw2d per-day decline (101→73) continue, or was 202633 a short-week artifact?
2. Do c2d compare-page clicks repeat, or were the 6 a one-week cluster? This is the load-bearing signal.
3. Any GSC row at all on the 8 silent `/best/` pages — especially if F36 linking ships.
4. PostHog engagement read on c2d, if the key is replaced.
