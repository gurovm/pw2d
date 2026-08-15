# Spec 029 — Extension Rescan v2: full-field refresh + listing-health detection

**Status:** DRAFT (2026-08-09, amended 2026-08-10; `unavailable` listing-flag implemented 2026-08-12; non-Amazon availability detection, extension v1.3, 2026-08-14; non-Amazon condition markers, extension v1.4, 2026-08-15) · **Bundles:** F37 (verify-condition mode), F38 (refresh drops image/stock), Q1 (offer unique-constraint, CONFIRMED firing in prod), reviews_count extraction fix, cleaned-raw_title blindness fix (found 2026-08-09), **Amazon "High price" buy-box label detection (owner, 2026-08-10)**.

> **Amendment 2026-08-10:** detection broadened from *condition* to *listing health*. Amazon's own
> buy-box "High price" warning ("We have recently seen better prices on Amazon or from other
> retailers") is DOM-only, like Renewed, and marks a listing users won't buy from — an affiliate
> dead end. Distinct handling: condition problems ignore the PRODUCT; a high-price label flags the
> OFFER (the product is fine; the listing is a bad deal today) and excludes it from landing-page
> pick eligibility only. Companion spec: 030 (landing-page freshness engine) handles the fallout
> when rescans invalidate published picks.
>
> **Amendment 2026-08-15 (server, three holes closed):** live owner QA on Whole Latte Love — which
> sells a refurbished line with "Refurbished" literally in the product title (e.g. "Refurbished
> Delonghi ECAM22110SB Magnifica XS") — surfaced that "condition problems ignore the PRODUCT" above
> was too blunt for a MULTI-STORE product. Product 3500 (De'Longhi ECAM22110SB) has a clean $670
> Amazon offer AND the $720 refurbished WLL offer; product 3648 (Gaggia Magenta Prestige) has ONLY
> the refurbished WLL offer. Three fixes, detailed in §A2 below: (1) an explicit payload
> `condition: 'new'` no longer overrides a negative title marker — the marker wins; (2) a negative
> condition now ALWAYS flags the OFFER, and ignores the PRODUCT only when no other clean,
> purchasable offer remains (product 3500 stays visible via Amazon; product 3648, with nothing else
> to fall back to, is still ignored exactly as before); (3) `Product::bestOffer` (and `best_price`/
> `affiliate_url`/`estimated_price`, which all derive from it) now excludes negative-condition and
> pick-excluding-flagged offers the same way `SelectLandingPagePicks`/`AuditLandingPageFreshness`
> already do, so a cheaper refurbished offer can never win the "best" slot over a pricier clean one.

## Why now (the data)

- **Offer staleness:** pw2d 651/652 offers >90 days old; c2d 0/418 fresher than 30 days (oldest 2026-03-29). Displayed estimated prices and `$$$` price-tier badges are built on March–April scrapes.
- **4 marker-blind renewed picks** found by manual QA across two sessions (#2704, #1638, #1655, plus polluted #2723). The live Amazon page is the only reliable condition source, and only the extension can read it (Amazon blocks server-side fetches — hard lesson #3).
- **Structural blindness found 2026-08-09:** some `product_offers.raw_title` values equal the *cleaned* product name — an import path stored cleaned titles, so the server-side title-marker guard can never fire on those rows. Rescan must re-store the true raw title.
- **`$$$` badge glut:** tier-3 share is 3–19% in most categories (fine) but prices are stale, so an unknown share of tier-2/3 badges are wrong. Semi-auto espresso's 49% tier-3 is genuine ($2k–6.6k machines) — rescan corrects data, it won't (and shouldn't) flatten real premium categories.

## Non-goals

- No `Offer.price`/`priceCurrency` in schema, no price sync scheduling, no PA-API (standing policies).
- No automatic un-ignoring: condition flags set `is_ignored`; reversal stays a human decision in Filament.
- No server-side fetching of Amazon pages, ever.

## Phase A — Server (deploy first; backward compatible with the current extension)

**A1. `OfferIngestionService` refresh semantics (F38 + Q1).**
- Replace the `ProductOffer::create()` path with `updateOrCreate` keyed on `(product_id, store_id)` — kills the confirmed prod `Duplicate entry` crash (Q1).
- On refresh (existing offer matched by URL or ASIN): update `scraped_price`, and when provided non-null: `image_url`, `stock_status`, `raw_title` (always overwrite with the incoming RAW title — this heals the cleaned-title rows), and `reviews_count` on the product (`null` when missing, never coerce to 0).
- Same refresh semantics in `BatchImportController` (ASIN-dedup branch) and `ProductImportController`.

**A2. Listing-health fields on ingest payloads.**
- Accept optional `condition` ∈ `new | renewed | refurbished | open_box | used | unknown` AND optional `listing_flags` (array; `high_price` + `unavailable` — the latter implemented 2026-08-12 after real "Currently unavailable" listings surfaced) on `POST /api/extension/ingest-offer` and both import endpoints. Absent → `unknown` / `[]` (today's behavior). Unknown flag strings still 422.
- Migration: `product_offers.condition` (string, nullable, indexed), `product_offers.listing_flags` (JSON, nullable), `product_offers.health_checked_at` (timestamp, nullable).
- On `renewed|refurbished|open_box|used`: set offer condition, **always** (2026-08-15 amendment below covers ignoring the product). `ProductConditionGuard` remains the single marker-source of truth for text checks; DOM-detected condition is a stronger signal layered on top.
- On `high_price`: store the flag on the OFFER only — the product stays visible (it's a fine product; today's listing is a bad deal). `SelectLandingPagePicks` excludes products whose best offer carries `high_price` from pick eligibility. Compare pages unchanged for now (informational value stands); revisit if CTR data says otherwise.
- On `unavailable` (2026-08-12): identical offer-level semantics — flag stored on the OFFER, product stays visible, clean rescan clears it, and pick eligibility is excluded via the shared `ListingHealth::PICK_EXCLUDING_FLAGS` intersection (`SelectLandingPagePicks` + `AuditLandingPageFreshness` `pick_ineligible` both read it). One extra side effect: when the payload carries the flag WITHOUT an explicit `stock_status`, `ListingHealthService` coerces the offer's `stock_status` to `out_of_stock` (a listing with nothing to buy IS out of stock); an explicit payload stock_status always wins.
- A clean rescan (no markers, no flags) clears `condition`→`new`, `listing_flags`→`[]`, stamps `health_checked_at` — flags are point-in-time listing state, meant to flip both ways.
- Landing-page fallout (picks going ineligible mid-publication) is Spec 030's job; this spec only guarantees the signals land in the DB.

**A2a. Offer-vs-product condition semantics (2026-08-15 amendment, closes a live-QA hole).** The original "a negative condition ignores the PRODUCT" rule above didn't account for a MULTI-STORE product — Whole Latte Love sells a refurbished line, and product 3500 (De'Longhi ECAM22110SB) has a clean $670 Amazon offer next to the $720 refurbished WLL one. Ignoring the whole product over one bad store's listing was itself a bug. Corrected rule, implemented once in `ListingHealthService::apply()`:
- A negative condition (`renewed|refurbished|open_box|used`) is **always** stored on the OFFER that reported it, regardless of what else the product has.
- The PRODUCT is ignored (`is_ignored = true`) **only when no other offer is "clean and purchasable"** — priced (`scraped_price` non-null and `> 0`), condition not in `ListingHealth::NEGATIVE_CONDITIONS`, and free of any `ListingHealth::PICK_EXCLUDING_FLAGS` flag. Response action `flagged_condition` (unchanged), landing-page-pick warning log unchanged (fires only on the transition into `is_ignored`).
- When a clean offer DOES survive elsewhere (e.g. product 3500's Amazon offer), the product stays visible and the response action is instead `flagged_offer_condition` — a new, distinct action so ingestion responses stay honest about what actually happened. Product 3648 (Gaggia Magenta Prestige), which has ONLY the refurbished WLL offer, still gets `is_ignored = true` exactly as the original rule intended — this amendment narrows the blast radius for multi-store products, it doesn't weaken the single-offer case.
- Reversal is still a human decision (unchanged non-goal): if a later rescan clears the negative condition that was driving an already-ignored product's `is_ignored = true`, the product is NOT auto-un-ignored — but `ListingHealthService` now logs a notice naming the product so the owner can find and manually review/un-ignore it in Filament, rather than the state going silently stale.

**A2b. Title-marker precedence on rescan (2026-08-15 amendment, closes a live-QA hole).** The three ingestion coercion sites (`OfferIngestionService`, `BatchImportController`, `ProductImportController` — all on the existing-offer/rescan branch, where a title marker is treated as condition EVIDENCE per Reviewer B2 below) used to do `$condition ?? ProductConditionGuard::titleCondition($title)`: an explicit payload `condition: 'new'` skipped the title check entirely. That's backwards for a store like WLL, whose refurbished listings put "Refurbished" directly in the title — a naive extractor reporting `new` (title found, page loaded fine) would store `new`, clear any prior flag, and stamp the listing verified-clean, which is worse than sending nothing. Single shared helper, `ProductConditionGuard::resolveEffectiveCondition($condition, $title)`, used identically at all three sites:
1. `$condition` already negative (`renewed|refurbished|open_box|used`) → wins outright, title never consulted.
2. `$condition` absent (`null`) → falls back to the title marker (unchanged prior behavior).
3. `$condition === 'new'` AND the title carries a negative marker → **the marker wins**.
4. Anything else (`'new'` with a clean title, or `'unknown'`) → the payload value is returned unchanged. `'unknown'` in particular keeps its stamp-only behavior (§A2's `unknown` rule) and is never overridden by a title marker.

**A2c. `Product::bestOffer` exclusion (2026-08-15 amendment, closes a live-QA hole).** `Product::bestOffer` (and everything that derives from it — `best_price`, `affiliate_url`, `estimated_price`) filtered out only null-`scraped_price` offers, so a cheaper refurbished/flagged offer could still win the "best" slot over a pricier clean one — linking a customer straight to a bad listing (or, via `best_price`, showing one offer's price while `affiliate_url` linked to a different one). Fixed to apply the same `ListingHealth::NEGATIVE_CONDITIONS` / `PICK_EXCLUDING_FLAGS` exclusion `SelectLandingPagePicks`/`AuditLandingPageFreshness` already use for pick eligibility. If every offer is excluded, `best_offer` is `null` exactly as it already was for an all-null-price product.

**A3. Rescan work-list endpoint.**
- `GET /api/extension/rescan-list?category_id={id}` (same token auth as existing endpoints): returns `[{offer_id, product_id, url, asin, last_scanned_at}]` for the category's non-ignored products, oldest first. Powers the extension's rescan walk; also subsumes the manual `--urls` table for F37.

**A4. Post-rescan tier recalc.**
- After a rescan updates prices in a category, dispatch the existing tier recalculation for that category (respect Q13: chunk, don't load unbounded). Corrected tiers fix wrong `$$$` badges automatically.

**A5. Tests.** Refresh-updates-all-fields, null-vs-0 reviews_count, updateOrCreate under duplicate fire, condition→flag path (incl. landing-pick warning), rescan-list tenant scoping + auth, tier recalc dispatch.

## Phase B — Extension (popup.js + content.js + background.js updated IN SYNC — CLAUDE.md rule)

**B1. `detectListingHealth()` in content.js** (product page DOM), returning `{condition, listing_flags}`:
- Condition: title parenthetical `(Renewed)`, the "Renewed" badge element, byline "Brand: Amazon Renewed" / "Visit the Amazon Renewed Store", "Refurbished"/"Open Box" markers. `new` only when the page affirmatively looks like a standard listing, else `unknown`.
- Flags: the buy-box "High price" label (heading text "High price" adjacent to the "Learn more" disclosure in the buy-box column) → `high_price`; the availability block stating "Currently unavailable" (`#availability` is the classic anchor, exact-leaf-text scan of the buy-box column as DOM-churn fallback) → `unavailable` — a FLAG, never a condition. Keep selector logic in this one function; Amazon DOM churn is expected. Unavailable pages still extract normally (`#productTitle` exists; price null, stock `out_of_stock`, reviews as usual) and POST like any other page — never skipped client-side, so their `health_checked_at` advances and they stop heading the rescan list.

**B1b. `detectStoreAvailability()` in content.js — non-Amazon availability (v1.3, 2026-08-14) + condition markers (v1.4, 2026-08-15).**

Motivating data: c2d has **116 rescannable non-Amazon offers** (86 wholelattelove.com, 30 clivecoffee.com).
Those extractors sent no `condition` and no `listing_flags`, so `ListingHealthService::apply()` returned
immediately: the offers refreshed price/title/image but never got `health_checked_at` stamped, and a
sold-out or discontinued listing was never flagged — it could still be selected as a landing-page pick.
The server side was already store-agnostic; only the extension needed to report.

- One shared helper `detectStoreAvailability(doc, titleFound)` returns `{condition, listing_flags}` in the
  same `ListingHealth` vocabulary as `detectListingHealth()`, wired into all three non-Amazon extractors
  (`extractCliveCoffeeProduct`, `extractSeattleCoffeeGearProduct`, `extractWholeLatteLoveProduct`). Each
  now also sends `stock_status` (`out_of_stock` when unavailable, else `in_stock`). Amazon path untouched.
- **`condition` reflects markers found on the page (v1.4, 2026-08-15 — corrects v1.3).** v1.3 shipped
  `condition: 'new'` for every page with a title, on the assumption that *"these are authorised dealers
  selling new goods"*. **Live owner QA disproved that assumption:** Whole Latte Love sells a refurbished
  line with "Refurbished" literally in the product title, e.g.
  `wholelattelove.com/collections/espresso-machines/products/refurbished-lelit-anna-espresso-machine`
  ("Refurbished Lelit Anna Espresso Machine"). Reporting `new` there is not merely imprecise, it is
  *actively harmful*: `new` is the clean branch, so it tells the server the listing was verified clean and
  CLEARS any prior condition flag. `detectStoreAvailability()` now scans for condition markers and reports
  `new` **only when a title was found AND no marker matched**.
- **Marker list + matching rules** — the single shared `conditionMarkerFromText()` (also used by the Amazon
  path) IS the list, mirroring `App\Support\ProductConditionGuard::TITLE_MARKERS` naming and mapping into
  the `App\Support\ListingHealth` vocabulary exactly: `renewed`→`renewed`, `refurbish*`→`refurbished`
  (incl. "Certified Refurbished"), `open box`/`open-box`→`open_box`, `pre-owned`→`used`, `used`→`used`.
  Bare "used" is guarded exactly as the Amazon path guards it — parenthetical (`(Used)`,
  `(Certified Used)`) or leading (`Used - Like New …`) only, so "…can be used with the Bambino…" never
  marks a listing. "Refurbished" counts **anywhere** in the title (WLL prefixes it). Marker sources, in
  order of trust: (1) the product title; (2) structured first-party condition — schema.org `itemCondition`
  (`Refurbished`/`Used`/`Damaged`/`NewCondition`, collected in the same single JSON-LD walk as
  `availability`) and `<meta property="product:condition">` (OG product namespace: new|refurbished|used),
  where a bare "used" token needs no positional guard; (3) product-type / badge / breadcrumb text (a
  "Refurbished" collection breadcrumb, a product-type label) — badges scoped to the product container so a
  related-products card can never fire, and any blob over 120 chars ignored.
- **`unknown` when no title was found** (partial/failed page) — never fabricate a report for a page that
  did not load; flags from such a page are withheld as untrusted. In practice the extractors already
  return `null` without a title, so an unverified page is never POSTed at all. A `new` report stays
  load-bearing: it is what lets the server stamp `health_checked_at` and CLEAR a stale `unavailable` flag
  when an item comes back in stock.
- **No `high_price`** — that is Amazon's own buy-box label with no first-party equivalent here.
- **`unavailable` uses LAYERED detection**, since live-page selectors can't be verified by the builder and
  a single brittle selector would rot silently. A POSITIVE match is always required — absence of evidence
  means in stock. (a) JSON-LD `application/ld+json` `offers.availability` (recursive key walk, so `@graph`
  / `offers[]` / nested `offers.offers[]` all work) — most reliable on Shopify-style stores and **decisive**:
  if ANY variant offer is purchasable the product is not unavailable; (b) `<meta property="product:availability">`
  / `og:availability`; (c) add-to-cart button state scoped to the product form, incl. a disabled ATC control
  — but only when its label doesn't still read "Add to cart", so a not-yet-hydrated theme can't read as sold
  out; (d) dedicated inventory elements + an exact-leaf-text scan, scoped so a related-products "Sold out"
  badge can never false-positive. Word separators are `[\s_-]*` throughout (`OutOfStock`, `sold-out`,
  `out_of_stock`).
- Price on a sold-out page follows the Amazon precedent only partially: `scraped_price` is nulled **only**
  when absent/placeholder (`<= 0`). A genuinely displayed price is preserved — Shopify stores normally keep
  showing the real price while sold out, and nulling it would destroy usable data (and, per the 2026-08-12
  incident, a null-priced flagged offer is the exact shape that broke pick eligibility).
- **No popup.js / background.js change needed** — re-verified for v1.4: `background.js` sets
  `payload.condition`/`payload.listing_flags` from whatever the extractor returned for ANY store, and
  `popup.js`'s non-Amazon branch spreads `{...product}`; both already strip an `unknown` condition
  (029B-B3) and both already label/tally a `flagged_condition` response (that path was built for Amazon
  and is store-agnostic). A non-Amazon `refurbished` report therefore flows end-to-end untouched.
  Original v1.3 verification: both POST paths already forward whatever
  `extractStoreProduct()` returns store-agnostically, already strip an `unknown` condition (029B-B3), and
  already tally a non-Amazon `unavailable` response as `updated` (the server returns `refreshed`, not
  `flagged_condition` — `unavailable` is offer-level and never sets `is_ignored`).

**B2. Rescan mode in popup.** Select category → fetch `/api/extension/rescan-list` → background.js walks each URL in a tab with a 3–5s polite delay (reuse batch-mode pause/resume/progress UI) → content.js extracts `{raw title, price, image_url, stock, reviews_count, condition}` via `extractProductPageData()` + `detectCondition()` → POST to `ingest-offer` per offer. CAPTCHA/robot page (existing `checkForRobot()`) pauses the run.

**B3. reviews_count 6th strategy.** Find a live URL where `extractReviewsCount()` returns 0 (88 known products), add the missing selector. Send `null` when genuinely absent.

**B4. SERP batch mode:** keep sending the untouched raw title; pass `condition` when the SERP title itself contains a marker.

## Phase B QA (owner)

Manual checklist — there is no JS test harness; `detectListingHealth()`, `conditionMarkerFromText()`
and `extractReviewsCount()` are pure functions of a Document/element so they can get fixture tests later.

1. **Load unpacked extension** (`chrome://extensions` → Developer mode → Load unpacked →
   `chrome_extension/`). Confirm version shows **1.4** and no manifest errors (no new permissions
   were added in 1.3 or 1.4 — availability/condition detection only reads the DOM of already-matched
   pages).
2. **Regression — batch import still works:** open an Amazon SERP → Scan Page for Products →
   Start Batch Import on a test category. Confirm created/refreshed counts and no console errors
   in the popup or service worker.
3. **Regression — single import still works** on one Amazon product page and one non-Amazon
   (Clive/SCG/WLL) product page.
4. **Rescan a small category:** select **gooseneck-kettles** (37 products) → Rescan Selected
   Category. Verify: progress counter advances, ~3–5s between page loads, one background worker
   tab is reused, Pause/Resume works, closing and reopening the popup restores the running state,
   and the final summary shows `updated / flagged / skipped / errors`.
5. **CAPTCHA path:** if Amazon serves a robot check mid-run, the run must auto-pause with
   "solve the captcha in the tab" and Resume must retry the same offer.
6. **Filament verification:** after the rescan, confirm in Filament that (a) known renewed/
   refurbished listings show `condition` set and product `is_ignored = true`, (b) any buy-box
   "High price" listing carries `listing_flags = ["high_price"]` on the OFFER only (product still
   visible), (c) every rescanned offer has a fresh `health_checked_at` stamp, and (d) clean
   listings show `condition = new`, `listing_flags = []`.
7. **reviews_count spot-check:** pick 3 of the 88 known zero-reviews products, open their Amazon
   pages, run a single import (or rescan their category), and confirm `amazon_reviews_count` now
   matches the on-page ratings count — or stays untouched (null sent) when the page truly shows
   none. (Empirical verification of the new selectors is an owner step — the builder cannot load
   Amazon pages.)
8. **Unavailable listing (v1.2):** open one known "Currently unavailable" product page and run a
   single import (Import This Product). Confirm in Filament that the OFFER carries
   `listing_flags = ["unavailable"]` + `stock_status = out_of_stock` + a fresh `health_checked_at`,
   and the product is still visible (`is_ignored = false`). Then rescan its category and confirm
   the page counts as **updated** (not skipped) in the summary.
9. **Non-Amazon availability (v1.3) — REQUIRED, the builder could not verify these selectors.**
   No live Clive/WLL page was loadable during implementation; detection was validated only against
   synthetic jsdom fixtures (64 assertions incl. every layer + the false-positive guards). Live
   confirmation is this step. For **wholelattelove.com** and **clivecoffee.com**, pick one IN-STOCK
   and one SOLD-OUT product each (4 pages total) and run **Import This Product** on each:
   - In-stock page → offer gets `condition = new`, `listing_flags = []`, `stock_status = in_stock`,
     and a fresh `health_checked_at`.
   - Sold-out page → offer gets `listing_flags = ["unavailable"]`, `stock_status = out_of_stock`,
     a fresh `health_checked_at`, product still visible (`is_ignored = false`), and the displayed
     price preserved if the page still shows one.
   - Then re-import the in-stock page (or rescan the category) and confirm a clean report **CLEARS**
     a previously-set `unavailable` flag back to `[]` — the flag must flip both ways.
   - If a sold-out page is NOT flagged, open DevTools on it and record which layer should have
     fired (JSON-LD `offers.availability` / meta / ATC button / inventory text) — every selector
     lives in the single `detectStoreAvailability()` function, so a fix is one edit.
10. **Non-Amazon CONDITION marker (v1.4) — REQUIRED, this is the case that disproved v1.3's assumption.**
   Run **Import This Product** on the live WLL refurbished listing:
   `https://www.wholelattelove.com/collections/espresso-machines/products/refurbished-lelit-anna-espresso-machine`
   Confirm in Filament that the offer stores **`condition = refurbished`** (NOT `new`), the popup reports
   "Flagged: non-new condition — product ignored", and the product is `is_ignored = true` (a negative
   condition ignores the PRODUCT — this is the intended, destructive-by-design outcome, and the reason
   the "used" marker stays positionally guarded). Then single-scan one ordinary NEW WLL machine and
   confirm it still reports `condition = new` — the marker must not over-fire. If any regular listing
   comes back `refurbished`/`used`, note which of the three marker sources fired (title / structured
   `itemCondition` / badge-breadcrumb); all three live in the one `detectStoreAvailability()` function.
11. **Old-server degradation:** point the extension at an environment without Phase A and press
   Rescan — expect the clear error "This server does not support rescan yet", no crash.

## Phase C — Operation (owner)

1. Deploy Phase A (safe before extension ships — new fields optional).
2. Load updated extension, rescan category-by-category (~1,070 offers ≈ 90 min of background browser time at 5s/URL, split across sessions; suggest starting with the 7 landing-page categories).
3. Review the flagged-condition list in Filament; regenerate any landing page that lost a pick or whose cited prices drifted (guide prose cites concrete prices/deltas — a big price shift makes published copy stale).
4. Standing habit: rescan a category before generating/regenerating its landing page.

## Risks

- Amazon DOM churn: condition selectors will rot; keep them in one function with the marker list mirrored from `ProductConditionGuard` naming.
- Overwriting `raw_title` on refresh loses the historical scrape title (acceptable — the current stored value is already unreliable; condition history lives in `condition`/`condition_checked_at`).
- Landing-page prose price drift after mass rescan (see C3) — deliberate, human-reviewed.

## Task breakdown

- T1 (builder): A1–A5 server work + tests.
- T2 (frontend/extension): B1–B4 (single PR, all three extension files + manifest bump).
- T3 (tester): endpoint contract tests incl. auth + tenant isolation.
- T4 (reviewer): pass over refresh semantics (silent-overwrite risks) + extension/API contract sync.
