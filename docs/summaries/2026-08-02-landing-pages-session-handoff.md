# Session Handoff — 2026-07-05 → 2026-08-02 (Spec 026/027 shipped, landing pages live, c2d climbing)

**Audience:** the next Claude session picking up SEO/product work on pw2d.com + coffee2decide.com
**Owner:** Michael (gurovm@gmail.com)
**Date written:** 2026-08-02
**Prod HEAD at session end:** `a35523f` · **Test suite:** 457 passed / 10 skipped

---

## TL;DR

The Jul-10 verdict fired the **pivot to off-page/authority**, and this session shipped its code half:
**Spec 027 — `/best/{slug}` landing pages** — now LIVE on all 4 demand-center categories (pw2d
keyboards ×2, c2d super-auto + manual grinders), submitted to GSC on Aug 2. Along the way, owner QA
forced three rounds of hardening that made the whole pipeline better: a human-prose + grounding
style contract for AI content, renewed/refurbished detection at four layers, and a sitewide
Tailwind-v4 tenant-color fix. Spec 026 (GSC Product-snippet error) also shipped and validated.
**coffee2decide is climbing hard** — weighted position 54→29 in four weeks, first 5 clicks — while
pw2d stays plateaued exactly as the authority verdict predicted.

**Next check ~Aug 9:** first `/best/` GSC read + cannibalization watch + remaining-categories batch
decision + c2d leaf #5-6 selection.

---

## State of the two sites (2026-08-02, GSC through Jul 30)

| | pw2d.com | coffee2decide.com |
|---|---|---|
| 28d impressions | 2,199 (fully plateaued) | 1,177 (climbing) |
| 28d clicks | 6 | 5 (first ever, started wk28) |
| Weighted pos trend | flat, presets stuck 10-27 | **54 → 29 in 4 weeks** |
| Landing pages | 2 live (`/best/mechanical-gaming-keyboards`, `/best/productivity-ergonomic-keyboards`) | 2 live (`/best/super-automatic-espresso-machines`, `/best/manual-coffee-grinders`) |
| Verdict | authority-capped; off-page is the lever | fresh-site climb; let it run |

pw2d target queries: "rsi keyboard" is now the widest preset surface (44 impr, pos 12.4); streamer
~11.5. Nothing breaks the ~10 ceiling — consistent for 6 weeks.

---

## What shipped this session

1. **Spec 026** (Jul 5, `0c17162`): rating-less products downgraded to URL-only ListItems in compare
   ItemList — fixed the first c2d GSC Product-snippet error (JURA X10). Owner validated in GSC.
2. **GA4/GSC for c2d unblocked** (Jul 12): owner granted service-account access; root cause of
   lingering GA4 failure was a **wrong `ga4_property_id`** on the tenant (`properties/15199060859` →
   corrected to `properties/544169093` via tinker). Both sources HEALTHY since; zero data lost.
3. **Spec 027 — Best-X landing pages** (Jul 19 → Aug 1, `a35523f`). Full architecture in
   `docs/specs/027-best-x-landing-pages.md` (+ Addendum A). Key traits:
   - `/best/{slug}` — **year in title, NOT in URL** (permanent link target).
   - Deterministic pick selection (`SelectLandingPagePicks`) — data picks products, AI only writes prose.
   - Plain Blade + controller (no Livewire), 1h tenant-scoped cache, invalidated via model hooks
     keyed off `$this->tenant_id` (never ambient tenancy).
   - Schema: ItemList reuses the 026 rating gate (shared `buildListItem()`), FAQPage, breadcrumbs,
     zero price keys.
   - Draft-first: `pw2d:generate-landing-page {tenant} {category} [--dry-run|--regenerate|--publish]`,
     Filament `LandingPageResource` publish toggle (no AI call to publish).
   - Compare pages cross-link published guides; sitemap includes published only.
4. **AI content style contract** (owner-driven, in `AiService::generateLandingPageContent`):
   banned AI-tells (clichés, "Whether you're..." triads, uniform rhythm) + **grounding rules**
   (payload facts only, never mention listing condition, never "explain" prices from world
   knowledge, real product counts / price deltas). See memory `ai-content-style-bar`. Apply to ALL
   future generators.
5. **Renewed/refurbished defense in depth** (owner found TWO renewed listings among picks; the
   second had completely clean DB data — only the live Amazon page reveals it):
   - Import guards at all 3 paths (BatchImport, ProductImport, OfferIngestion) — server no longer
     trusts extension filters.
   - AI Bouncer `evaluateProduct()` prompt: renewed/refurb/open-box → is_ignored (REMOVED an old
     carve-out that explicitly protected refurbished units!).
   - Pick selection excludes condition-marked products + near-duplicate names (≥85% similar).
   - `pw2d:flag-condition-products {tenant} [--ignore] [--urls --category=]` — marker audit +
     per-category URL tables for MANUAL browser verification (Amazon blocks server-side fetches;
     the Chrome extension is the only reliable live detector → **F37**).
6. **Sitewide Tailwind v4 fix**: `tenant-*` color utilities were NEVER in compiled CSS (v4 ignores
   `tailwind.config.js`); registered via `@theme` in `resources/css/app.css`. Fixed invisible
   badges/elements across the whole site, not just landing pages.
7. **Prod data surgery** (Aug 1-2): merged exact-name duplicates (6 pw2d + 7 c2d); ignored renewed
   G915s (#2704 + #2646-adjacent); nulled 8 dangling `image_path`s (accessor falls back to offer
   CDN image — G715 image bug); **AI-swept super-auto category** (4 semi-autos detached: Barista
   Touch ×2, Oracle Jet, Philips Barista Brew) before regenerating that page's picks.

---

## Open items (owner)

- [ ] **Condition audits await `--ignore`**: pw2d 33, c2d 2 (`pw2d:flag-condition-products {tenant}`).
- [ ] **Re-home the 4 detached semi-autos** into `semi-automatic-manual-espresso-machines` (Filament
      category assign + feature rescan) — they're good products, currently homeless.
- [ ] **First data-study/outreach push** — the founder-led half of the authority pivot. Landing pages
      are the link targets; suggested first study: espresso price-vs-feature-score from the ~190-machine
      dataset, pitched to coffee blogs linking `/best/super-automatic-espresso-machines`.
- [ ] c2d GA4 frontend settings still unset (`ga_measurement_id`, `posthog_key`) — no frontend
      analytics fire on c2d.

## Open items (next session)

- [ ] **~Aug 9 status check**: first `/best/` GSC data; cannibalization watch ("best X" queries should
      move to landing pages, "compare X" + presets stay); c2d climb continuation; then the
      **remaining-categories batch** (pw2d mics/headsets/lavalier; c2d semi-auto/kettles/pour-over/
      cold-brew) — generate all, publish only credible pick tables (command aborts <5 picks = quality floor).
- [ ] **c2d leaf #5-6 selection** (~Aug 9): super-auto demand dominance persists; electric-burr-grinders
      lean holds (11 seed products detached and waiting).
- [ ] **F37**: Chrome-extension "verify condition" mode — the only reliable renewed detector. Bundle
      with the reviews_count extraction fix (both extension work).
- [ ] **F38**: offer-refresh paths drop `image_url`/stock (extension re-scan can't repair images).
      Bundle with Q1 (offer unique-constraint — CONFIRMED firing in prod logs).
- [ ] **Ergonomic page follow-ups**: Q11 pair (#2859/#2831) possibly same product under 2 ASINs —
      owner published as-is; check + maybe tune the similarity guard. Cross-category dup exists too
      (K8 HE #2761 pw2d-gaming vs #3021 pw2d-ergonomic, same ASIN — invisible to merge-duplicates).
- [ ] **evaluateProduct grounding guardrail** + clean the ~32 ai_summaries containing condition words
      (they leak into product-page schema descriptions). Do before Spec-028 (product content depth).
- [ ] Spec-028 candidate (product-page content depth) — supported by evidence: product pages enter
      GSC at pos 20-39 vs compare pages at 50-80 on c2d.

---

## Hard lessons added this session

1. **Tailwind v4 ignores `tailwind.config.js`** — tokens must live in CSS `@theme`. Grep compiled CSS
   for a class before assuming it exists; new Blade files need `npm run build` locally.
2. **AI content needs BOTH a style contract and a grounding contract** — killing clichés made prose
   convincing, which made its one fabricated fact ("renewed model") more dangerous, and that "fabrication"
   turned out to be REAL (polluted upstream ai_summary + an actually-renewed listing). Verify surprising
   generated claims against source data AND the live listing.
3. **Amazon blocks server-side fetches** — live listing verification must go through a real browser
   (manual click-through now, extension mode F37 later). Also puts a question mark over
   sync-offer-prices' Amazon coverage.
4. **Data can be clean while the listing is dirty**: renewed listings can leave zero trace in stored
   titles/summaries. Text-marker audits are necessary but not sufficient.
5. **`image_url` accessor prefers a local path without checking file existence** — dangling paths render
   as broken images while a live CDN fallback sits unused (8 cases found and nulled).
6. **Old prompts accumulate contradictions** — the Bouncer explicitly protected refurbished units while
   we were trying to keep them out. Read the whole prompt when changing policy.
7. **Local viewing of tenant pages** needs a tenant domain (`pw2d.lcl` in /etc/hosts + domains row);
   localhost is central and 404s tenant-only routes. Prod DB snapshot: `ssh root@… mysqldump | mysql`.

## Quick reference

```bash
/seo-status pw2d | coffee2decide      # weekly checkpoint (checkpoint doc has all baselines)
php artisan pw2d:generate-landing-page {tenant} {category} [--dry-run|--regenerate|--publish]
php artisan pw2d:flag-condition-products {tenant} [--ignore] [--urls --category={slug}]
php artisan pw2d:ai-sweep-category {tenant} {slug} --dry-run
php artisan pw2d:merge-duplicates {tenant} --dry-run
# Local preview: http://pw2d.lcl:8000/best/{slug} (tenant domain; localhost 404s)
# Live URLs: pw2d.com/best/mechanical-gaming-keyboards · /best/productivity-ergonomic-keyboards
#            coffee2decide.com/best/super-automatic-espresso-machines · /best/manual-coffee-grinders
```

**Prior docs:** `2026-07-04-two-site-session-handoff.md` (previous era), checkpoint trail in
`2026-06-13-seo-status-checkpoint.md` (updated through 2026-08-02), Spec 027 + Addendum A + review
in `docs/specs/` / `docs/reviews/`.
