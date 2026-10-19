# Session Summary — 2026-10-19 — Rescans never updated price bands (a second save hid the price change); semi-automatic budget pick replaced; Customize drawer no longer auto-opens

**Commits:** `93df98d` (fix: rescans recalculate price bands + nightly 03:20 safety net) · `b71ae3e` (fix: stop
auto-opening the Customize drawer) · `544ab71` (docs: lesson + approved draft) + this summary ·
**Suite:** 991 → 999 passed, 21 skipped, 0 failures
**Prod:** deployed `b71ae3e` via `/deploy` (~11:10 UTC); prod HEAD = `origin/main` code; later commits are docs only
**Prod writes:** `price_tier` on 27 products · semi-automatic landing page (budget pick + 3 one-line edits) — backups
and undo in §1
**Spec:** 029 addendum 2026-10-19 (price bands) · 025 addendum 2026-10-19 (drawer)

> **The one-line lesson:** `wasChanged()` only remembers the last save — read it right after the save you mean.
> The rescan's price-change check ran after a second save of the same offer, so rescans never recalculated price
> bands, and a guide's "Best Budget" pick was a $394 machine in a category whose budget line is $250.

## 1. What changed

**Code (deployed, `93df98d`):** `OfferIngestionService` captures `wasChanged('scraped_price')` right after the
offer's own save, before `ListingHealthService::apply()` re-saves it (both refresh paths). `products:recalculate-tiers`
is scheduled daily at 03:20, before the 03:30 guide audit, and logs `products:recalculate-tiers: done {fixed, skipped}`.
6 tests; the 3 that send the extension's real payload (`condition` present) were confirmed red on the old code by the
architect, green with the fix. Built by the builder sub-agent; diff and suite verified by the architect.

**Code (deployed, `b71ae3e`):** the compare/product-page Customize drawer never opens by itself; every load shows the
teaser (FAB bounce + tooltip). The `autoOpen` prop is removed. 2 tests. Verified live in Chrome: no auto-open, manual
open works, console clean. Built by the frontend sub-agent. The `app-open-sidebar` listener now has no dispatcher
(harmless; kept as a hook for a future "Customize" link).

**Prod writes (each confirmed with the owner, backup first):**
| Write | Backup (`/root/backups/`) | Undo |
|---|---|---|
| `products:recalculate-tiers` — 27 products changed band (list in §2) | `products-price-tier-before-recalc-2026-10-19.sql` (one UPDATE per product, all 2,649) | run the file |
| Semi-automatic guide: budget pick De'Longhi Dedica Maestro Plus (3329) → Breville Bambino (3287) + 3 one-line edits (intro, busy-commuter pick, one FAQ), from the owner-approved `docs/drafts/2026-10-19-semi-auto-budget-pick-draft.md` (source `…-guide.json`); selection, price and style guards passed; audit FRESH | `landing-semi-auto-before-budget-swap-2026-10-19.sql` (INSERT for row 8) | delete row 8, run the file |

**Docs:** Specs 029 + 025 addenda; lesson "wasChanged after a second save"; memory `maintenance-cadence` (10-19 entry).

## 2. What was measured (all prod, 2026-10-19)

- **The bug, from the log:** on 10-15 laravel.log has 167 `OfferIngestion: refreshed existing offer` lines (both
  picks runs + the super-automatic rescan) and one `RecalculateCategoryPriceTiers: done` — from an Amazon batch
  import, whose controller sets `$priceUpdated` on any refresh and is not affected. Job never failed (0 in failed_jobs).
- **Stale bands before the fix (read-only scan, live products, `best_price` vs `priceTierFor()`):** 27 of 1,305 in 10
  of 11 categories — pw2d gaming headsets 10, lavalier 8, mechanical keyboards 2, ergonomic 1, podcast mics 0; c2d
  gooseneck 2, pour-over 2, semi-automatic 1, grinders 1, super-automatic 0, cold brew 0. The recalc changed exactly
  these 27.
- **Audit after the recalc:** selection drift appeared on semi-automatic (budget slot only), gooseneck (budget Mecity
  → Grosche Marrakesh $25.28, last checked 08-21; one fill-in Cosori → Mecity) and **lavalier, which was FRESH**: 6 of
  9 slots move (overall Rode Wireless Micro → DJI Mic, budget, all three presets, one fill-in; premium stays). Gaming
  headsets stayed FRESH despite 10 band changes. The band feeds the ranking score (price-tier credit), so a band change
  can move preset and overall picks, not only budget/premium.
- **Semi-automatic after the save:** Bambino $249.95 (Amazon, in stock, checked 10-05); the other six picks' prices
  equal their snapshots (checked 10-15). Live page 200, "Bambino" ×8, "Dedica" ×0; audit FRESH.
- **After deploy:** `schedule:list` shows the 03:20 recalc before the 03:30 audit; compare pages on both sites and the
  Bambino product page return 200 with the teaser and no auto-open code; queue workers restarted (fresh PIDs); queue
  empty, 0 failed jobs today.

**Falsified / corrected:**
- The 10-15 todo diagnosis "`price_tier` is recalculated only on imports, not on rescans" — the dispatch existed on the
  rescan path; a second save made its check always false. The proposed fix (dispatch per rescan run) would have
  duplicated code that was already there.
- This morning's plan "surgical price patch on semi-automatic (Dedica $250 → $390)" — would have published a $394
  "budget" pick; the slot itself was wrong. Caught by checking bands before writing.
- The 10-15 audit's "selection unchanged" on semi-automatic and gooseneck was computed on stale bands.
- The A4 tests passed for two months because they never sent `condition`, which the extension always sends.

## 3. What is still unknown

- **Do rescans now queue the recalc in real use?** First proof: Thursday's picks runs should log
  `RecalculateCategoryPriceTiers: done` lines. Also read the first nightly `products:recalculate-tiers: done` line
  (10-20): a non-zero `fixed` means another path still leaks (stock/condition changes without a price change are
  expected sources; the nightly run covers them).
- **Bambino flapping:** it sits 5¢ under the $250 line; any price rise moves it to band 2 and the budget slot changes
  again. How often that happens is unknown — watch it in the weekly audit.
- **Gooseneck and lavalier new picks are unverified** (pools last swept 08-21 / 08-31) — sweep before regenerating.
- **Does the drawer change move conversion?** Compare funnel `BFrlzHNY` baseline 15.5%; ~13 compare landers / 30 d, so
  read before/after around 11-02 and expect a noisy answer.
