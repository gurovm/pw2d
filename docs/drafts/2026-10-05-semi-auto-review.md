# Semi-automatic espresso — post-import review (2026-10-05)

Pool: 135 → **193 live machines, 232 listings** (prod, 11:20 UTC). Imports today: Clive 2 pages, WLL 7 pages
(semi-automatic collection, pages 3–7 sorted price high→low), Amazon 2 searches × 2 pages. Gemini: ~170
evaluations, ~$2.50. Every accepted title was read.

## Import outcome

- **Clive + WLL:** ~40 new machines, almost all $1,000–9,400 (Dalla Corte Mina, La Marzocco GS3 MP, Crem ONE ×3,
  Quick Mill ×5, Bezzera ×9, ECM ×5, Profitec Pro 800, Arkel Coast, Lelit Mara X3/Anna, Rocket Porta Via).
- **Amazon:** 18 kept (budget end, $130–630). The gate moved 11 super-automatics out (Magnifica, Dinamica,
  PrimaDonna, Philips LatteGo, Bosch 300, KitchenAid KF3, Ninja AutoBarista) and rejected ~45 no-name machines.
- **Fixed today (owner-approved, backed up):** Estetika and Appartamento TCA duplicates; Rocket/Rocket Espresso
  brand merge; Quick Mill + Eureka bundle hidden; WLL "Manufacturer Marked" Silvia M listing deleted.

## Decisions for the owner

### 1. Hide — wrong product type, imported in March before the gate (per record)
| id | machine | why |
|---|---|---|
| 3447 | KitchenAid KF6 | fully automatic (bean-to-cup) |
| 3450 | KitchenAid KF7 | fully automatic |
| 3411 | Philips 5500 LatteGo | fully automatic |
| 3424 | Ninja CFN601 | Nespresso-capsule machine |
| 3442 | Breville Bambino Plus + Baratza Encore ESP | machine + grinder bundle |
| 3430 | Cuisinart Espresso Bar EM-550 | **unsure** — Amazon title says "fully automatic"; check the listing |

### 2. Brand fixes (move products, then remove the empty brand; brand delete cascades products, so empty-check first)
- **DeLonghi (9 products) → De'Longhi** (38). Two rows for one brand: the picks guard compares brand ids, so a
  De'Longhi and a DeLonghi copy of one machine both count as different.
- **"Saeco" (2) → Gaggia:** 3372 Classic Evo Pro Lobster Red, 3313 E24 — both Gaggia RI9380 machines.
- **"Luxe Café" (2) → Ninja:** 3301 Luxe Café Premier, 3297 Luxe Café Pro.

### 3. "With Flow Control" — same machine or a different one?
LUCCA M58 (3619 + 5088), ECM Synchronika II, Classika PID, Technika VI, Bezzera Aria PID, Profitec RIDE, JUMP.
Flow control is a factory paddle option (+$100) on the same machine. **Draft treats it as the same model** (one
product, the guide can't recommend both). Different = separate products that compete as near-duplicates.

### 4. Three names that describe a different machine (rename: page title changes)
- 3599 "LUCCA A53 Pro" — listing is the **A53 Direct Plumb** (Clive sells both; the A53 Pro listing was lost).
- 3586 "DeLonghi Dedica Maestro Plus" — listing is the **Dedica Arte EC885**.
- 3366 "Gaggia RI9380/49 Classic Evo Pro" — listing is the **Classic E24 (RI9380/46)**.

### 5. Model names — after the rescan
Draft for 135 machines in `docs/drafts/2026-10-05-semi-auto-models.json`. After the rescan: `pw2d:products:apply-models coffee2decide
<file> --dry-run` → owner reviews the same-model groups and the page-impact diff → apply. Expected groups: colour
copies (Bambino Plus ×4, Barista Express ×4, Dedica Duo ×4, Silvia ×3, Silvia Pro X ×3, Barista Touch ×3, Gaggia
Classic Evo Pro ×3), Ninja Luxe Café Premier/Pro (after the brand fix), plus the flow-control pairs if (3) = same.
Not drafted (model unknown from the title): Gevi 3404, Krups 3435.

## Left to the rescan
Remanufactured / renewed listings (Breville RM-BES870, RM-BES880, La Specialista Arte Evo "Renewed") — the rescan's
condition check flags them per listing; no hide needed unless a product has no clean listing left.
