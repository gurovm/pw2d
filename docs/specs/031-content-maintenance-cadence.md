# Spec 031 — Content maintenance cadence

**Status:** APPROVED (2026-08-16) · **Owner decisions:** weekly picks + monthly full cycle; quarterly discovery prioritising small pools; build the picks-only rescan mode first.

## Why

The 2026-08-12→16 rollout verified all 11 landing pages across both tenants and removed ~150 bad listings and 26 duplicate rows. That state decays: prices moved 9–40% within days during the rollout itself, listings sold out, and Amazon flagged high prices on live picks. Verification is a point-in-time claim, so it needs a routine rather than a one-off.

### The asymmetry this plan exploits

| | Products | Offers | Time @ ~10s/offer |
|---|---|---|---|
| Live picks (11 pages × 7) | 77 | ~100 | **~17 min** |
| Everything rescannable | ~940 | 1,003 | ~2.8 h |

The products readers actually click are 8% of the work. Verify those often; sweep the rest on rotation.

### Pool headroom (the discovery driver)

Ignore rates run 35–70%, so pools only shrink without new imports. Smallest first: grinders 33, kettles 37, cold-brew 40, super-auto 43 (**the c2d demand centre**), pour-over 59, lavalier 76, headsets 79, ergonomic 99, semi-auto 125, keyboards 162, mics 181. `SelectLandingPagePicks` aborts below 5 picks; quality degrades well before that.

## The cadence

**Tier 1 — weekly, ~17 min.** Walk every live pick across both tenants using the new picks-only mode. This protects click targets and affiliate links. Pair it with the existing weekly `/seo-status` check so it becomes one habit.

**Tier 2 — monthly full cycle, ~40 min/week.** Rotate 2–3 full categories per week so all 11 are swept within a month. Suggested rotation, heaviest weeks first while the habit is new:

- Week 1: mics (30 min), grinders (6 min)
- Week 2: keyboards (29 min), kettles (6 min)
- Week 3: semi-auto (36 min), cold-brew (7 min)
- Week 4: ergonomic (17 min), lavalier (13 min), headsets (14 min)
- Week 5 (or fold into 1): super-auto (9 min), pour-over (10 min)

**Tier 3 — quarterly discovery, prioritised by pool size.** SERP batch import per category, starting with pools under 45 (grinders, kettles, cold-brew, super-auto). New products flow through the AI Bouncer, and if one would change a ranking the nightly audit raises `selection_drift` on its page — so the loop closes itself without manual checking.

### The response rule (important)

A Tier 1 pass **detects**; it does not authorise a rebuild. When a pick is flagged, the page must not be re-selected from an unverified pool — re-selection would just pick the next unchecked listing. Correct response: **full category rescan (Tier 2, out of turn) → regenerate → review → publish.** This is the lesson from the 2026-08-12 headsets page, where 4 of 7 picks were bad and re-selection kept landing on unverified rows.

## Build — picks-only rescan mode

**Server.** `GET /api/extension/rescan-list` gains `scope=picks`:
- `category_id` becomes required **only** when `scope` is absent/`category` (keep today's behaviour and its tests intact).
- With `scope=picks`: return every offer belonging to a product that appears in any landing page's `picks` JSON for the current tenant — regardless of category, and including drafts (a draft is about to be published).
- Return **all** offers of those products, not just the best one: `best_offer` can move between stores, so each store's listing needs its own health check.
- Same tenant scoping, auth, `limit`, and `COALESCE(health_checked_at, updated_at)` ordering as today.
- Add `landing_page_slug` to each row so the extension's summary can say which guide a flagged pick belongs to.
- Picks JSON is filtered in PHP over the tenant's bounded landing-page set — never a `LIKE` against the JSON column (see the Spec 030 B1 lesson: MySQL normalises JSON with spaces, so `LIKE '%"product_id":N,%'` silently never matches).

**Extension.** A "Verify live picks" action alongside the category rescan — no category selector, reuses the existing walk, pause/resume, CAPTCHA handling, and tallies. Summary should name the affected guide for any flagged pick.

### Extension UI as built (v1.5, T2 — 2026-08-16)

A **Verify Live Picks** section sits directly under *Rescan Category* in the popup, with no selector of its own ("Weekly check of every pick on every guide — no category needed"). Both modes drive **one** background run and **one** progress panel: the panel's label reads *Rescanning* or *Verifying picks*, and both Start buttons hide while either run is active, so the two modes plus the SERP batch remain mutually exclusive in every direction (the batch checks `GET_STATUS.active`; `START_RESCAN` refuses whenever a run is active).

The walk itself is **parameterised, not forked** — `rescanRun.scope` (`'category' | 'picks'`) changes only the work-list URL (`?scope=picks` vs `?category_id=`), the wording, and the tally. Delay, watchdog, single reused worker tab, CAPTCHA auto-pause, generation-counter guards, idempotent advancement, and `chrome.storage` persistence are shared verbatim, so Pause/Resume/Stop and post-restart recovery behave identically in both modes.

Flagged picks are reported by guide: each row's `landing_page_slug` is tallied into `results.flagged_guides` whenever the offer comes back with a condition verdict (`flagged_condition` / `skipped_condition`) or a `high_price` / `unavailable` listing flag, and the summary renders it as `flagged: gaming-chat-headsets, usb-mics` (with a count when a guide has more than one). That is deliberately broader than the `flagged` counter beside it, whose known undercount is an out-of-scope item above — the response rule keys off *any* bad-listing signal on a pick. The end-of-run line renders amber rather than green when any guide is named.

**Contract addendum (required by T1).** `/api/extension/ingest-offer` requires `category_id`, and a picks run spans categories, so **every `scope=picks` row must carry its own `category_id`** in addition to `landing_page_slug`. The extension preflights this and refuses to start with a naming error rather than walking ~100 pages that would each 422.

### T1 build notes (server, done 2026-08-16)

Implemented in `app/Http/Controllers/Api/OfferIngestionController.php` — `rescanList()` branches on `scope` (`nullable`, `Rule::in(['category','picks'])`, default `category`); `categoryScopeOffers()` is the untouched original query extracted verbatim; `picksScopeOffers()` + `pickProductSlugs()` are new. Two builder decisions the spec left open:

1. **`category_id` under `scope=picks` is ignored, not validated.** When `scope=picks`, `category_id` is dropped from the validation rules array entirely — any value (absent, a real id from a different category, a nonexistent id, a cross-tenant id) is accepted and has zero effect on the query. Chosen over "reject with 422" per the owner's stated preference: a stale client-side param can never silently narrow a picks sweep, and there's no 422 for the extension's "Verify live picks" button to work around. No `Rule::exists` runs on it in this mode, so a tenant-foreign id also can't be used as an oracle to probe another tenant's category IDs (it's simply never looked at).
2. **Multi-page pick tie-break: first landing page by `slug` ASC wins.** `pickProductSlugs()` queries `LandingPage::where('tenant_id', ...)->orderBy('slug')` and keeps only the first slug seen per `product_id` (`Collection::has()` guard in the reduce). Deterministic and stable across requests; arbitrary in the sense that "first alphabetically" has no special meaning — any deterministic tie-break would do. The offer row itself is never duplicated per pick-page.

Also (not spec'd explicitly, but required for internal consistency): `scope=picks` does **not** filter by `is_ignored`/`status` the way `scope=category` does — a pick that has drifted into ignored/pending state is exactly the kind of thing this pass exists to catch, not something to hide from the rescan. See `docs/questions.md` for the full write-up and the regression-test note re: the Spec 030 §B1 `LIKE` bug (not meaningfully reproducible on sqlite; mitigated structurally instead — `pickProductSlugs()` never issues a JSON `LIKE` query).

**Contract addendum honoured (resolved, not outstanding).** The T2 extension build (§"Extension UI as built" above) landed concurrently and correctly identified a gap this spec text didn't call out: `POST /api/extension/ingest-offer` requires `category_id`, and a `scope=picks` row can belong to any category, so each row needs its own. Added `category_id` (the offer's product's own category, via a `product:id,category_id` eager load — no N+1, never the ignored request param) to every `scope=picks` row. `landing_page_slug` and `category_id` together fully satisfy the extension's preflight check in `popup.js`, which already reads it defensively (`offer.category_id ?? rescanRun.categoryId`) — no extension-side change was needed once the server field landed.

**Decision: `scope=category` rows do NOT get `category_id` added, for symmetry or otherwise.** Considered and rejected — the client already supplied and therefore already knows `category_id` for every row in that scope (it's the very query param that produced them), so echoing it back on each row would be pure redundancy with no consumer. `categoryScopeOffers()`'s row shape stays byte-for-byte identical to the pre-Spec-031 response, which is also why its pre-existing tests needed zero changes.

Tests: `tests/Feature/RescanListControllerTest.php`, 14 new (19 total in the file), including one asserting every `scope=picks` row's `category_id` matches its own product's category (not just spot-checked). Full suite 580 passed/19 skipped → **594 passed/19 skipped**, no regressions.

### Owner QA — Verify Live Picks (T2, extension v1.5)

Run after both halves are deployed. The builder cannot do any of this: it needs the real server, real Amazon/store pages, and a real tenant's picks.

1. **Blocking pre-check.** Press **Verify Live Picks** with no category selected. If the popup says *"Server returned N pick(s) without category_id"*, the T1 follow-up below has not shipped — stop, fix the server, re-run. This preflight is the intended behaviour, not a bug.
2. **Coverage.** The run should start with roughly **~100 offers** for pw2d + c2d combined, spanning **all 11 guides**, with no category ever selected. Spot-check that URLs from more than one category go by in the worker tab.
3. **Pause/Resume.** Pause mid-run, close the popup, reopen it: the progress panel must come back showing *Verifying picks: x/y* and a Resume button. Resume and confirm the counter continues from the same offer (it re-does the current one — that is by design).
4. **Flagged pick names its guide.** When any pick comes back flagged, the summary line must name the slug, e.g. `flagged: gaming-chat-headsets`. Per the response rule above, the correct follow-up is a **full category rescan of that guide's category**, not a re-selection.
5. **Mutual exclusion.** With a picks run active, *Start Batch Import* must refuse ("Picks verification is running — stop it first."), and both Start buttons must be hidden.
6. **Record the duration** for T3.

## Explicitly out of scope here (remains in `docs/tasks/todo.md`)

`offer_id` targeting for duplicate rows, the Clive price-extraction gap, the popup flag-tally undercount, the `price_drift` threshold tuning, and a staleness worklist command. None block the cadence; all were logged during the rollout.

## Task breakdown

- [x] T1 (builder): server `scope=picks` + tests. Done 2026-08-16, see "T1 build notes" above.
- [x] T2 (extension): picks mode in popup/background, version bump (1.4→1.5), spec QA step. Done 2026-08-16, see "Extension UI as built" above.
- [x] **T1 follow-up (server, blocks the first live run):** add `category_id` to every `scope=picks` row — done same day, see "Contract addendum honoured" above (`picksScopeOffers()` eager-loads `product:id,category_id` and maps it alongside `landing_page_slug`). +1 regression test.
- T3: after first live run, record actual duration in this spec so the cadence estimate is real rather than modelled.
