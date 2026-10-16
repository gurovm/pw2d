# PostHog session-replay review + PostHog setup fixes — 2026-10-16

Source: PostHog EU project 133580. All 116 recordings and 30 days of events (2026-09-16 → 10-16) were
read through the PostHog API, and one replay was watched end to end. **Numbers are small**:
coffee2decide has ~5 real visitors/day and pw2d ~1–2/day, ~140 real sessions in total.

## 1. PostHog changes made (live now)

| Setting | Before | After |
|---|---|---|
| "Filter out internal and test users" | `$geoip_country_name` **equals** Israel → the toggle showed *only* our own visits; local `pw2d.lcl:8000` (84 events) not excluded | `$host` regex `^(www\.)?(pw2d\.com\|coffee2decide\.com)$` AND `$geoip_country_code` is not IL, CN |
| Filter on by default for new insights | off | on |
| Dead-click capture | off | on |
| Action **Store click (Check Current Price)** (id 169198) | none | `$autocapture` with href matching `amazon.` / `amzn.to` / `wholelattelove.com` |
| Saved insight **coffee2decide: visit → store click (by device)** (`BFrlzHNY`) | none | baseline 110 visitors → 17 store clickers = **15.5%**, median 17 s to click |

**China traffic is bots.** 64 pw2d sessions and 16 coffee2decide sessions in 30 days, none with a click, all
lasting 0–2 seconds. Most of pw2d's "direct" sessions are these. IPs are anonymised in PostHog, so
country is the only usable filter.

**A/B test of the drawer: skipped on purpose.** Only ~13 real visitors landed on a compare page in 30
days, which is far too few to settle a test. Ship the change and compare before/after on the saved funnel.

## 2. Findings (coffee2decide, bots and own visits excluded)

| Landing page | Sessions | Clicked to a store |
|---|---|---|
| Product, desktop | 39 | 21% |
| Product, mobile | 46 | 15% |
| Best / compare / vs | ~32 | ~6% |

1. **The people who click came to click.** Most of them land on a product page from Google and click
   out within 2–60 s, without reading (median time to click: 12 s desktop, 44 s mobile).
2. **Mobile visitors who don't click also don't scroll.** On mobile product pages the median scroll is
   0%, 29 of 38 page-leaves saw less than 30% of the page, and 17 of 46 mobile visitors left within 10 s.
   The sticky "Check Current Price" bar is visible the whole time, so the button is not hidden.
3. **The most-clicked control on product pages is the X that closes the product modal** (39 clicks in 26
   sessions), not the price button. People want alternatives, but the browse path rarely converts:
   1 of 13 compare-page landers clicked to a store.
4. **The auto-opened Customize drawer (Spec 025 §2, Issue 2) costs more than it gives.** It fires on every
   first compare-page visit, and on mobile it covers the whole screen with 7 sliders. About 8 of 18 real
   visitors who got it left within 25 s with zero clicks. Only 2 used a preset, and 1 moved a slider.
   Replay `01a0fbbb-6757-731f-9f0d-8d6e3e7a2951` (DE, mobile) shows the pattern: 4.5 min reading a
   product, then the manual-grinders best list and its FAQ. On the compare page the drawer popped, and
   the visitor left 9 s later without ever clicking a price.
5. **The FAQ may not open on tap.** In the same replay: five taps in a row on "What does external grind
   adjustment actually change…" (`/best/manual-coffee-grinders`), recorded as a rage-click on the
   question's `<span>`.
6. **Misleading copy.** The product page tells a cold visitor "78% Personalized Match — based on your
   precise slider configurations", even though they never touched a slider.
7. **Speed is not the problem.** LCP p75 ~1.7 s on both devices, INP and CLS fine.
8. **Replays are partly missing.** posthog-js logs `[SessionRecording] Too many mutations on node` and
   throttles that node. Likely an animated element (score ring or sliders).

pw2d: 4 store clicks in 30 days. That is too little traffic to read anything.
