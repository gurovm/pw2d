# Session Summary — 2026-10-05 — Clive had gone unchecked for six weeks; semi-automatic rebuilt on 188 machines; first VS pages live

**Commits:** `76672a7` (extension: Clive + WLL readers, v1.11) · `ddc98ac` (Spec 043 VS pages + "Compared with") ·
`f59455a` (VS names from brand + model; truthful "How We Ranked This") · `1182b25`, `aaa2d2e`, `55eaaad` (docs) ·
**Suite:** 916 → 986 passed, 21 skipped, 0 failures (local)
**Prod:** deployed `aaa2d2e` (migration `vs_pages`; queue workers restarted, fresh PIDs). Docs-only commits after it.
**Prod writes:** many, all per record and backed up first — see §1 "Prod writes". Two `vs_pages` rows published.
**Spec:** [043-vs-pages](../specs/043-vs-pages.md) (new, approved, deployed) · Spec 041 model backfill: semi-automatic done.

> **The one-line lesson:** errors that cluster on one store are a broken reader, not noise — every Clive listing,
> including the semi-automatic guide's best-overall pick, had been unchecked since 08-20 while the weekly run
> called the errors "expected".

## 1. What changed

**Semi-automatic espresso (c2d) — top-up → sweep → models → rebuild, all in one day (high-ticket first)**
- **Imports:** Clive 2 listing pages, Whole Latte Love 7 pages (semi-automatic collection; pages 3–7 sorted price
  high→low), Amazon "semi automatic espresso machine" + "espresso machine with grinder", 2 pages each. Live pool
  **135 → 188** (prod, 12:44 UTC; 193 before 5 hides). The Amazon gate moved 11 super-automatics out and rejected
  ~45 no-name machines; one bundle slipped through (hidden). Every accepted title was read.
- **Extension 1.11** (`76672a7`): Clive redesigned its site; both Clive readers (listing and product page) matched
  nothing. Fixed: Clive listing (new cards, canonical `/products/{handle}` URLs, brand), Clive product page (title,
  sale price, Pre-Order = buyable, 1400px images), WLL listing (dedupe by handle, stored URL shape, brand from the
  page's Shopify meta). Each verified with jsdom against pages the owner saved.
- **Category rescan** (= monthly sweep): first run errored on all 36 Clive offers; after the fix, 223 of 227
  listings checked, errors 37 → 5.
- **Model backfill:** 130 models written, 186 of 188 live products have one (Gevi 3404, Krups 3435 left on the
  heuristic). 18 same-model groups. Dry-run proved **zero page impact** from the models themselves.
- **Guide rebuilt and FRESH** (`/best/semi-automatic-manual-espresso-machines`): Dalla Corte Mina (overall), De'Longhi
  Dedica Maestro Plus (budget), La Marzocco GS3 MP (premium), ECM Estetika (purist), Linea Mini (latte artist),
  Bezzera BZ13 DE (commuter), ECM Mechanika Max II. Claude-written under the style + grounding contract, every number
  machine-checked, owner-approved draft `docs/drafts/2026-10-05-semi-auto-guide-draft.md`; saved behind a selection
  guard and an exact-price guard.

**False ranking claims fixed.** The methodology note on 5 c2d guides and the static "How We Ranked This" box said
features were weighted by importance; the overall ranking weights all six equally (+ stored rating, price-tier
credit) — only presets weight. Notes replaced on prod; template fixed in `f59455a` (deployed).

**Spec 043 — head-to-head pages** (`ddc98ac`, `f59455a`, deployed): `/vs/{slug}` pages (score table, preset row,
grounded prose, verdict, FAQ; BreadcrumbList only), `pw2d:vs-pages:save` with selection / price / style guards,
"Compared with" rivals on every product page, Head-to-head list on `/best/` pages, sitemap, Filament list, nightly
audit. Same-model logic extracted to `ModelIdentity::match()` (picks unchanged). Published: **/vs/profitec-go-vs-
rancilio-silvia** and **/vs/breville-barista-express-vs-breville-barista-pro**; owner requested indexing.

**Prod writes and how to undo them** (all under `/root/backups/` on prod, 2026-10-05)
| Backup | What it protects |
|---|---|
| `pw2d_full_before_clive_cleanup_2026-10-05.sql.gz` (10:27) | deleted dup ECM Estetika 5091 (+ Clive offer 3631) — a mistake, see §3; Clive URL `?ref` cleaned (offer 2595); bundle 5090 hidden; WLL Drive offer 2069 `www.` removed |
| `pw2d_full_before_rocket_brand_merge_2026-10-05.sql.gz` (10:44) | brand "Rocket" 258 → "Rocket Espresso" 247 (2 products), brand 258 deleted |
| `pw2d_full_before_estetika_tca_fix_2026-10-05.sql.gz` (10:48) | Clive offer 2595 moved 4052 → 5098, failed stub 4052 deleted; WLL TCA offer moved 5106 → 3630, 5106 deleted |
| `pw2d_full_before_marked_offer_delete_2026-10-05.sql.gz` (11:03, run by the owner) | WLL "Manufacturer Marked" Silvia M offer 3700 deleted |
| `pw2d_full_before_semi_hides_brands_renames_2026-10-05.sql.gz` (11:15) | hid 3447 KF6, 3450 KF7, 3411 Philips 5500, 3424 Ninja CFN601, 3442 Bambino+Baratza; brands DeLonghi 228 → De'Longhi 222, Saeco 227 → Gaggia 224, Luxe Café 226 → Ninja 215 (emptied brands deleted); renamed 3599, 3586, 3366 |
| `products_before_saeco_luxe_renames_2026-10-05.sql.gz` (11:16) | renamed 3372, 3313, 3301, 3297 (slugs unchanged; two still contain "saeco") |
| `products_before_semi_models_2026-10-05.sql.gz` (12:44) | 130 `products.model` values |
| `pw2d_full_before_semi_guide_and_notes_2026-10-05.sql.gz` + `landing_page_semi_auto_20261005_125212.json` + `landing_page_notes_before_fix_2026-10-05.json` (12:52) | semi-auto guide content; 4 methodology notes |
| (none — new table) | `vs_pages` ids 1–2; undo = delete the rows |

Imports, merges by the pipeline and the rescan's health updates are ordinary pipeline writes (the 10:27 dump predates them).

## 2. What was measured

- **Clive:** 0 of 36 offers checked by the 11:10 rescan (last check 08-20); 33 of 36 after extension 1.11. The
  Linea Mini (best-overall pick until today, Clive-only) was confirmed at $6,600 in stock at 12:08 UTC (prod).
- **Store mix, semi-automatic before the top-up:** 69 of 166 offers Amazon, 67 WLL, 30 Clive; 66 machines had no
  Amazon listing (prod, 10:00 UTC).
- **AI cost today:** 163 `evaluate_product` ($2.20) + 92 `match_product` ($0.29) — well under the ~250/day cap.
- **"vs" demand, our own GSC** (both sites, 16 months, query+page): ~20 real pair searches, 1–18 impressions each,
  0 clicks; 40 of 59 rows junk. `docs/drafts/2026-10-05-vs-queries.md`.
- **Keyword Planner, US, Sep 2025–Aug 2026** (owner's Ads account): "delonghi vs breville" 1K–10K; Jura E8 vs S8,
  E6 vs E8, E4 vs E6, Breville Barista family pairs, Profitec GO vs Rancilio Silvia, La Specialista vs Barista
  Express, Ninja Luxe Café vs Barista Express at 100–1K; most prosumer pairs 10–100.
- **Our scores bunch at the high end** (88–100): Bianca vs Drive within 4 points everywhere; Barista Pro vs Touch
  identical — tie pairs are excluded from VS pages.
- **Overall ranking formula** (code): every feature weight 50 + stored rating + price tier (1 = 100, 2 = 50, 3 = 0).

**Falsified or corrected today**
- "Clive errors are expected" (10-04 memory and run) — the reader was broken. Memory corrected.
- Architect proposed an Amazon top-up for a category whose catalog is mostly WLL/Clive, arguing only Amazon earns —
  the owner corrected it; memory `content-priority-high-ticket` already said the opposite.
- "12 Clive links match" — 11; one stored URL carried `?ref=`.
- The Estetika "duplicate" choice kept a failed, unscored stub and deleted the good copy; repaired at 10:48.
- "pos 7.7 for vesper vs timemore" — one impression; the top-query table overstates pair demand.
- "We weight X and Y most heavily" on 5 guides and the template — false.

## 3. What is still unknown

- **Five semi-automatic listings still unchecked** (keep the category's oldest-offer date at 08-20): Ascaso Steel DUO
  3627 (Clive page gone, only offer), Philips Barista Brew 3467 (Amazon, no price since 08-20, only offer), Lelit
  Mara X 3521 Clive offer 2172 (page gone; WLL offer fine), ECM Synchronika II FC 3601 Clive offer 2150 (errored on
  the live run although the saved page parses — unexplained), Eureka Costanza 3568 (WLL, out of stock). Need per-record
  decisions.
- **Will the VS pages rank?** Pilot gate ~2026-11-16 (≥ 2 of 5 on page one). Three Jura pages wait for the
  super-automatic sweep + model backfill.
- **Dalla Corte Mina as "best overall" at $8,500** is what the scores say; reader reaction unknown.
- **"Compared with" picks rivals by price only** — the GO page doesn't link its VS partner (todo).
- **Matcher cache** can attach new listings to hidden products; **one listing per store per product** makes colour
  and flow-control listings overwrite each other and re-import on every scan; WLL Fellow/Stone send no brand
  (all backlog).
- **"Manufacturer Marked"** (WLL cosmetic units) is not in the condition guard (backlog).
- **Flow control = same model** was applied through the backfill (only LUCCA M58 formed a group); the owner approved
  the apply but never stated a general rule.
- The **single-product import** still renames and re-slugs tracked products (Spec 041 out of scope) — owner warned.
