# Backlog — deferred and unscheduled

One line per item. Nothing here is scheduled; promote a line to `todo.md` when it becomes next work.
Detail for every line is in `archive/2026-09-21-todo-monolith-snapshot.md` — search for the bold name or
the code in brackets. Codes (Q5, F12, H-E…) are kept only so the old text can be found.

## Security & data integrity

- [ ] **Queue job wipes feature values on re-categorise** — `ProcessPendingProduct` destroys all feature values on the hottest `category_id` write path; a 50-product "Retry Failed" erases previous scoring [H-E, audit 08-21]
- [ ] **Queue starvation** — every job shares `default` with 2 workers; a 100-product assign blocks live ingest for ~12 min [H-F]
- [ ] **Tenant trait missing on two models** — `AiCategoryRejection`, `ProductFeatureValue` [Q4]
- [ ] **Features created without explicit `tenant_id` in observers** [Q7]
- [ ] **ASIN validation accepts any string** [Q5] · **URL fields accept `data:` / `file:`** [Q6]
- [ ] **`addslashes()` used for JS escaping in templates** — use `@js()` [Q8]
- [ ] **CDN script without Subresource Integrity** [Q14]
- [ ] **Slug uniqueness not tenant-scoped in Filament** — CategoryResource, StoreResource [L11]
- [ ] **SEO dashboard has no access gate** — same as every other admin page; build gates system-wide or drop the requirement [F13]

## Import, Bouncer & AI

- [ ] **Duplicate variant rows** — SM58 family, pack sizes, colourways as separate products; within-category exact-ASIN duplicates too [F29, "Duplicate rows also occur WITHIN a category"]
- [ ] **`pw2d:ai-assign-categories` would put grain mills into manual-coffee-grinders** — tooling hazard, check before any tenant-wide run
- [ ] **Add the sweep step to the documented top-up sequence** — import → *read and sweep* → rescan → regenerate [Spec 031 amendment]
- [ ] **Trigger discovery on unbuyable share, not only pool size**
- [ ] **Batch import: one transaction + bulk insert** [P3] · **Extract `BatchImportService`** [Q3] · **`OfferIngestionRequest` Form Request** [Q2]
- [ ] **AI model strategy (Spec 037)** — replay harness `pw2d:ai:eval-model` [T2]; then `admin_model` 2.5 Pro → 3.7 Flash [T3]; Claude for landing-page prose only, after T3; `generateCategoryImage()` bypasses the cost log
- [ ] **AI cost log follow-ups** — null model string escapes the guard [A2]; 11 of 13 `purpose` strings untested [A3]; swallow log cannot reconstruct what was lost [A4]; `AiUsage` needs a tenant query contract [A5]; unpriced-model warning dedupes per instance [038 L2]; `usageMetadata` array guard [038 N1]; PHPDoc [038 N2]; forward `tenant_id` in five more methods if they ever queue [038 N4]
- [ ] **`Setting::get()` caches the first caller's default forever** [L12]

## Listing health & rescan

- [ ] **`unknown` condition overwrites a stored negative condition** [S4] · **negative-condition branch fires no freshness audit** [S5] · **`hasCleanOffer()` `> 0` turns the Clive price gap into product ignores** [S6] · **one audit job per rescanned offer** [S9]
- [ ] **`import_debt` may be un-clearable** — extension never sends `condition: 'unknown'`, so some offers are never stamped [H-D]
- [ ] **`price_drift` 15% threshold too loose for espresso-machine prices**
- [ ] **Weekly picks run never checks non-Amazon pick offers** — 4 Clive Coffee offers on c2d semi-automatic; La Marzocco Linea Mini's only offer is unverified; accept or spot-check monthly (owner decision)
- [ ] **Premium picks die first** — headsets and lavalier both went stale within 3 weeks of a rebuild on a premium high-price flag; if it repeats, prefer picks with a second offer
- [ ] **`(store_id, url(120))` prefix index on `product_offers`** [Perf M1] · **`url_hash` column** [Q12]

## Extension

- [ ] **Popup fixes, one bundle** — `flagged` tally ignores listing flags and offer-level condition flags (reads "flagged 0" while flags exist); garbled punctuation (no `<meta charset>` in `popup.html`)
- [ ] **"Unchecked only" rescan mode** — every top-up forces a full re-check of a just-swept category; touches the rescan endpoint + `popup.js` together
- [ ] **Amazon `reviews_count` extraction** — 88 products at 0; needs a 6th selector strategy [Spec 029 B3]
- [ ] **Clive Coffee price extraction misses some in-stock products**

## Landing pages & picks

- [ ] **Category-intent skew on podcast mics** — weighting rewards noise rejection, so handheld vocal mics dominate a page readers expect to be broadcast mics
- [ ] **Homepage and tile counts are unfiltered** — owner asked 08-20: "remember to fix homepage numbers later"; four callers count raw rows
- [ ] **`/best/` pages are near-orphaned** — one internal link each; downgraded 09-01 to an optional accelerant [F36]
- [ ] **Re-read the kept pick bodies against fresh score notes** — on 09-21 the six kept headset bodies and four kept keyboard bodies were re-verified for prices and score comparisons only; descriptive claims date from 08-14 / 08-28
- [ ] **Flaky, order-dependent `SelectLandingPicksTest`**

## SEO pipeline & dashboard

- [ ] **Spec 040 follow-ups** — GA4 reports have no pagination (limit 500, real volume ~7–30 rows a day) [S4]; `runReport()` seam so row parsing is testable and `close()` runs on exceptions [S1]; redact the click-failure log [N5]; one `deltaStat()` helper instead of six copies, and no red arrow for "no prior data" [N8]
- [ ] **GA4 property timezone vs UTC pulls** — mitigated by the 3-day window, not fixed [F15]
- [ ] **GA4 "STALE" is a low-traffic false positive** — relax or document [F34]
- [ ] **Live Google API paths are untested** — smoke command or recorded responses [F20] · **GA4 fixture is synthetic** [F18] · **dashboard isolation tests re-implement the SQL** [F17]
- [ ] **Dashboard widgets as real tables** — TopMovers [F8], PageTypeBreakdown [F9], free-text URL filter [F10]
- [ ] **Sitemap** — cache invalidation on save [F1]; index/chunking past 5k URLs [F2]; extract `SitemapBuilder` and share it with `UrlCoverageWidget` [F3, F11]
- [ ] **`ProblemProducts` uses raw `REGEXP`** — blocks admin HTTP tests on sqlite; two tests skipped because of it [F12]
- [ ] **Per-category `seo_description` field** [F32] · **`route('home')` in `forHomepage()`** [F6] · **pw2d.com central-vs-tenant mismatch** · **unused imports** [F16] · **shared test-tenant trait** [F4]
- [ ] **Unbuyable product pages stay indexed** — data favours `noindex` while unbuyable; owner decision

## Data cleanup & QA stragglers

- [ ] **Ergonomic pool leftovers** — 11 older keyboard+mouse combos still visible (do not affect today's picks); LEOBOG A80 RT gaming board sits in the ergonomic category
- [ ] **c2d duplicates** — 4 Breville Oracle Jet rows, 5 Jura Z10-family rows; owner decision whether the two Z10 rows are one machine or two generations
- [ ] **c2d detached products** — 8 rows, owner decision (4 look right, 3 are grain mills, 1 Oracle Jet)
- [ ] **Product 3352 carries feature values from two categories**
- [ ] **Unchecked or stuck rows** — Eureka Costanza R (3568, collection-scoped URL); ECM Estetika (4052, `status = failed`, can never be rescanned); #3292's Whole Latte Love offer

## Frontend & polish

- [ ] **N+1 in Filament resources** [L1] · **eager `Category::pluck` in a modal** [A6] · **6 missing DB indexes** [L8]
- [ ] **Duplicated price-note builder** [L3] · **duplicated typewriter animation** [L4] · **DB query in a Blade template** [L5]
- [ ] **Static pages hardcode "Pw2D"** [L6] · **hardcoded Amazon orange** [Q10] · **missing `strict_types` in ~15 files** [L7]
- [ ] **Dead files** — SearchLog page classes [L9], `welcome.blade.php` [L10]

## Growth & owner

- [ ] **c2d leaf #5: Electric Burr Grinders** — owner decision 08-09; launch checklist in the snapshot
- [ ] **File the stancl/tenancy bug upstream** — report is ready at `docs/bug-reports/stancl-tenancy-pk-leak.md` [F5]
