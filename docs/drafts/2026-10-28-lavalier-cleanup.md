# Lavalier clean-up before the rebuild — draft for owner review (2026-10-28)

The sweep (10-27: 177 of 178 listings checked, 1 error) is done. Before the guide is rebuilt, the category gets the
same clean-up the three coffee categories got: models, plus hiding what the category notes already exclude.
Nothing below is written until you approve.

## Why it matters for the page

Without models the picker treats "DJI Mic", "DJI Mic 2" and "DJI Mic 3" as **one product**. With the DJI Mic as
best overall, the other two were blocked, and the presenter and filmmaker slots fell to a **Sennheiser MKE 600 (a
wired shotgun mic)** and a $770 Sony UWP-D26. With models (dry run, rolled back), the picks are:

| slot | product | price |
|---|---|---|
| Best overall | DJI Mic (1 TX + 1 RX) — new on the page | $85 |
| Best budget | Rode Wireless Micro (was best overall) | $100 |
| Premium | Shure GLXD14+/93 | $690 |
| Vlogger | DJI Mic 2 | $199 |
| Presenter | DJI Mic 3 | $259 |
| Filmmaker | Rode Wireless GO III | $157 |
| 7th | Hollyland Lark M2 (was best budget) | $70 |

Out: Rode Lavalier GO. The budget pick costs more than the overall and 7th picks. The picker fills "budget" from the
lowest price band by score, and the Rode scores higher than the Lark. The page will say so plainly.

## 1. Models — 167 of 178 products → `2026-10-28-lavalier-models.json`

18 groups count as one product (same model, different colour, plug, transmitter count or frequency band):
      Hohem / "MIC-01" (2)
          #2168 Hohem MIC-01 Wireless Lavalier Microphone
          #2222 Hohem MIC-01
      Hollyland / "Lark MAX 2" (2)
          #2172 Hollyland Lark MAX 2
          #2252 Hollyland Lark MAX 2 Combo-4 Person Version
      Hollyland / "Lark M2S" (2)
          #2179 Hollyland Lark M2S Ultimate Combo
          #4619 Hollyland Lark M2S Wireless Microphone with USB-C Plug
      DJI / "Mic 2" (2)
          #2200 DJI Mic 2
          #2277 DJI Mic 2 (1 TX + 1 RX)
      Rode / "Wireless Micro" (2)
          #2220 Rode Wireless Micro
          #2275 Rode Wireless Micro White
      Saramonic / "BlinkMe" (2)
          #2232 Saramonic BlinkMe U2
          #4661 Saramonic BlinkMe B2
      Synco / "G2A2 Pro" (2)
          #2233 Synco G2(A2) Pro Dual Wireless Lavalier Mic
          #2345 SYNCO G2(A2) Wireless Lavalier Microphone
      BOYA / "BY-V4" (2)
          #2249 Boya BY-V4U
          #2376 Boya BY-V4D
      Saramonic / "Blink 500 ProX" (4)
          #2283 Saramonic Blink 500 Pro X B4
          #4659 Saramonic Blink 500 Pro X B2
          #4663 Saramonic Blink 500 ProX B1
          #4664 Saramonic Blink 500 ProX B2R
      Shure / "BLX1288/CVL" (3)
          #2300 Shure BLX1288/CVL
          #4777 BLX1288/CVL Dual-Channel Wireless Audio System
          #4853 Shure BLX1288/CVL Dual-Channel Wireless Audio System
      Rode / "Wireless ME" (2)
          #4556 Rode Wireless ME
          #4592 Rode Wireless ME Dual
      Shure / "MoveMic" (2)
          #4611 Shure MoveMic Two
          #4728 Shure MoveMic One
      Hollyland / "Lark A1" (2)
          #4625 Hollyland Lark A1 Wireless Mini Microphone for iPhone
          #4644 Hollyland Lark A1 Combo
      Saramonic / "Blink 500" (6)
          #4668 Saramonic Blink 500 B5
          #4670 Saramonic Blink500
          #4671 Saramonic Blink 500 B4
          #4672 Saramonic Blink 500 B3
          #4673 Saramonic Blink500 B2W
          #4680 Saramonic Blink 500 B6
      Saramonic / "Blink 900 S" (2)
          #4676 Saramonic Blink900 S1
          #4686 Saramonic Blink900 S6
      Saramonic / "UwMic9" (2)
          #4682 Saramonic UwMIC9 Single Kit
          #4685 Saramonic UwMic9 RX9+TX9+TX9
      Sony / "UWP-D21" (2)
          #4803 Sony UWP-D21 Band 14
          #4810 Sony UWP-D21 Band 25
      Alvoxcon / "Wireless Headset Lavalier System" (2)
          #4870 Alvoxcon Wireless Lavalier System
          #4900 Alvoxcon Wireless Lavalier System

11 left without a model (generic names or unclear listings): 2187 Godox, 2327 Fulaim, 2424 Synco (see §3), 4684
Saramonic 2-person, 4858 / 4861 Tonor 4-channel (one $50, one $160 — not the same kit), 4860 Pyle, 4882 Hotec,
4892 VeGue, 2328 / 2329 Tonor (hidden below).

## 2. Hide — the category notes already exclude these (11)

Notes: *exclude shotgun and on-camera mics, wired handheld vocal mics, karaoke sets, wireless handheld vocal systems,
standalone receivers.* All hidden per record through the model (`is_ignored`), backed up first.

| id | product | why |
|---|---|---|
| 4724 | Sennheiser MKE 600 | wired shotgun mic; its own summary says the "wireless" title is keyword stuffing |
| 4826 | COMICA VM30 | shotgun mic |
| 4649 | Hollyland Lark MAX 2 OWS | monitoring earphones, not a mic (the sweep's 1 error) |
| 4721 | Sennheiser EM-XSW 1-A | receiver sold alone |
| 4601 | DJI Mic 2 Transmitter | spare transmitter sold alone |
| 4678 | Saramonic Blink 800 B2 | plug-on transmitter for handheld XLR mics |
| 4681 | Saramonic Blink 800 B3 | guitar / instrument transmitter |
| 4720 | Sennheiser XSW-D Instrument Base Set | instrument transmitter |
| 4715 | Sennheiser XSW 1-825 Dual | two handheld vocal mics |
| 2329 | Tonor TW-820 | two handheld dynamic mics |
| 2328 | Tonor Wireless Microphones System | handheld mics |

**Your call (3):** 4731 Shure MoveMic 88+ (a stereo video mic, closer to on-camera than lavalier), 4590 MAONO DGM20W
("wireless gaming microphone"), 4867 Debra AU400 Pro (listing doesn't say whether the 4 mics are handheld).
My suggestion: hide the first two, keep the Debra.

## 3. Per-record fixes (8)

| id | now | change |
|---|---|---|
| 4657 | brand SmallRig, "Magic XT1 Wireless Lavalier Microphone" | listing says GODOX → brand GODOX; it is then the same product as 2243 Godox Magic XT1 → hide 4657 (2243 keeps its listing) |
| 4622 | brand "Stage", "Stage Wireless Mini Microphone" | listing says Belkin → brand Belkin (**new brand row**: pw2d has no Belkin yet), name "Belkin Stage Wireless Mini" |
| 4660 | brand "Blink", "Blink M2 Wireless Lavalier Microphone for Cameras" | listing says Saramonic → brand Saramonic, name "Saramonic Blink M2" |
| 4578 | brand "Wave", "Wave T5 Wireless Lavalier Microphone" | listing says MAONO → brand MAONO, name "MAONO Wave T5" |
| 2424 | "Synco G2(A2) Pro" | the listing now reads **SYNCO G4**: a different product behind the same link → leave it, flag for the next import |
| 4777 | "BLX1288/CVL Dual-Channel Wireless Audio System" | **not a duplicate**: H10 band → "Shure BLX1288/CVL H10" |
| 4853 | "Shure BLX1288/CVL Dual-Channel Wireless Audio System" | H9 band → "Shure BLX1288/CVL H9" |
| 2300 | "Shure BLX1288/CVL" | J11 band → "Shure BLX1288/CVL J11" |

## Apply (after approval)

Back up the affected rows → hides and brand fixes per record → `pw2d:products:apply-models pw2d … ` (no dry run) →
re-run the picker read-only and check it matches the table above → then the page draft for your review.

## Applied 2026-10-28 (owner: "agree for cleanup")

- Your-call rows as suggested: MoveMic 88+ and DGM20W hidden, Debra kept. 14 hidden in all (11 + 2 + 4657); live 178 → 164.
- Brand row Belkin created; 4622, 4660, 4578 re-branded and renamed; 4657 → GODOX, then hidden; BLX1288/CVL ×3 renamed by band.
- `apply-models` refuses hidden products, so the 12 hidden ids with a model were dropped from the file: **155 models written**
  (164 live, 9 without). Same 18 groups and the same page impact as the dry run above.
- Fresh selection matches the table above, in order. Backup + logs: `/root/backups/2026-10-28-lavalier/`
  (`products-cat10-before.sql` 434 rows, `brands-pw2d-before.sql`, `apply-cleanup.log`, `apply-models.log`).
- Page draft: `2026-10-28-lavalier-guide-draft.md`.
