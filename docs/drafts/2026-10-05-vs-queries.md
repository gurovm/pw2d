# Head-to-head searches in Google Search Console — 2026-10-05

Spec 043 Phase 0. Both tenants, last 16 months, `query` matching `\bvs\b|\bversus\b`, dims query+page,
aggregated per query (impression-weighted position, landing page with most impressions). Junk rows (position > 100,
e.g. "cold brew maker vs facial cleanser") removed: 40 of 59.

| Site | Search | Impr. | Clicks | Pos. | Landing page |
|---|---|---|---|---|---|
| coffee2decide | bezzera hobby vs rancilio silvia | 18 | 0 | 51.1 | `/product/silvia-espresso-machinet-pyhne` |
| coffee2decide | hario switch 02 vs 03 | 10 | 0 | 23.4 | `/product/hario-switch-size-02-qfgr6` |
| coffee2decide | semi automatic espresso machine vs manual | 3 | 0 | 63.3 | `/compare/semi-automatic-manual-espresso-machines` |
| coffee2decide | espresso vs appiumap23 | 2 | 0 | 49.5 | `/compare/super-automatic-espresso-machines` |
| coffee2decide | bezzera vs la marzocco | 1 | 0 | 20.0 | `/compare/semi-automatic-manual-espresso-machines` |
| coffee2decide | chemex vs hario switch | 1 | 0 | 1.0 | `/product/hario-switch-size-02-qfgr6` |
| coffee2decide | hario switch 03 vs 02 | 1 | 0 | 23.0 | `/product/hario-switch-size-02-qfgr6` |
| coffee2decide | hario switch size 02 vs 03 | 1 | 0 | 6.0 | `/product/hario-switch-size-03-rjfov` |
| coffee2decide | kingrinder k1 vs k6 | 1 | 0 | 3.0 | `/product/graykingrinder-k1-manual-coffee-grinder-straight-handle-stainless-ivvsy` |
| coffee2decide | manual espresso machine vs semi automatic | 1 | 0 | 21.0 | `/compare/semi-automatic-manual-espresso-machines` |
| coffee2decide | manual vs semi automatic espresso machine | 1 | 0 | 16.0 | `/compare/semi-automatic-manual-espresso-machines` |
| coffee2decide | semi automatic vs manual espresso machine | 1 | 0 | 30.0 | `/compare/semi-automatic-manual-espresso-machines` |
| coffee2decide | timemore c3 vs c5 | 1 | 0 | 5.0 | `/product/timemore-c5-pro-manual-coffee-grinder-capacity-30g-beidg` |
| coffee2decide | timemore c5 pro vs kingrinder k6 | 1 | 0 | 20.0 | `/product/timemore-c5-pro-manual-coffee-grinder-capacity-30g-beidg` |
| coffee2decide | vesper vs timemore | 1 | 0 | 9.0 | `/compare/manual-coffee-grinders?preset=traveler` |
| pw2d | keychron k2 vs aula f75 comparison | 17 | 0 | 13.4 | `/product/keychron-k2-75-layout-v2-rbak1` |
| pw2d | austrian audio od303 vs shure sm58 | 2 | 0 | 8.5 | `/product/austrian-audio-od303-pmziq` |
| pw2d | apex pro mini gen 3 vs gen 2 | 1 | 0 | 27.0 | `/product/steelseries-apex-pro-mini-gen-3-5odxu` |
| pw2d | casper vs shure | 1 | 0 | 10.0 | `/compare/podcast-studio-mics` |
| pw2d | tc helicon mp 75 vs mp 85 | 1 | 0 | 21.0 | `/product/tc-helicon-mp-85-bwdwd` |

**Read:** ~20 real pair searches, 1–18 impressions each over 16 months, no clicks. Google already associates our
product and compare pages with some pairs, but the demand reaching us today is too small to justify pages by itself.
Market demand for well-known rivalries (Gaggia Classic Pro vs Rancilio Silvia, Linea Mini vs GS3) is real but not
measurable from our own data; a keyword tool (Google Ads Keyword Planner, or a paid tool) would be needed for volumes.

## Keyword Planner (Google Ads), US, all languages, Sep 2025 – Aug 2026 — owner's account, 2026-10-05

| Pair | Avg. monthly searches | Our prices | Our scores (espresso / steam / workflow / heat-up / build / maintenance) | Split ≥ 5? |
|---|---|---|---|---|
| De'Longhi La Specialista vs Breville Barista Express | **100–1K** | $699 / $500 | 70/75/80/85/78/65 vs 68/62/78/63/72/60 | La Specialista wins 4, Express 0 |
| Ninja Luxe Café vs Breville Barista Express | **100–1K** | $500 / $500 | 55/45/78/65/70/60 vs 68/62/78/63/72/60 | Express wins 2, Ninja 0 (its drip/cold-brew extras are outside our features) |
| Gaggia Classic Pro vs Rancilio Silvia | 10–100 | $299 / $995 | 75/70/65/55/85/70 vs 65/85/55/40/90/70 | **yes** — Gaggia 3, Silvia 2 (price gap 3.3×, rule 3 waived on demand) |
| ECM Synchronika vs Profitec Drive | 10–100 | $3,599 / $3,449 | 96/92/88/65/98/60 vs 95/92/88/75/98/65 | Drive wins 2, Synchronika 0 |
| La Marzocco Linea Mini vs GS3 | 10–100 | $6,600 / $8,400 | 98/99/88/75/100/65 vs 98/97/88/65/99/60 | Mini wins 2, GS3 0 |
| Lelit Bianca vs Profitec Drive | 10–100 | $3,000 / $3,449 | 96/92/88/75/94/65 vs 95/92/88/75/98/65 | **no** — within 4 points everywhere |
| Breville Barista Pro vs Barista Touch | 10–100 | $630 / $800 | identical | **no** |
| ECM Synchronika vs Lelit Bianca; Linea Mini vs Lelit Bianca; Rocket Appartamento vs Lelit Mara X; Profitec GO vs Gaggia Classic Pro; Breville Oracle Jet vs Barista Touch Impress; Rancilio Silvia Pro X vs Lelit Elizabeth; Bezzera Hobby vs Rancilio Silvia; Barista Express vs Barista Pro | 10–100 each | — | not checked | — |
| Kingrinder K6 vs Timemore C5; Timemore C3 vs C5 (yardstick) | 10–100 each | — | — | — |
| Breville Barista Express vs Bambino Plus; Breville Dual Boiler vs Profitec Pro 500 | no data (< 10) | — | — | — |

**Finding:** at the high end our six scores bunch at 88–100, so several prosumer pairs tie on our data (Bianca vs Drive,
Barista Pro vs Touch). A VS page can only say what the score table supports; a tie pair has nothing to stand on.
Prices are pre-rescan (Gaggia $299 looks like a sale or a stale price).

## Keyword Planner "Discover new keywords" — 8 seeds, US, 1,133 "vs" ideas (CSV in owner's Downloads, 14:52)

Bucket midpoints from the CSV: 5000 = 1K–10K, 500 = 100–1K, 50 = 10–100.
- **Brand vs brand:** "delonghi vs breville" **1K–10K** plus ~15 variants at 100–1K (many are Nespresso-by-Breville
  vs Nespresso-by-De'Longhi — capsule intent we do not cover); "jura vs delonghi", "jura vs breville", "jura vs
  miele", "terra kaffe vs jura" 100–1K each. A different page type (brand vs brand) — Spec 043 open question 7.
- **Jura models (super-automatic, high-ticket, sold at WLL):** e8 vs s8, e6 vs e8, e4 vs e6 at 100–1K each (several
  word orders each); z10 vs s8 / e8 / giga 6, ena 4 vs e4, ena 8 vs e8 at 10–100.
- **Breville Barista family:** pro vs express (+ ~8 variants), pro vs touch, express vs impress, express vs touch,
  touch vs impress at 100–1K each; oracle vs oracle touch, touch vs oracle touch at 10–100.
- **Others at 100–1K:** profitec go vs rancilio silvia; gaggia classic vs pro; delonghi la specialista vs breville
  barista express. Gaggia Classic Pro vs Rancilio Silvia only 10–100.

### Pair checks on prod (2026-10-05, pre-rescan prices)
| Pair | Prices | Scores | Verdict |
|---|---|---|---|
| Profitec GO (3510) vs Rancilio Silvia (3397) | $1,199 / $995 | GO 85/60/72/88/90/78 vs Silvia 65/85/55/40/90/70 | strong split — **pilot** |
| Jura E4 (3492) vs E6 (3466) | $1,345 / $1,760 | E4 milk 10, maintenance 75 vs E6 milk 70, maintenance 65 | real split — **pilot** |
| Jura E6 (3466) vs E8 (3497) | $1,760 / $2,599 | E8 +18 milk, +15 maintenance, +25 customisation | E8 leads; "worth $840 more?" — **pilot** |
| Jura E8 (3497) vs S8 (3502) | $2,599 / $3,299 | within 5 everywhere | near-tie; "S8 costs $700 more for little" — **pilot** (demand overrides rule 4) |
| Breville Barista Express (3282) vs Pro (3289) | $500 / $630 | Pro +13 steam, +7 workflow, +27 heat-up, +6 build | Pro leads — **pilot** |
| De'Longhi La Specialista vs Barista Express; Ninja Luxe Café vs Barista Express; Barista Express vs Impress | | | reserves |
| Breville Barista Pro vs Touch | | identical | excluded (tie) |

Note: two E8 products exist (3497 Chrome, 4246 "Gen 4") and two E4 colours — super-automatic needs its model
backfill before its VS pages (spec dependency).
