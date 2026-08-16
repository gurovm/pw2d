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

### First live run (2026-08-16) — two lessons

**Always run `pw2d:landing-pages:audit` after a picks pass.** The extension names only guides
carrying a *listing flag*; **price drift is invisible to it**. The first run named
gaming-chat-headsets (HyperX Cloud III flagged `high_price`, $60→$80 — it was the Best Overall
pick) but productivity-ergonomic-keyboards had *also* gone stale on a +31% move (Keychron K2 V3,
$65→$85) and reading the popup alone would have missed it. The popup's numeric `flagged` counter
is weaker still — it counts only product-level condition flags, so it showed "flagged 0" while a
real flag existed. The guides line covered for it; the counter undercount is **fixed in v1.7**
(action→bucket table below). The audit's sharper finding: the guides line only covered for it *by
luck* — it shared the same `flagged_offer_condition` blind spot, so a multi-store pick would have
gone unnamed too. Price drift remains invisible to the extension either way, so the
`pw2d:landing-pages:audit` habit above still stands on its own.

**Two repair paths, chosen by cause:**

| Cause | Response |
|---|---|
| Pick carries a listing flag (bad listing) | Full category rescan → regenerate → review → publish |
| Price drift only, picks clean **and** selection unchanged | Surgical patch: rewrite the affected pick's copy, re-stamp all `est_price_snapshot`s, clear staleness |

The surgical path must **assert stored picks still equal `SelectLandingPagePicks` output** and
refuse if they differ — otherwise it would paper over a real selection change. Used successfully
on the ergonomic page: one pick rewritten, seven snapshots re-stamped, no rescan and no re-author.

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

Flagged picks are reported by guide: each row's `landing_page_slug` is tallied into `results.flagged_guides` whenever the offer comes back with a condition verdict or a `high_price` / `unavailable` listing flag, and the summary renders it as `flagged: gaming-chat-headsets, usb-mics` (with a count when a guide has more than one). That is deliberately broader than the `flagged` counter beside it — the response rule keys off *any* bad-listing signal on a pick. The end-of-run line renders amber rather than green when any guide is named.

> **Superseded in v1.7.** As built in v1.5 the guide-naming set was `flagged_condition` / `skipped_condition` only, and the tally's `else` branch counted everything else as `updated`. Both had the same blind spot for `flagged_offer_condition`. See "Extension v1.7" below for the corrected mapping — the paragraph above describes the intent, the table below is the contract.

**Contract addendum (required by T1).** `/api/extension/ingest-offer` requires `category_id`, and a picks run spans categories, so **every `scope=picks` row must carry its own `category_id`** in addition to `landing_page_slug`. The extension preflights this and refuses to start with a naming error rather than walking ~100 pages that would each 422.

### T1 build notes (server, done 2026-08-16)

Implemented in `app/Http/Controllers/Api/OfferIngestionController.php` — `rescanList()` branches on `scope` (`nullable`, `Rule::in(['category','picks'])`, default `category`); `categoryScopeOffers()` is the untouched original query extracted verbatim; `picksScopeOffers()` + `pickProductSlugs()` are new. Two builder decisions the spec left open:

1. **`category_id` under `scope=picks` is ignored, not validated.** When `scope=picks`, `category_id` is dropped from the validation rules array entirely — any value (absent, a real id from a different category, a nonexistent id, a cross-tenant id) is accepted and has zero effect on the query. Chosen over "reject with 422" per the owner's stated preference: a stale client-side param can never silently narrow a picks sweep, and there's no 422 for the extension's "Verify live picks" button to work around. No `Rule::exists` runs on it in this mode, so a tenant-foreign id also can't be used as an oracle to probe another tenant's category IDs (it's simply never looked at).
2. **Multi-page pick tie-break: first landing page by `slug` ASC wins.** `pickProductSlugs()` queries `LandingPage::where('tenant_id', ...)->orderBy('slug')` and keeps only the first slug seen per `product_id` (`Collection::has()` guard in the reduce). Deterministic and stable across requests; arbitrary in the sense that "first alphabetically" has no special meaning — any deterministic tie-break would do. The offer row itself is never duplicated per pick-page.

Also (not spec'd explicitly, but required for internal consistency): `scope=picks` does **not** filter by `is_ignored`/`status` the way `scope=category` does — a pick that has drifted into ignored/pending state is exactly the kind of thing this pass exists to catch, not something to hide from the rescan. See `docs/questions.md` for the full write-up and the regression-test note re: the Spec 030 §B1 `LIKE` bug (not meaningfully reproducible on sqlite; mitigated structurally instead — `pickProductSlugs()` never issues a JSON `LIKE` query).

**Contract addendum honoured (resolved, not outstanding).** The T2 extension build (§"Extension UI as built" above) landed concurrently and correctly identified a gap this spec text didn't call out: `POST /api/extension/ingest-offer` requires `category_id`, and a `scope=picks` row can belong to any category, so each row needs its own. Added `category_id` (the offer's product's own category, via a `product:id,category_id` eager load — no N+1, never the ignored request param) to every `scope=picks` row. `landing_page_slug` and `category_id` together fully satisfy the extension's preflight check in `popup.js`, which already reads it defensively (`offer.category_id ?? rescanRun.categoryId`) — no extension-side change was needed once the server field landed.

**Decision: `scope=category` rows do NOT get `category_id` added, for symmetry or otherwise.** Considered and rejected — the client already supplied and therefore already knows `category_id` for every row in that scope (it's the very query param that produced them), so echoing it back on each row would be pure redundancy with no consumer. `categoryScopeOffers()`'s row shape stays byte-for-byte identical to the pre-Spec-031 response, which is also why its pre-existing tests needed zero changes.

Tests: `tests/Feature/RescanListControllerTest.php`, 14 new (19 total in the file), including one asserting every `scope=picks` row's `category_id` matches its own product's category (not just spot-checked). Full suite 580 passed/19 skipped → **594 passed/19 skipped**, no regressions.

### Extension v1.7 — 2026-08-16 audit fixes (review B3/B4, security M3)

Three extension-side defects found by the post-029b audit, all in the weekly picks path. Manifest 1.6 → **1.7**.

**B4 — the action→bucket mapping is now a table, not an `else`.** `flagged_offer_condition` (Spec 029 §A2b: a bad condition flags the *offer* while the product survives on a clean sibling listing) was new, unlisted, and fell through `else results.updated++`. It also never reached `noteFlaggedGuide()`. So the exact multi-store case that fix exists for reported `updated N · flagged 0` with **no guide named** — the `flagged_guides` line that the response rule above depends on had the same blind spot as the numeric counter it was introduced to cover for. On the 2026-08-16 first live run only the guide line caught a bad pick; for a multi-store product it would not have.

Every action string the three ingestion controllers and `OfferIngestionService` can return is now mapped explicitly, in `background.js::RESCAN_ACTION_BUCKET` (with a mirror for the SERP batch loop in `popup.js::SERP_ACTION_BUCKET`). **There is no "everything else is fine" default.**

| Server action | Source | Tally bucket | Names its guide? |
|---|---|---|---|
| `refreshed` | `OfferIngestionService` — existing offer re-scraped | `updated` | no |
| `created` | `OfferIngestionService` — new product created | `updated` | no |
| `matched` | `OfferIngestionService` — AI-matched to an existing product | `updated` | no |
| `queued_new` | `ProductImportController` — new product queued for AI | `updated` | no |
| `queued_rescan` | `ProductImportController` — existing product refreshed | `updated` | no |
| `flagged_condition` | bad condition, **no** clean sibling → product ignored | `flagged_condition` | **yes** |
| `flagged_offer_condition` | bad condition, a clean sibling survives → offer excluded only | `flagged_condition` | **yes** |
| `skipped_condition` | import refused outright, nothing written | `skipped` | **yes** |
| `skipped_ignored` | `ProductImportController` — product already ignored | `skipped` | no |
| *anything else* | a future/unknown server action | **`unknown_action`** | **yes** |

Three deliberate calls in that table:

- **`flagged_offer_condition` counts as flagged and names its guide.** The product is still visible, so it is tempting to treat it as benign — but the owner's response rule ("full category rescan of the named guide") is exactly what a store's listing going refurbished should trigger, whether or not a sibling saved the product.
- **`skipped_condition` names a guide without being counted as flagged.** Nothing was written, so it is not a flag; it still means a live pick is pointing at a condition-marked listing.
- **An unrecognised action gets its own bucket *and* names the guide.** Under-reporting is precisely what made B4 invisible for a week, so the fallback is deliberately noisy: a build that cannot classify a verdict says so rather than guessing "clean". `skipped_ignored` is the one bucket that does *not* name a guide — it reports a pre-existing ignore, not a new observation about the listing.

**B3 — a detached pick no longer aborts the run.** `scope=picks` applies no category filter by design, so a pick whose product was detached by an AI sweep arrives with `category_id: null`. The v1.5 preflight refused to start the *entire* ~100-offer run over one such row, with an error text ("Update the server first") pointing at a server that was behaving as specified. The preflight now **skips those rows individually, counts them, and walks everything else**; the count is passed to the background run so it survives the popup closing and appears in the end-of-run summary as `3 skipped: no category`. It aborts only when **no** row carries a `category_id` — the genuinely-outdated-server case the check was written for. This is correct whether or not the server also stops emitting such rows.

**Security M3 — navigation allowlist.** The walk drives a real tab to `offer.url`, a value that reached the DB from an ingestion payload; the server constrains neither scheme nor host, and this pass is an *unattended weekly walk of ~100 rows*. `background.js` now checks every URL before navigating: `https:` only, and host (after stripping a leading `www.`) in `amazon.com` / `clivecoffee.com` / `seattlecoffeegear.com` / `wholelattelove.com` — exactly the set `content.js::extractStoreProduct()` routes on, so a row outside it could never have been extracted anyway. Off-allowlist rows are counted as `blocked` and reported (`2 blocked: bad URL`), never silently dropped. The check is a single definition used by the shared `processNextRescan()`, so both walk modes are covered by construction and cannot drift.

Note this excludes `amazon.co.uk` / `amazon.de`, which are in the manifest's `content_scripts` matches but have **no** entry in `extractStoreProduct()`'s extractor map — a row on those hosts would have failed extraction and been counted as an error. It is now counted as `blocked` instead, which is a more honest label for the same non-outcome. If a UK/DE extractor is ever added, add the host here in the same commit.

**Summary line.** The four core buckets always render (a zero is information — `flagged 0` is what the owner reads to decide no follow-up is needed, so it has to be trustworthy). The three exception buckets render only when non-zero, so an ordinary clean run keeps its existing one-line shape:

```
updated 47 · flagged 0 · skipped 0 · errors 0
updated 44 · flagged 1 · skipped 0 · errors 0 · 3 skipped: no category — flagged: gaming-chat-headsets
```

### Owner QA — Verify Live Picks (T2, extension v1.5)

Run after both halves are deployed. The builder cannot do any of this: it needs the real server, real Amazon/store pages, and a real tenant's picks.

1. **Pre-check (revised in v1.7 — was "blocking").** Press **Verify Live Picks** with no category selected. The run now *starts* even if some rows lack a `category_id`, reporting e.g. `(3 skipped: no category)` in the start line and the final summary — those are detached picks, which is a real staleness finding worth following up in Filament, not a reason to lose the weekly pass. It refuses to start **only** if *no* row has a `category_id`, which means the T1 follow-up has not shipped: stop, fix the server, re-run.
2. **Coverage.** The run should start with roughly **~100 offers** for pw2d + c2d combined, spanning **all 11 guides**, with no category ever selected. Spot-check that URLs from more than one category go by in the worker tab.
3. **Pause/Resume.** Pause mid-run, close the popup, reopen it: the progress panel must come back showing *Verifying picks: x/y* and a Resume button. Resume and confirm the counter continues from the same offer (it re-does the current one — that is by design).
4. **Flagged pick names its guide.** When any pick comes back flagged, the summary line must name the slug, e.g. `flagged: gaming-chat-headsets`. Per the response rule above, the correct follow-up is a **full category rescan of that guide's category**, not a re-selection.
5. **Mutual exclusion.** With a picks run active, *Start Batch Import* must refuse ("Picks verification is running — stop it first."), and both Start buttons must be hidden.
6. **Record the duration** for T3.

## Explicitly out of scope here (remains in `docs/tasks/todo.md`)

`offer_id` targeting for duplicate rows, the Clive price-extraction gap, the `price_drift` threshold tuning, and a staleness worklist command. None block the cadence; all were logged during the rollout.

~~the popup flag-tally undercount~~ — **fixed in extension v1.7**, see the action→bucket table above. It turned out not to be cosmetic: the same blind spot reached `flagged_guides`, which is the line the response rule actually keys off.

## Task breakdown

- [x] T1 (builder): server `scope=picks` + tests. Done 2026-08-16, see "T1 build notes" above.
- [x] T2 (extension): picks mode in popup/background, version bump (1.4→1.5), spec QA step. Done 2026-08-16, see "Extension UI as built" above.
- [x] **T1 follow-up (server, blocks the first live run):** add `category_id` to every `scope=picks` row — done same day, see "Contract addendum honoured" above (`picksScopeOffers()` eager-loads `product:id,category_id` and maps it alongside `landing_page_slug`). +1 regression test.
- [x] T3 — **real duration recorded (2026-08-16).** First live picks run: coffee2decide, 47 offers, **~7 min** (≈9s/offer, faster than the 10s modelled). Extrapolated: pw2d 35 offers ≈ 5 min, so **~12 min for both tenants** vs the 17 min estimated above.
  - **The extension is tenant-scoped**, so a weekly pass is TWO runs (switch tenant in the popup between them), not one. The spec's "one walk across both tenants" wording was wrong; the work-list endpoint is correctly tenant-scoped and there is no cross-tenant mode.
  - Verified live on prod: pw2d returns 35 pick offers across 5 guides, c2d 47 across 6 (super-auto 9 and semi-auto 10, because those picks carry multiple store listings). Every row has `category_id` and `landing_page_slug`.
