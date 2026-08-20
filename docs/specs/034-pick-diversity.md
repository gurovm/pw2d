# Spec 034 — Pick diversity: model-identity variants + brand cap

**Status:** DRAFT (2026-08-20) · **Depends on:** 027 (landing pages), 031 (cadence) · **Blocks:** regeneration of `/best/super-automatic-espresso-machines` and `/best/semi-automatic-manual-espresso-machines`

## Why

The 2026-08-20 super-auto Tier-3 top-up took the buyable pool 41 → 62 and made the page **worse**.
`--dry-run` on the enlarged pool selected:

| # | Role | Proposed pick | Price |
|---|---|---|---|
| 1 | overall | JURA Z10 Super-Automatic — Gen 1 | $4,300 |
| 2 | budget | Philips 4400 Series | $680 |
| 3 | premium | **Jura Z10 Aluminum White** | $4,200 |
| 4 | the-purist | JURA GIGA 10 | $5,500 |
| 5 | latte-lover | JURA J10 Twin | $3,800 |
| 6 | office-hero | Jura GIGA X8 | $10,000 |
| 7 | overall | JURA X10 Dark Inox | $4,400 |

Two independent failures, both structural:

1. **The same machine holds two slots.** Z10 Gen 1 is *Best Overall*; Z10 Aluminum White is *Premium*.
   One machine, two colourways.
2. **Six of seven picks are one brand.** A "best super-automatic" guide that is 6/7 Jura does not read
   as a comparison. On a page whose only asset is trust, that is the expensive failure.

A third consequence follows from the second: the $2,000 De'Longhi Eletta — the only option between the
budget pick and the $3,800 tier — is displaced, so the page jumps $680 → $3,800 with nothing between.

### The existing guard cannot be tuned into correctness

`SelectLandingPagePicks` rejects a candidate whose normalized name is ≥85% `similar_text` to an
already-picked one. Measured against the real names:

| Pair | Similarity | Truth | Outcome |
|---|---|---|---|
| Z10 Gen 1 vs Z10 Aluminum White | **43.3%** | same machine | **missed** |
| GIGA 10 vs GIGA X8 | 63.8% | different | correctly passed |
| X10 Dark Inox vs J10 Twin | **82.1%** | different | nearly false-positive |

The true duplicate scores **43%** while two genuinely different machines score **82%**. The metric is
**inverted relative to truth**: lowering the threshold to catch the real duplicate would merge two
distinct machines first. No threshold value separates these cases, because string similarity measures
marketing copy length, not product identity.

Product identity lives in the **model token** — `Z10`, `GIGA X8`, `ECAM29043SB` — and everything else
in the name is copy and colourway.

## Build

Both rules land in `SelectLandingPagePicks`, in the existing `$addPick` closure — already the single
chokepoint every pick site funnels through, including the fall-through-to-next-best behaviour that
keeps slots from going empty.

### 1. Variant identity replaces name similarity

New `modelKey(Product $p): ?string` — `brand_id` + the product's strongest model token:

- normalize: lowercase, split on non-alphanumerics
- **candidate tokens** = tokens containing at least one digit
- join a candidate to its immediately-preceding alpha token when that token is ≤5 chars and is not
  the brand name and not a stopword (`series`, `gen`, `edition`, `professional`, `espresso`,
  `machine`, `coffee`, `super`, `automatic`, `fully`) — so `GIGA 10` → `giga10`, while `Z10` stays `z10`
- **take the FIRST candidate in name order** — model numbers lead, generation and SKU suffixes trail,
  so this drops `Gen 1` and trailing catalogue numbers for free

Two products are variants when `modelKey` is non-null and **equal**. When either side has no model
token (e.g. `Gaggia Cadorna Prestige`), fall back to today's `similar_text`/substring guard unchanged
— it is a reasonable last resort, just a bad primary.

Verified by hand against the live names:

| Name | Key |
|---|---|
| JURA Z10 Super-Automatic Espresso Machine - Gen 1 | `z10` |
| Jura Z10 Aluminum White | `z10` ← **same, now caught** |
| JURA GIGA 10 Espresso Machine | `giga10` |
| Jura GIGA X8 Professional | `gigax8` |
| JURA X10 Dark Inox / JURA J10 Twin | `x10` / `j10` ← still distinct |
| Philips 4400 Series Fully Automatic | `4400` |
| De'Longhi Magnifica Evo ECAM29043SB | `ecam29043sb` |
| Gaggia Cadorna Prestige | *null* → similarity fallback |

### 2. Brand cap

`MAX_PICKS_PER_BRAND = 3` of `MAX_PICKS = 7`.

**It must be a SOFT cap.** If no eligible under-quota candidate exists for a slot, take the
over-quota one rather than leave the slot empty — a category with only two viable brands must still
produce a page, and `MIN_PICKS = 5` already aborts generation when a pool is too thin. Emit a
`Log::info` naming the category whenever the cap is exceeded, so a genuinely monocultural category is
visible as data rather than silently normalised.

Applied greedily in existing role order (overall → budget → premium → presets → fill-in), so the
highest-value roles claim brand slots first.

Constants, not config — one obvious place to tune, no new plumbing.

## Consequences the owner must weigh

**This changes pick selection on all 11 landing pages, not just super-auto.** Every page will report
`selection_drift` on its next audit. Do **not** mass-regenerate: ship the rule, then regenerate one
page at a time with review, per Spec 031's response rule. The two espresso pages are already stale and
are the natural first two.

It also partly addresses two open todo items — "repeated products within/across pages" (Keychron Q11
twice on ergonomic, K8 HE across both keyboard pages, SM58 two-pack + SM58S on mics) and the
`podcast-studio-mics` intent skew. The variant rule should catch the Q11 and SM58 cases; the brand cap
should loosen the SM58-family dominance on mics. Neither is a full fix for the intent-skew question,
which is about feature weighting, not selection.

## Tests

- Z10 Gen 1 + Z10 Aluminum White → second rejected as a variant (**the regression this spec exists
  for** — assert on the real names, not synthetic ones).
- X10 Dark Inox + J10 Twin → both selectable (guards against over-merging; these score 82.1% and
  would be lost to a naive threshold cut).
- GIGA 10 + GIGA X8 → both selectable.
- Two products with no model token fall back to the similarity guard, behaviour unchanged.
- Brand cap: a pool of 7+ same-brand products yields at most 3 of that brand when alternatives exist.
- Brand cap is soft: a pool containing ONLY one brand still produces `MIN_PICKS`, and logs.
- Cap counts by `brand_id`, not brand name string.
- Existing `SelectLandingPagePicksTest` cases still pass — condition markers, null-best-offer
  exclusion, and fall-through-to-next-best must be untouched.
