# Draft — /best/mechanical-gaming-keyboards: full rebuild after the 2026-10-29 clean-up

**SAVED 2026-11-01** (owner: "yes"; Wireless preset left off): four guards passed (picks still last checked 10-29), audit
FRESH. Row backup: `/root/backups/2026-11-01-mech-keyboards/landing-mech-keyboards-before-rebuild.sql`; script and log
`save-mk-guide.php`, `save-guide.log`.

**For:** owner review. Nothing is saved until you approve. Source of truth for the save: `2026-10-29-mech-keyboards-guide.json`;
the live page as it stands is kept in `2026-10-29-mech-keyboards-live-before.json`.

**Why:** the page dates from 09-21 and is STALE (selection and price drift). The clean-up you approved today (189 models,
11 hides, 5 brand fixes, 2 renames; `2026-10-29-mech-keyboards-cleanup.md`) is applied. A fresh selection keeps 3 of the 7
products, and one more moves to another slot:
- Best overall: Keychron K8 HE → **Keychron Q5 Pro (gray)**, $120 (new on the page)
- Best budget: AULA F87 → **Keychron C3 Pro 8K**, $30 (new)
- Best for hobbyists: Keychron Q6 Max → **Glorious GMMK 3 PRO HE**, $200 (moved from streamer)
- Best for streamers: GMMK 3 PRO HE → **Razer BlackWidow V4 Low Profile**, $200 (new)
- Unchanged: Keychron Q2 Max $230 (premium), ASUS ROG Azoth Extreme $400 (pro gamer), NuPhy Air75 HE $130 (7th)

The old text is rewritten, not patched. Its overall, budget, hobbyist and streamer blocks describe the wrong products,
it counted 204 keyboards (now 192), and one FAQ ("Why do keyboard prices swing so much month to month?") explains prices,
which the content rules now forbid.

**The editorial calls to check:**
1. **Keychron holds three slots, the limit for one brand, so the three "best for" slots go to other brands.** On raw scores
   the Q2 Max tops all three of those rankings, and Keychron boards take the top eight hobbyist places and seven of the top
   eight streamer places. The intro, the hobbyist and streamer blocks and the first FAQ say so. The awkward result: the
   streamer slot weights typing feel most, and the Razer has the lowest typing score on the page (75). Its block says so,
   and leans on what is true and relevant: quiet switches, a control roller, and 92 on wireless.
2. **The Q5 Pro's $120 is for the gray listing only; the other two Q5 Pro colours cost $220.** It's the KitchenAid case
   again: the price is real today, and if it ends, the audit flags price drift and the overall slot may move. The page
   doesn't mention the other colours (that would be explaining a price). Likewise, the C3 Pro **8K** at $30 is cheaper
   than the plain C3 Pro ($50). The page names the 8K version and doesn't compare the two.
3. **The NuPhy's "out of stock" label in admin is stale; a correction to what I told you earlier.** Today's sweep read a
   $129.95 buy-box price, and the extension never sends a price from a page that says "out of stock" or "currently
   unavailable". The label is left over from an earlier check: the server keeps the old stock label when a page doesn't
   state one. Nothing on the public site reads that label, so no code fix is needed. The same applies to the podcast
   mics' sE V7 MK ($149 on 10-24).
4. **The Wireless preset isn't on the page, by accident rather than by choice.** All four keyboard presets have sort order 0,
   so "the top three presets by sort order" falls back to database order: Pro Gamer, Hobbyist, Streamer. If you'd rather
   have Wireless in place of one of them, that's a sort-order change in admin and a re-run of the selection; tell me
   which slot it should replace. Otherwise I'll leave it.

**Checks run:** banned phrases (shared `ProseStyle` list): clean, 26 blocks · listing-condition words: none · every number
traced by hand to the pick scores, prices, ratings and the selection ranking run on prod today · picks equal a fresh
selection, in order: yes · no sentence explains why a price is what it is · intro 155 words, pick blocks 125–155, FAQ
answers 59–80.

---

## Intro

We scored all 192 mechanical gaming keyboards in our database on six things — gaming responsiveness, typing feel and acoustics, build quality, software and customization, ergonomics and layout, and wireless performance — and ranked them on those scores, with price and store ratings counted in. The seven below run from $30 to $400.

Two things are worth knowing first. Keychron holds three of the seven slots, and three is as many as we give any one brand. On raw scores the Keychron Q2 Max, our premium pick, would top all three "best for" rankings too, and for hobbyists and streamers more Keychron boards come right after it. So those three slots went to the best keyboards from other brands. And our best overall pick scores 70 on gaming responsiveness, tied for the lowest here. It earns its place on typing, build and software. If competitive games are your main use, start with the pro gamer pick or the NuPhy.

## Best overall — Keychron Q5 Pro Gray ($120)

**The best software score here, for $120**

It came first in our overall ranking. Software and customization is 98, the best on this page, and per our data that means QMK/VIA: open-source remapping, macros and layers. Build quality is 95 and typing feel is 90. Per the listing it's a 96% board with a programmable knob, double-shot PBT keycaps and hot-swappable K Pro Brown switches.

The obvious comparison is our premium pick, the Q2 Max. It costs $110 more and beats this board by 15 points on gaming, 5 on typing, 3 on build and 10 on wireless. Software is a tie in practice, 98 to 97. The Q5 Pro's 96% layout also keeps more keys than the Q2 Max's 65%.

The drawback is gaming. 70 on responsiveness ties for the lowest here, and per our data tactile switches like these aren't everyone's choice for competitive play. Its 5-star store rating comes from 7 reviews, so give that little weight. If you type all day and game now and then, this is the one we'd buy.

## Best budget — Keychron C3 Pro ($30)

**QMK and a gasket mount for $30**

This is the 8K version of the C3 Pro. Per the listing it polls at 8000Hz and puts hot-swappable Super Brown switches in a gasket-mounted TKL case. It placed fifth in our overall ranking, ahead of keyboards that cost four times as much.

Software is 88, the best on this page after the two Q boards, and per our data it's the same QMK/VIA support they have. It also has 138 store reviews, more than any other pick here, at 4.4 stars.

The drawback is the case. Per our data it's mostly plastic, and 68 on build quality is the lowest on this page. It's wired only, so there's no wireless score, and typing feel is 78, 12 points under the Q5 Pro. For $90 less, that's a trade we'd take.

## Premium — Keychron Q2 Max Wireless Black ($230)

**The best typing and build scores here**

Typing feel is 95 and build quality is 98, both the best on this page. Per our data it's a gasket-mounted 65% board in a CNC aluminum case, with QMK/VIA behind a software score of 97. Wireless is 90, over 2.4GHz or Bluetooth 5.1. It's the top-scoring keyboard in the highest price band.

On raw scores it would also win all three of our "best for" slots: pro gamer, hobbyist and streamer. Each keyboard appears once here, so it stays in this one, and the other slots go to the next-best boards.

The drawback is gaming next to the dedicated gaming picks. 85 on responsiveness trails the 95 that the Azoth, the GMMK and the Razer each score. Ergonomics is 75: per our data the typing angle is fixed and there's no wrist rest. Its 4.7-star rating comes from 10 reviews.

## Best for pro gamers — ASUS ROG Azoth Extreme Black ($400)

**Top gaming and wireless scores, at $400**

The pro gamer slot weights gaming responsiveness far above everything else, then build quality. The Azoth scores 95 on gaming, tied for the best here, and 92 on build. Wireless is 93, the best on this page, and per our data that's ASUS's SpeedNova 2.4GHz link. Per the listing it's a 75% board with NX Snow linear switches, PBT keycaps, a gasket mount, a carbon fiber plate in an aluminum case, and a color OLED screen.

On these weights the Keychron Q2 Max scores a little higher. It already holds the premium slot, so this one goes to the Azoth.

The drawback is price. At $400 it's the most expensive keyboard here, and the GMMK 3 PRO HE and the Razer both match its 95 on gaming for $200. Over the GMMK, the extra $200 buys 5 points of typing feel and 18 of wireless, and gives up 7 of ergonomics. Software is 78, tied for the lowest here, and per our data Armoury Crate can be resource-heavy.

## Best for hobbyists — Glorious GMMK 3 PRO HE ($200)

**Magnetic or MX switches in one aluminum case**

The hobbyist slot weights build quality and typing feel most, then software. On those weights Keychron's Q boards take the top eight places. Keychron already has three slots here, so this one went to the best board from another brand. The GMMK scores 92 on build, 85 on typing and 78 on software.

Per the listing it's a 75% board with a modular aluminum body and a modular gasket system, and its hot-swap sockets take both standard MX switches and magnetic Hall Effect ones. Per our data that's where its customization is unmatched. It's quick too: 95 on gaming, with rapid trigger and an 8K polling rate.

The drawback is the store rating. 3.7 stars from 17 reviews is the lowest on this page, and per our data the Glorious Core software is clunky and buggy. If you don't mind another Keychron, the Q2 Max scores higher on these weights for $30 more.

## Best for streamers — Razer BlackWidow V4 Low Profile HyperSpeed Wireless ($200)

**Quiet low-profile switches and a control roller**

The streamer slot weights typing feel and software most, then gaming. Keychron boards take seven of the top eight places on those weights, and the one that isn't a Keychron, the Azoth, holds the pro gamer slot. This one went to the best board left: the Razer, with 85 on software, 95 on gaming and 75 on typing feel.

Per our data its low-profile Yellow linear switches are smooth and quiet, which counts when your mic sits next to the keyboard. It also has a multi-function roller and dedicated control buttons. Wireless is 92, over HyperSpeed or Bluetooth, and ergonomics is 82, helped by the low profile.

The drawback is typing feel. 75 is the lowest on this page, and per our data the linear switches lack the tactile feedback many typists want. The ABS keycaps may also shine with long use. If you type more than you play, the $120 Q5 Pro scores 90 there.

## Also on the list — NuPhy Air75 HE ($130)

**Rapid trigger and the best ergonomics score, for $130**

The NuPhy came third in our overall ranking. Second was another Keychron, and Keychron is at its limit here. Per the listing it's a wired, low-profile 75% board with magnetic Jade switches, rapid trigger and adjustable actuation. Gaming responsiveness is 92, 3 points under the best here, for $70 less than the GMMK.

Ergonomics is 90, the best on this page, and per our data the low profile lets you type comfortably without a wrist rest. Typing feel is 85 and build quality is 88, with an aluminum top frame and PBT keycaps.

The drawback is that it's wired only, so there's no wireless score. Software is 78, tied for the lowest here: per our data the web-based configurator sets actuation points fine but lacks polish. Against the $120 Q5 Pro it's 22 points ahead on gaming and 20 behind on software.

## FAQs

**Why are three of the seven picks Keychrons, but none of the "best for" picks?**

Three is as many slots as we give one brand. Keychron boards won best overall (the Q5 Pro), best budget (the C3 Pro) and premium (the Q2 Max), so the three "best for" slots went to the best keyboards from other brands. On raw scores the Q2 Max tops all three of those rankings, and Keychron boards take the top eight places for hobbyists. If you want a Keychron for any of those uses, the $230 Q2 Max is the one.

**Which keyboard here is best for competitive gaming?**

Three tie at 95 on gaming responsiveness: the $400 ASUS ROG Azoth Extreme, the $200 Glorious GMMK 3 PRO HE and the $200 Razer BlackWidow V4 Low Profile. Per the listing the GMMK has magnetic Hall Effect switches, rapid trigger and an 8K polling rate. The $130 NuPhy Air75 HE scores 92 with magnetic switches and rapid trigger, but it's wired. We'd choose between the GMMK and the NuPhy, depending on whether you need wireless.

**Keychron Q5 Pro or Q2 Max: which should I buy?**

The $120 Q5 Pro, for most people. It's $110 less, it edges the Q2 Max on software, 98 to 97, and its 96% layout keeps more keys. The $230 Q2 Max is ahead on gaming, 85 to 70, and on wireless, 90 to 80, and it has the best typing and build scores on this page. Buy the Q2 Max if you game as much as you type and don't need the extra keys.

**Which of these are wired only?**

Two, per their listings: the $30 Keychron C3 Pro and the $130 NuPhy Air75 HE. The other five are wireless. The best wireless scores here are the ASUS ROG Azoth Extreme at 93, the Razer BlackWidow V4 Low Profile at 92 and the Keychron Q2 Max at 90. The GMMK 3 PRO HE is lowest at 75, and per our data its battery drains fast at 8K polling with bright RGB.

**How many keyboards did you compare to get to these seven?**

192, every mechanical gaming keyboard in our database for this category. Each is scored 0 to 100 on six features: gaming responsiveness, typing feel and acoustics, build quality and durability, software and customization, ergonomics and layout, and wireless performance. The overall ranking counts the six equally, with store ratings and price counted in; the three "best for" picks use their own weights.

## Methodology note

In the overall ranking every feature score counts equally, alongside a store customer rating where we have one and a credit for cheaper price tiers; each preset pick uses that preset's own feature weights instead. Every pick's listing was checked on October 29, 2026.

## The save, once you approve

Back up the landing-page row → piped script with four guards (live row equals `…-live-before.json`; picks equal a fresh selection
in order; every price equals its snapshot; `ProseStyle` violations empty) → write intro, picks, FAQs, methodology → run
`pw2d:landing-pages:audit` → expect FRESH. Undo: restore the backup row.
