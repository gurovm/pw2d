# Review: Spec 029 Phase B — Extension Rescan v2 (manifest.json, popup.html, popup.js, content.js, background.js)
**Date:** 2026-08-10
**Reviewer:** Code Reviewer (contract-sync pass, T4)
**Status:** Needs changes — **SHIP-WITH-FIXES** (4 blockers, all small contained patches; core contract is in sync)

Scope: every fetch in popup.js/background.js vs `routes/api.php`, `OfferIngestionController` (ingest + rescanList), `BatchImportRequest`, `ProductImportRequest`, `ListingHealth`, `ListingHealthService`, `OfferIngestionService`, `BatchImportController`, `ProductImportController`, plus the secondary checks from the task brief.

---

## Primary question: is the extension↔API contract exactly in sync?

**Yes, with three exceptions (B2, B3, B4 below).** Verified field-by-field:

| Extension call site | Server | Result |
|---|---|---|
| popup `GET /api/categories` | `ProductImportController::categories` → `{success, categories}` | ✓ |
| popup `GET /api/existing-asins?category_id=` | `existingAsins` → `{success, asins}` | ✓ |
| popup `POST /api/products/batch-import` (SERP array + single Amazon product) | `BatchImportRequest` + `BatchImportController` | ✓ (S2, S4 nits) |
| popup `POST /api/extension/ingest-offer` (non-Amazon batch loop + single) | `OfferIngestionController::ingest` | ✓ (S4 nit) |
| popup `GET /api/extension/rescan-list?category_id=` | `rescanList` → `{success, offers:[{offer_id, product_id, url, asin, last_scanned_at}]}` | ✓ — popup reads `data.offers`, background uses only `offer.url` |
| background rescan `POST /api/extension/ingest-offer` | `ingest` validation | ✓ names/types/nullability (B3 semantics gap) |
| background `POST /api/product-import` (legacy walker) | `ProductImportRequest` | ✗ dead code, payload would 422 (S1) |

Specific confirmations requested by the brief:

- **DB-stored URL in rescan POSTs:** confirmed — `background.js` `handleRescanExtract()` builds `url: offer.url` (the rescan-list value), NOT `product.url` (the tab's post-redirect URL), with an explicit comment. This guarantees the `where('url', $data['url'])` refresh branch matches.
- **Omitted-vs-null for non-Amazon:** confirmed — Clive/SCG/WLL extractors return no `condition`/`listing_flags` keys; `if (product.condition)` omits both from the payload; server `$data['condition'] ?? null` → `ListingHealthService::apply()` early-returns on null. **Absent is a true no-op.** The gap is `'unknown'`, which is NOT absent and NOT a no-op — see B3.
- **`reviews_count` null semantics:** `null` sent when genuinely absent; all three server paths use `array_key_exists(...) && !== null` and never coerce to 0. ✓
- **Auth headers:** `X-Extension-Token` + `X-Tenant-Id` present on all six fetch sites; match `VerifyExtensionToken` / `InitializeTenancyFromPayload` exactly. ✓
- **`detectListingHealth()` vocabulary:** returns only `new|renewed|refurbished|open_box|used|unknown` and flags only `high_price` — all within `ListingHealth::CONDITIONS` / `RECOGNIZED_FLAGS`; flags array length 0–1 satisfies `max:5` + `distinct`. ✓
- **Backward compatibility with an un-updated server:** no endpoint URL changed; no existing payload field changed meaning. New fields (`condition`, `listing_flags`) are additive — an old server's Form Requests simply don't validate them and `validated()` strips them (no 422). Rescan against an old server hits a routeless `GET` → 404 → the exact spec'd message ("This server does not support rescan yet"), checked before `res.json()` so the HTML 404 body never throws. SERP condition-marked titles are no longer client-skipped (B4 of the spec), but an old server's Spec 027 `ProductConditionGuard::matchesTitle` guard still skips them server-side — safe degradation. ✓

## Critical Issues (must fix)

- **B1 — Pause→Resume while a page is mid-load double-processes the same offer (double-count + silently skips the next one).** `background.js processNextRescan()` registers a fresh anonymous `chrome.tabs.onUpdated` listener per call and `pauseRescan()` never removes it (nor cancels the 60s watchdog). If the user pauses during a page load and resumes before `status: 'complete'`, `processNextRescan()` re-navigates the same tab and adds a SECOND listener; when the load completes, both listeners fire (independent `settled` closures), each schedules `extractRescanData()` → two POSTs for the same offer and two `advanceRescan()` calls → tallies double-counted and the following offer never visited. Related: `advanceRescan()` increments `index` even when `rescanRun.paused` is true, so the un-cancelled watchdog firing during a pause skips the current offer — violating "Resume retries the SAME offer." Fix: keep module-level refs to the active onUpdated listener + watchdog timer; clear both in `pauseRescan()` and at the top of `processNextRescan()`; guard the watchdog/advance on `paused`. (CAPTCHA-pause itself is safe: the robot response short-circuits before any tally/POST/advance, and load-complete has already consumed the listener.)

- **B2 — `extractReviewsCount(document)` can return a RELATED product's review count on zero-review pages.** `content.js`: the new product-page strategies (0a–0d) are correct, but when they miss — which is precisely the state of the 88 zero-review products B3 targets — the function falls through to the SERP-card heuristics (1–5) executed over the ENTIRE document. Amazon product pages nearly always contain "related products" carousels whose nodes match strategy 1 (`[aria-label*="rating"]` → "…2,567 ratings" of another product), strategy 4 (`span[data-rt]` — explicitly a carousel-card selector), and strategy 3 (bare-number review links). The server writes any non-null value to `amazon_reviews_count`, so this turns "honest null" into another product's count. Fix: after strategy 0d, `if (el === document) return null;` (card heuristics are card-scoped only), or scope the document path to the title/ACR region and exclude `[class*="sims"]`/carousel subtrees.

- **B3 — `condition: 'unknown'` clears `listing_flags` and stamps `health_checked_at` server-side; the extension sends it on unverified pages.** In `handleRescanExtract()`, `if (product.condition)` treats `'unknown'` as truthy and sends it; the popup single-import sends `condition: product.condition ?? null` (always, including `'unknown'`). `ListingHealthService::apply()`'s clean branch treats `unknown` + `[]` identically to an affirmative `new` + `[]`: it clears a previously stored `high_price` flag, overwrites `condition`, stamps `health_checked_at`, and dispatches `AuditLandingPageFreshnessJob` — off a page the extension explicitly could NOT verify (missing `#productTitle` / no buy-box after the 3s settle). Spec A2 reserves clearing for "a clean rescan" — the affirmative `new`. Simplest in-scope fix (extension): only send `condition`/`listing_flags` when `condition !== 'unknown'` — this collapses `'unknown'` into the server's documented "absent → no-op" and leaves the offer at the front of the rescan queue (correct: it wasn't checked). Alternative (server): treat `unknown` + empty flags as stamp-only. Pick one; today the two halves disagree on what `unknown` means.

- **B4 — Server-side vocabulary desync: `ProductConditionGuard::titleMarker()` output is fed to `ListingHealthService::apply()` but 4 of its 6 markers are not in the `ListingHealth` vocabulary.** (Phase A code, but it is exactly an extension↔API vocabulary desync and the rescan walk is its main trigger.) All three refresh paths do `$effectiveCondition = $condition ?? ProductConditionGuard::titleMarker($title)`. `titleMarker()` returns raw marker strings — `'renewed' | 'refurbish' | 'open box' | 'open-box' | 'pre-owned' | 'used'` — while `apply()` recognizes `NEGATIVE_CONDITIONS = ['renewed','refurbished','open_box','used']`. So `refurbish` / `open box` / `open-box` / `pre-owned` miss the negative check, fall into the CLEAN branch, and are stored verbatim as out-of-vocabulary `condition` values with the product NOT ignored and prior flags cleared. Trigger: any refresh whose payload lacks explicit `condition` — i.e. every non-Amazon rescan offer — with a marker in the raw title (e.g. a WLL "Refurbished Jura E8…" listing). Only `renewed`/`used` coerce correctly today, by coincidence. Fix: map marker → canonical condition at the coercion sites (or add `ProductConditionGuard::titleCondition()` returning `ListingHealth` vocabulary: `refurbish→refurbished`, `open box|open-box→open_box`, `pre-owned→used`), plus a test per path.

## Suggestions (recommended improvements)

- **S1 — Delete (or fix) the dead legacy batch walker; its payload now 422s.** Nothing sends `START_BATCH` or `SCRAPE_COMPLETE` anymore (popup's batch runs entirely in-popup), yet `background.js` still carries the whole tab-walking flow whose `POST /api/product-import` body is `{raw_text, image_url, product_url, external_id, category_id}` — `ProductImportRequest` requires `title` (and doesn't know `raw_text`/`product_url`), so any future rewiring breaks instantly with a 422. Side effect: the batch↔rescan mutual-exclusion guard on `START_BATCH` protects only this dead path — the LIVE popup-driven SERP batch is not blocked while a rescan runs (harmless today: no tab contention, endpoints/throttles distinct, but the guard's coverage is partly illusory). Removing the walker also retires `RESUME_BATCH`, `handleRobotDetected`, `handleNextPage`/`scanNextPage`, and the never-read `autoNextPageCheck`.
- **S2 — Single Amazon import drops `stock_status`.** `extractAmazonProduct()` supplies it, but popup.js's batch-import payload for the single-product path omits it, so the A1/F38 stock refresh never happens there (server fallback keeps the stale value forever). Add `stock_status: product.stock_status ?? null` to the `products[0]` object.
- **S3 — `\bused\b` title marker will false-positive "…can be used with…" titles into `condition: 'used'` → `is_ignored` (human-reversal only).** The server's own substring markers are broader still (`'used'` matches "Infused", "Focused"), so this is design-parity with Spec 027 — but rescan now heals TRUE raw titles at scale, where descriptive "used with/for" phrasing is common. Tighten the extension's `used` detection to parenthetical/leading forms (`(Used)`, `(Certified Used)`, leading `Used –`) and consider the same server-side.
- **S4 — Popup tallies/labels don't cover the new response actions.** (a) The non-Amazon batch loop counts only `created|matched|refreshed`+catch — `flagged_condition`/`skipped_condition` responses increment nothing, so the `x/y` progress line undercounts and stalls short of the total. (b) The single-import `actionLabels` map lacks `flagged_condition`/`skipped_condition`/`skipped_ignored`; worse, for the Amazon single-import path the response is BatchImportController's `{created, refreshed, skipped, flagged}` (no `action` key), so a condition-flagged listing displays "Queued for AI". Cosmetic but actively misleading during Phase C QA.

## Notes (no action required to ship)

- N1 — Manifest declares `alarms` and `scripting`; neither `chrome.alarms` nor `chrome.scripting` is used. Trim. The rescan's `setTimeout` delays die if MV3 kills the worker, but the persisted-run → restore-as-paused (`worker_restart`) design degrades gracefully to a manual Resume — acceptable; `chrome.alarms` would remove even that.
- N2 — `rescan-list` 404 handling conflates "old server" with `InitializeTenancyFromPayload`'s 404 (`Invalid tenant.`) — a typo'd tenant ID shows "deploy Spec 029 Phase A first".
- N3 — On worker restart the orphaned worker tab stays open (tabId correctly not persisted), and a run finishing while the popup is closed loses its final summary (`GET_STATUS` shows nothing after `finishRescan`).
- N4 — `rescan-list` GET omits `Accept: application/json` (all POSTs have it); low practical risk since `category_id` comes from the fetched list.
- N5 — `rescanList`'s `asin` field is the URL basename (garbage for non-Amazon offers); currently unused by the extension — fine, just don't build on it.

## Praise (what was done well)

- The `url: offer.url` decision — with the CRITICAL comment explaining why the tab's redirected URL must not be used — is exactly right and is the linchpin of the whole refresh contract.
- CAPTCHA flow is correctly ordered: robot check → surface tab → pause BEFORE any tally/POST/advance, so Resume genuinely retries the same offer (B1's race aside).
- `detectListingHealth()` keeps all selector/marker logic in one function per the spec's DOM-churn rationale, is a pure function of `doc` (fixture-testable), uses leaf-node exact-text matching to avoid review-text false positives, scopes `high_price` to the buy-box column, and only reports `new` when a buy-box affirmatively loaded.
- Backward compatibility was clearly designed, not accidental: additive-only payload fields, the 404 old-server check placed before JSON parsing, no endpoint or field-meaning changes anywhere.
- `rescanRun` persistence with tabId deliberately excluded, restore-as-paused on worker restart, and disjoint storage keys (`rescanRun` vs `extensionToken`/`tenantId`/`env`/`lastCategoryId`) — no collision between modes.
- SERP regression safety of the shared `extractReviewsCount()`: the new 0a–0d strategies are ID-anchored and inert inside SERP cards, and the null return is treated identically to the old 0 by the `!reviews_count` filter. The SERP call site is clean; only the document call site has the B2 gap.
- popup.html/popup.js element IDs match 1:1; the message-action inventory across popup↔background↔content is fully consistent (checked exhaustively).

## Fix tasks (for docs/tasks/todo.md, Spec 029 Phase B section)

- [ ] **029B-B1:** background.js — track and clear the active `onUpdated` listener + 60s watchdog in `pauseRescan()`/top of `processNextRescan()`; guard `advanceRescan()`'s index increment when paused. Prevents double-POST/double-count + offer skip on pause→resume mid-load.
- [ ] **029B-B2:** content.js — `extractReviewsCount()`: return `null` after strategy 0d when `el === document` (card heuristics 1–5 must never run document-wide). Prevents writing a related-product's count to zero-review products.
- [ ] **029B-B3:** decide `'unknown'` semantics — extension: omit `condition`/`listing_flags` when condition is `'unknown'` (rescan + single-import paths), OR server: `ListingHealthService` treats `unknown`+`[]` as stamp-only. Today an unverified page wipes `high_price` flags and triggers freshness audits.
- [ ] **029B-B4 (server, Phase A file):** map `ProductConditionGuard::titleMarker()` output to `ListingHealth` vocabulary at the three coercion sites (`refurbish→refurbished`, `open box|open-box→open_box`, `pre-owned→used`) — currently stored verbatim via the clean branch, product not ignored. + 1 test per ingestion path.
- [ ] **029B-S1..S4:** dead legacy walker removal (or payload fix), single-import `stock_status`, tighter `used` marker, popup tally/label coverage for `flagged_condition`/`skipped_condition`/`skipped_ignored`.

## Verdict

**SHIP-WITH-FIXES.** The contract is in sync on names, types, nullability, auth, URLs, and old-server degradation; the four blockers are each a few-line patch (B1 listener hygiene, B2 one guard clause, B3 one condition check on either side, B4 a marker map) and must land before the owner runs the ~1,070-offer Phase C walk — B1 corrupts run integrity, B2/B3/B4 corrupt data at exactly the scale the rescan is meant to heal.
