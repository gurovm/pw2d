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
