# pw2d Tier-3 top-up run sheet — 2026-08-22

Discovery (SERP batch import) for the **pw2d** tenant. Baseline figures are the prod-verified
Tier-3 standings of 2026-08-20 (`docs/tasks/todo.md`); no pw2d rows have been added since 08-15.

## Priority order

Spec 031 §Tier 3 sorts by pool size. The open todo item *"Signal for Tier-3 discovery"* argues
unbuyable **share** is the stronger trigger. Both signals agree on the same first pick.

| # | Category | Pool | Buyable | Unbuyable | Verdict |
|---|---|---|---|---|---|
| 1 | `gaming-chat-headsets` | 79 | 59 | **25%** | Worst on both signals. Do first. |
| 2 | `lavalier-wireless-systems` | 76 | 66 | 13% | Smallest pool. |
| 3 | `productivity-ergonomic-keyboards` | 99 | 86 | 13% | Pool was cut 115→99 by the cross-category dupe sweep; never re-grown. |
| 4 | `mechanical-gaming-keyboards` | 162 | 149 | 8% | Healthy. Optional — coverage-gap searches only. |
| 5 | `podcast-studio-mics` | 181 | 153 | 15% | Largest pool but 15% unbuyable, and skewed to handheld vocal mics. Optional, targeted only. |

Calibration from the proven grinders run: **two** searches took that pool 33 → 58 buyable. A pool
already at 59–153 has less headroom, so expect lower yield per search and prefer brand-scoped
phrases that reach the tail over segment phrases that re-return the head.

## Sequence (Spec 031 §61 — do not deviate)

**import (ALL searches for one category) → category rescan → `pw2d:landing-pages:audit` → regenerate → owner review → publish**

- Never regenerate between searches — you would just rebuild twice.
- SERP import stores search-tile data only, so new rows land with `health_checked_at = NULL`.
  Picks must never be selected from them; the rescan is what makes them eligible.
- A top-up rescan **resets that category's Tier-2 clock** — it *is* that month's sweep.

## Pre-flight

- [ ] Extension reports **v1.8** (Spec 033 `offer_id` targeting). Below that, a rescan of a
      duplicate-bearing category lets the lowest-id twin absorb every update. Ergonomic keyboards
      is the known duplicate-bearing category.
- [ ] Extension tenant selector set to **pw2d**, not coffee2decide. The extension is tenant-scoped.
- [ ] Weekly pick verification for pw2d has **never** been run. If you only top up 1–2 categories,
      still run "Verify Live Picks" for pw2d afterwards so the untouched pages get checked.

## Search phrases

Brand-scoped phrases first — they reach ASINs the pool doesn't have. Segment phrases return the
head of the market, which is mostly already imported. Scrape 2–3 SERP pages per phrase; past that
it is accessories. Ignore rates run 35–70%, which is normal and expected.

### 1. gaming-chat-headsets — target +25–35 rows

Pool skews to HyperX Cloud, Logitech G Pro, SteelSeries Arctis, Razer Kaira. These reach elsewhere:

- `Corsair gaming headset wireless`
- `Turtle Beach Stealth wireless headset`
- `Sony INZONE gaming headset`
- `JBL Quantum gaming headset`
- `Astro gaming headset wireless`
- `Beyerdynamic gaming headset`
- `EPOS gaming headset`
- `Audeze Maxwell gaming headset`
- `Asus ROG gaming headset`
- `Alienware gaming headset wireless`
- `open back gaming headset PC`
- `gaming headset detachable boom microphone`

**Avoid:** `cheap gaming headset`, `RGB gaming headset`, anything with `stand` or `bundle`.
Those return white-label junk and accessories — the Bouncer kills them, but you pay the AI cost.

### 2. lavalier-wireless-systems — target +20–30 rows

- `Rode Wireless PRO`
- `Rode Wireless ME`
- `DJI Mic 2`
- `DJI Mic Mini`
- `Hollyland Lark M2`
- `Hollyland Lark Max`
- `Saramonic Blink wireless microphone`
- `Sennheiser EW-DP wireless microphone`
- `Shure MoveMic`
- `Sony ECM-W3 wireless microphone`
- `Comica wireless lavalier microphone`
- `Deity Pocket Wireless`
- `UHF wireless lavalier system dual channel`

**Avoid:** `lavalier microphone` bare — this category is an accessory magnet (windscreens, dead
cats, TRS adapter cables). Keep the brand or the word `wireless` in every phrase.

### 3. productivity-ergonomic-keyboards — target +20–30 rows

- `Logitech ERGO K860`
- `Logitech Wave Keys`
- `Kinesis Advantage 360`
- `Kinesis Freestyle2 split keyboard`
- `Microsoft Sculpt Ergonomic Keyboard`
- `Perixx Periboard ergonomic keyboard`
- `Cloud Nine ErgoTKL`
- `Mistel Barocco split keyboard`
- `X-Bows ergonomic keyboard`
- `Goldtouch adjustable keyboard`
- `Contour Balance keyboard`
- `Matias Ergo Pro`
- `tented split mechanical keyboard wireless`
- `quiet mechanical keyboard for office`

**Avoid `ergonomic gaming keyboard` and any phrase pairing `ergonomic` with `gaming` or `RGB`.**
That is precisely how 16 gaming boards (G815, ROG Strix, AULA, Redragon, Keychron Q5 Pro/K2/K4/C1)
landed in this category as cross-category duplicates and had to be swept in August.

### 4. mechanical-gaming-keyboards — optional, coverage gaps only

- `Wooting 80HE`
- `SteelSeries Apex Pro TKL`
- `Razer Huntsman V3 Pro`
- `Corsair K70 Max magnetic`
- `HyperX Alloy Rise`
- `NuPhy Field75`
- `Epomaker magnetic switch keyboard`
- `Glorious GMMK 3`
- `hall effect keyboard rapid trigger`

### 5. podcast-studio-mics — optional, targeted only

The open todo *Category-intent skew* notes the weighting rewards noise rejection, so live handheld
vocal mics (SM58 family, XS 1, MD 445, KSM8) dominate a page readers expect to be broadcast mics.
Discovery here should add **broadcast dynamics and studio USB**, not more handhelds.

- `Electro-Voice RE20`
- `Electro-Voice RE320`
- `Heil PR40`
- `Rode PodMic USB`
- `Rode Procaster`
- `Shure MV7+`
- `Audio-Technica BP40`
- `Beyerdynamic M70 Pro X`
- `sE Electronics DynaCaster`
- `Lewitt Ray microphone`
- `Elgato Wave DX`

**Avoid:** `podcast microphone kit` / `bundle` — bundles are a Bouncer rejection class.

## Known hazards on the regenerate step

- **H-A (`modelKey()` false-merge, open, live on 6 pages).** A brand name containing a digit
  becomes the identity key, so every product of that brand collapses to one slot. In pw2d the
  exposed brands are **8BitDo** (keyboards) and **1MORE** (audio). Bare size/quantity tokens also
  read as identity. If a top-up imports several products from a digit-bearing brand, the page will
  silently pick at most one of them and log nothing. Check the pick table before publishing.
- **Homepage `products_count` is unfiltered** (open todo) — it counts raw `products` rows with no
  `is_ignored`/`status`/buyability filter, so the homepage tile will overstate the new pool. Read
  pool size from `pw2d:categories:health`, not the site.
