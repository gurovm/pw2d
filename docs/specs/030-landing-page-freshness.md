# Spec 030 — Landing-page freshness engine

**Status:** DRAFT (2026-08-10) · **Companion to:** Spec 029 (rescans create the very events this spec detects). **Owner insight that triggered it:** "every refresh can move a product to ignored (refurbished, or the high-price tag) — so we need an engine that checks whether the best pages are up to date."

## Problem

Published `/best/` pages are generated from a point-in-time pick selection, but the underlying products keep moving: rescans flip products to `is_ignored` (renewed) or flag offers (`high_price`), AI sweeps detach products, prices drift. Today the ONLY safety net is render-time filtering — the page silently drops ineligible picks (the headsets page served 4 of 7 picks on 2026-08-09 until manual QA caught it). Nothing tells anyone a published guide has degraded, and the prose keeps citing prices that may no longer be true.

## Design principles

- **Detect automatically, regenerate manually.** Regeneration produces new AI/authored prose that must pass owner review (style contract, condition QA) — auto-publishing unreviewed content is off the table. The engine's job ends at a loud, precise "this page is stale, here's why."
- Two detection speeds: **instant** (observer-driven, catches ignore/detach the moment it happens) and **nightly** (scheduled audit, catches drift no single event announces).

## Staleness reasons (the contract)

A page is stale when any of:
1. `pick_ineligible` — a stored pick is deleted, detached (`category_id` null/changed), `is_ignored`, or its best offer carries a condition/`high_price` flag (Spec 029 fields).
2. `selection_drift` — re-running `SelectLandingPagePicks` yields a different id set than stored (scores/prices moved enough to change the deterministic answer).
3. `price_drift` — any pick's current `estimated_price` deviates from its generation-time snapshot by >15% (prose cites concrete prices and deltas).
4. `render_short` — the controller would render fewer picks than stored (the current silent self-heal, made visible).

## Build

**B1. Migration + snapshot.**
- `landing_pages`: add `stale_reasons` (JSON, nullable — null/[] = fresh) and `freshness_checked_at` (timestamp, nullable).
- Picks JSON entries gain `est_price_snapshot` written at save time (GenerateLandingPage + any import path). One-off backfill command stamps snapshots on the 11 existing pages from current prices (accepting today as the baseline).

**B2. `App\Actions\AuditLandingPageFreshness`.** Takes a LandingPage, returns the reasons array (empty = fresh) and persists `stale_reasons` + `freshness_checked_at`. Pure read logic + one write; no AI, no side effects on products.

**B3. Instant path — observer.** On Product saved/deleted where `is_ignored` flipped true or `category_id` changed: find same-tenant landing pages whose picks JSON contains that product_id and run B2 on them (queue it — never block the admin/API write). Offer condition/flag changes from Spec 029 ingestion trigger the same hook.

**B4. Nightly path — `pw2d:landing-pages:audit {tenant?}`.** Runs B2 over all pages (published first), prints a table (page, status, reasons, checked_at), exits non-zero if any PUBLISHED page is stale. Scheduled nightly after the SEO pull; failure shows up in the same ops habits as `pw2d:seo:status`.

**B5. Filament surfacing.** `LandingPageResource`: "Freshness" badge column (FRESH green / STALE red with reasons in tooltip), navigation badge = count of stale published pages. Stale published pages sort first.

**B6. Regeneration loop (unchanged tooling, closed loop).** Owner regenerates via the existing `pw2d:generate-landing-page {tenant} {category} --regenerate` (keeps status) → review → save clears `stale_reasons` and re-stamps snapshots. The audit never edits content.

**B7. Tests.** Each reason in isolation; observer triggers on ignore-flip and detach; snapshot backfill; nightly command exit codes; Filament badge query tenant-scoping; regeneration clears staleness.

## Non-goals

- No auto-regeneration, no auto-unpublish (a degraded-but-honest page usually beats a 404; the owner decides).
- No freshness signals in schema.org output.
- No coupling to GSC data (that's the /seo-status lane).

## Task breakdown

- T1 (builder): B1–B4 + B6 wiring.
- T2 (frontend/Filament): B5.
- T3 (tester): B7.
- T4 (reviewer): observer performance (JSON containment query), queue safety, tenant scoping.

## Sequencing with Spec 029

029 Phase A and 030 are independent at the code level but 030's `pick_ineligible` reads 029's offer fields when present (guarded with column checks until both are live). Recommended order: **029-A → 030 → 029-B (extension) → Phase C mass rescan** — so by the time the rescan starts flipping products, the freshness engine is already watching the published pages.
