# Spec 029 — Extension Rescan v2: full-field refresh + listing-health detection

**Status:** DRAFT (2026-08-09, amended 2026-08-10) · **Bundles:** F37 (verify-condition mode), F38 (refresh drops image/stock), Q1 (offer unique-constraint, CONFIRMED firing in prod), reviews_count extraction fix, cleaned-raw_title blindness fix (found 2026-08-09), **Amazon "High price" buy-box label detection (owner, 2026-08-10)**.

> **Amendment 2026-08-10:** detection broadened from *condition* to *listing health*. Amazon's own
> buy-box "High price" warning ("We have recently seen better prices on Amazon or from other
> retailers") is DOM-only, like Renewed, and marks a listing users won't buy from — an affiliate
> dead end. Distinct handling: condition problems ignore the PRODUCT; a high-price label flags the
> OFFER (the product is fine; the listing is a bad deal today) and excludes it from landing-page
> pick eligibility only. Companion spec: 030 (landing-page freshness engine) handles the fallout
> when rescans invalidate published picks.

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
- Accept optional `condition` ∈ `new | renewed | refurbished | open_box | used | unknown` AND optional `listing_flags` (array; initially `high_price`, room for `unavailable` later) on `POST /api/extension/ingest-offer` and both import endpoints. Absent → `unknown` / `[]` (today's behavior).
- Migration: `product_offers.condition` (string, nullable, indexed), `product_offers.listing_flags` (JSON, nullable), `product_offers.health_checked_at` (timestamp, nullable).
- On `renewed|refurbished|open_box|used`: set offer condition, set product `is_ignored = true`, respond `action: flagged_condition`. `ProductConditionGuard` remains the single marker-source of truth for text checks; DOM-detected condition is a stronger signal layered on top.
- On `high_price`: store the flag on the OFFER only — the product stays visible (it's a fine product; today's listing is a bad deal). `SelectLandingPagePicks` excludes products whose best offer carries `high_price` from pick eligibility. Compare pages unchanged for now (informational value stands); revisit if CTR data says otherwise.
- A clean rescan (no markers, no flags) clears `condition`→`new`, `listing_flags`→`[]`, stamps `health_checked_at` — flags are point-in-time listing state, meant to flip both ways.
- Landing-page fallout (picks going ineligible mid-publication) is Spec 030's job; this spec only guarantees the signals land in the DB.

**A3. Rescan work-list endpoint.**
- `GET /api/extension/rescan-list?category_id={id}` (same token auth as existing endpoints): returns `[{offer_id, product_id, url, asin, last_scanned_at}]` for the category's non-ignored products, oldest first. Powers the extension's rescan walk; also subsumes the manual `--urls` table for F37.

**A4. Post-rescan tier recalc.**
- After a rescan updates prices in a category, dispatch the existing tier recalculation for that category (respect Q13: chunk, don't load unbounded). Corrected tiers fix wrong `$$$` badges automatically.

**A5. Tests.** Refresh-updates-all-fields, null-vs-0 reviews_count, updateOrCreate under duplicate fire, condition→flag path (incl. landing-pick warning), rescan-list tenant scoping + auth, tier recalc dispatch.

## Phase B — Extension (popup.js + content.js + background.js updated IN SYNC — CLAUDE.md rule)

**B1. `detectListingHealth()` in content.js** (product page DOM), returning `{condition, listing_flags}`:
- Condition: title parenthetical `(Renewed)`, the "Renewed" badge element, byline "Brand: Amazon Renewed" / "Visit the Amazon Renewed Store", "Refurbished"/"Open Box" markers. `new` only when the page affirmatively looks like a standard listing, else `unknown`.
- Flags: the buy-box "High price" label (heading text "High price" adjacent to the "Learn more" disclosure in the buy-box column) → `high_price`. Keep selector logic in this one function; Amazon DOM churn is expected.

**B2. Rescan mode in popup.** Select category → fetch `/api/extension/rescan-list` → background.js walks each URL in a tab with a 3–5s polite delay (reuse batch-mode pause/resume/progress UI) → content.js extracts `{raw title, price, image_url, stock, reviews_count, condition}` via `extractProductPageData()` + `detectCondition()` → POST to `ingest-offer` per offer. CAPTCHA/robot page (existing `checkForRobot()`) pauses the run.

**B3. reviews_count 6th strategy.** Find a live URL where `extractReviewsCount()` returns 0 (88 known products), add the missing selector. Send `null` when genuinely absent.

**B4. SERP batch mode:** keep sending the untouched raw title; pass `condition` when the SERP title itself contains a marker.

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
