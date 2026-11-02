# Mechanical Gaming Keyboards clean-up before the rebuild — draft for owner review (2026-10-29)

The sweep (10-29: 200 of 203 listings checked; 3 not refreshed, none a pick) is done. Before the guide is rebuilt the
category gets the same clean-up lavalier got on 10-28: models, plus hiding what the category notes already exclude.
Nothing below is written until you approve.

## Why it matters for the page

No keyboard has a model today (0 of 203), so the picker can't tell that two listings are the same keyboard.
Dry run with models only, before the hides (nothing written):

| slot | now | with models |
|---|---|---|
| Best overall | Keychron K8 HE ($130) | Keychron Q5 Pro, gray listing ($116) |
| Best budget | AULA F87 ($70) | Keychron C3 Pro 8K ($31) |
| Streamer | Glorious GMMK 3 PRO HE | an ASUS keyboard + mouse **bundle**, which the hides below remove |
| Hobbyist | Keychron Q6 Max | Glorious GMMK 3 PRO HE |

Two of the new prices look like sales: the other two Q5 Pro colours cost $220, and the C3 Pro without 8K costs $50.
That is the KitchenAid case again: the price is real today, and the slot moves when the sale ends. The final picks are
computed after the hides, in the guide draft.

## 1. Models: 188 of 203 → `2026-10-29-mech-keyboards-models.json`

The category rule: a different size or generation is a different model; switch type and colour are not. 25 groups
count as one keyboard (60 listings). The judgement calls:

- **Same model:** the 5 Keychron C1 listings (switch / backlight / hot-swap options); Falchion Ace HFX and its ZywOo
  Edition; Corsair K65 Plus Wireless and its Mac version; Logitech G512 Carbon and G512 SE; Huntsman V3 Pro TKL and
  its Counter-Strike 2 Edition ($92 vs $270); Mistel MD600 Alpha BT RGB and non-RGB; the 3 Q5 Pro colours.
- **Kept apart:** C1 Pro vs C1 Pro 8K; C3 Pro vs C3 Pro 8K; SteelSeries Apex Pro TKL (first gen) vs Gen 3 vs Wireless;
  BlackWidow V4 TKL vs V4 Low Profile TKL; Redragon K673 PRO vs K673 MAX.
- **No model (4):** #2738 "AULA 99 Key Keyboard - Pink" and #2479 "Redragon 60% Mechanical Gaming Keyboard" (the
  title names no model); #2505 "Redragon Wireless Mechanical Keyboard" (same); #2504, see §4.

When unsure I kept listings apart, which is how the picker treats them today.

## 2. Hide: 11 products, per record through the model

- **Keyboard + mouse bundles (4):** #2799 ROG Strix Scope II 96 + Harpe mouse · #2793 ROG Strix Scope II 96 + Spatha X
  mouse · #2656 Logitech G305 + G515 TKL · #2495 Redragon S101-3 PRO keyboard and mouse
- **Membrane boards the notes name (5):** #2735 Logitech G213 Prodigy · #2679 SteelSeries Apex 3 TKL · #2676 Razer
  Ornata V3 · #2642 Razer Ornata V3 TKL Hello Kitty · #2503 Razer Ornata V3 X
- **Your call (1):** #5019 SteelSeries Apex 5. Its "hybrid mechanical" switch is a membrane with a click on top, which
  is the same kind as the Ornata. I suggest hiding it.
- **Dead listing (1):** #5001 Wooting TenZ Takeover. Its only listing returns "Page Not Found", it has no price, and it
  errored in today's sweep.

## 3. Brand fixes: 5 products

- #5010 Mercury K1 Lite: "Mercury" → **GravaStar**
- #5057 Kick75: "Kick75" → **NuPhy**
- #5041 MAKR PRO 75: "MAKR" → **Corsair**
- #5045 Origins 2 1800, #5046 Origins 2 65: "Origins" → **HyperX**

Not changed here: ASUS and ASUS ROG, and Logitech and Logitech G, are each two brand rows for one maker. The page's
brand cap counts them separately. Merging them touches other categories, so it's a separate decision.

## 4. Name fixes: 2 products

- #2445 "Redragon K631 Gery" → **"Redragon K628 Pollux"**. The listing is the K628 Pollux 75%, 78 keys. Model K628.
- #2504 "Redragon Wired Mechanical Gaming Keyboard K636CLO" → **"Redragon K688SP PRO"**. The listing is a K688SP-RGB-PRO
  96% three-mode wireless keyboard. Model K688SP PRO.

## Order of writes after approval

Back up first. Then models → hides → brand fixes → renames, then a price-band check, then the guide draft for your
review.
