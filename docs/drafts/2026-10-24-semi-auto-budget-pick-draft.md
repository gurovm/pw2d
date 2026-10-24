# Draft — /best/semi-automatic-manual-espresso-machines: new budget pick (2026-10-24)

**For:** owner review. Nothing is saved until you approve. Source of truth for the save:
`2026-10-24-semi-auto-guide.json` (the live page, which still equals `2026-10-19-semi-auto-guide.json`, with only
the edits below).

**Why:** the Breville Bambino, our budget pick since 10-19, now costs $299.95 (re-checked today in the VPN
profile). This category's budget line sits between $240 and $256 (tier 1 tops out at $240, tier 2 starts at $256),
so the Bambino left the budget band and the audit marked the page STALE (selection drift). A fresh selection
gives the budget slot to the **KitchenAid Metal Semi-Automatic Espresso Machine (KES6503)**: $229.95 at Whole
Latte Love, in stock, re-checked today. The other six picks and their prices are unchanged (all equal their
snapshots).

**Checks run:** banned phrases (shared `ProseStyle` list) — clean, 13 blocks · listing-condition words — none ·
every number traced to prod scores and prices — clean · picks equal a fresh selection — yes (dry run, budget slot
only differs).

**Watch:** $229.95 is a Whole Latte Love **sale** price (struck-through $349.00 on the page); Amazon lists it at
$349.99 (last checked 10-05). When the sale ends the KitchenAid leaves the budget band too, and the slot moves
again — the third change in a few weeks. That is the case for the "keep a pick in its slot until its price moves
clearly past the line" backlog item.

## The new budget pick

| | Espresso | Steam | Workflow | Heat-up | Build | Maintenance | Price |
|---|---|---|---|---|---|---|---|
| KitchenAid KES6503 (new) | 60 | 55 | 70 | 65 | 75 | 70 | $230 |
| Breville Bambino (leaving) | 65 | 55 | 68 | 85 | 60 | 70 | $300 |

**Headline:** $1,470 less than anything else here

At $230 the KitchenAid KES6503 is the cheapest machine on the page by $1,470, and the top-ranked one we track at
$250 or less. Per our data it puts looks and ease of use ahead of espresso performance, and the body is metal.

Its best score is build, at 75, and workflow comes next at 70. That's the case for it: an easy machine to learn
on, and a way to find out whether espresso sticks before you spend $1,700 on the next machine up this list.

Everything else is a long step down. 60 on espresso quality, 55 on steam and 65 on heat-up and recovery are the
lowest on this page; every other pick scores at least 85 on espresso, 88 on steam and 75 on heat-up. Even its
build score trails the next-lowest here by 15 points. If the shot itself matters to you, skip ahead.

*(Sources: scores from `product_feature_values` for product 3438; "looks and ease of use ahead of espresso
performance" and "metal" from its stored summary; $1,470 = $1,700 Bezzera − $230; 85 / 88 / 75 = the lowest
espresso / steam / heat-up among the other six picks; 15 = Bezzera build 90 − 75.)*

## Five small edits elsewhere on the page

- **Intro:** "run from ~~$250~~ → **$230** to $8,800" · "The exception is the ~~$250 Breville Bambino, which scores
  65~~ → **$230 KitchenAid KES6503, which scores 60** on espresso quality; the next-lowest on this page is 85." ·
  "Nothing between ~~$250~~ → **$230** and $1,700 won a slot."
- **Top overall pick (Dalla Corte Mina):** "~~$8,250~~ → **$8,270** more than our budget pick".
- **Busy-commuter pick (Bezzera):** "the least expensive way onto this list after the ~~$250 Bambino~~ → **$230
  KitchenAid**".
- **FAQ "cheapest 90+ on espresso":** "~~the Breville Bambino scores 65 at $250~~ → **the KitchenAid KES6503 scores
  60 at $230**".
- **FAQ question:** "Why is there nothing between ~~$250~~ → **$230** and $1,700 on this list?"

Everything else stays word for word. Each sentence that compares against the whole list still holds with the
KitchenAid in it: Linea Mini's 65 is still the lowest maintenance score (the KitchenAid's is 70), the Bezzera's 92
is still the fastest heat-up, the Mechanika's 82 is still the best maintenance score, and "six of the seven cost
$1,700 or more, four cost more than $4,800" is unchanged.

## The save, once you approve

Back up the landing-page row to `/root/backups/landing-semi-auto-before-budget-swap-2026-10-24.sql` → assert the
stored picks equal a fresh selection and every price equals its snapshot (the two guards) → write intro, picks,
FAQs → run the guide audit → expect FRESH. Undo: restore the backup row.
