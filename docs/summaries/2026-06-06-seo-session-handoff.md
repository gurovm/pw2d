# SEO Session Handoff — 2026-06-06

**Audience:** the next Claude session picking up SEO work on pw2d.com
**Owner:** Michael (gurovm@gmail.com)
**Date written:** 2026-06-06

---

## TL;DR

Pw2D shipped five SEO specs (018–022) plus one critical hotfix in a single architect-driven session, all merged to `main` and deployed to production. The work targeted a stark baseline: **1,226 products, 7% indexation rate, 276 impressions / 28 days / 0 clicks, avg position 17.4**. The thesis is that the on-page content + structured-data foundation now compounds enough that real CTR signal will appear within 2–4 weeks. **The next session's primary job is to look at the GSC numbers after recrawl and decide whether the foundation worked or whether the problem is off-page (brand authority / backlinks).** Until then, don't pile on more on-page work — wait for the signal.

There are TWO standing policies you must respect (see [memory](#critical-memory--policy-constraints)).

---

## What was shipped this session

All on the SEO surface, in chronological order. Specs are in `docs/specs/`.

| # | Title | Closes | Why it mattered |
|---|---|---|---|
| 018 | [Schema fixes pack](../specs/018-seo-schema-fixes-pack.md) | F-image, F-offer, F-meta | Absolute image URL in Product schema (was relative — invalidated all 1,226 products); Offer block (later trimmed); compare-page meta description templated (was the buying-guide intro dump) |
| 019 | [Drop price from Offer](../specs/019-seo-schema-no-price.md) | Amazon ToS | Removed `price`/`priceCurrency` from Offer schema. Scraped prices were 60 days stale; emitting them as machine-readable was a ToS violation scanners can detect. See policy below. |
| 020 | [Titles + BreadcrumbList](../specs/020-seo-titles-breadcrumb.md) | F27, F28 | Product titles inject category name; `BreadcrumbList` schema on product + leaf-category pages |
| 021 | [Compare page content depth](../specs/021-seo-compare-content-depth.md) | F-content | New `intro` / `methodology` / `faqs` keys in `category.buying_guide`. AI-generated via `pw2d:generate-compare-content`. Renders in Blade + emits FAQPage schema |
| **Hotfix** | [emit all JSON-LD schemas](https://github.com/gurovm/pw2d/pull/9) | bug | **Critical bug discovered post-021-deploy**: layout emitted only `schemas[0]`. Spec 020 BreadcrumbList AND Spec 021 FAQPage shipped but never rendered to Google until this fix. See [Lessons](#hard-lessons-learned-this-session). |
| 022 | [top_query + Review schema](../specs/022-seo-top-query-and-review.md) | F30 | GSC `gsc_top_query` column now populated (was always null); Product schema embeds `Review` field using `ai_summary` as `reviewBody` |

Plus operational work:
- **System cron hook** installed on prod (`www-data` crontab) — discovered missing during this session's first prod check, causing 34 days of zero SEO data
- **34-day GSC + GA4 backfill** ran via `pw2d:seo:pull pw2d --gsc-window-days=35 --ga4-window-days=35` after the cron hook fix. Recovered 107 GSC + 1,272 GA4 rows from April 9 → May 13
- **AI content populated** for all 5 active categories on prod via `pw2d:generate-compare-content pw2d`. Cost: ~$0.20 total

---

## Critical memory + policy constraints

Two standing policies are saved in the project memory at `~/.claude/projects/-Users-mg-projects-power-to-decide-pw2d/memory/`. **Read them before suggesting anything price-related or Amazon-API-related.**

### [amazon-associates-strategy](../../.claude/projects/-Users-mg-projects-power-to-decide-pw2d/memory/amazon-associates-strategy.md) (project memory)

Pw2D is **deliberately deferring** Amazon Associates and PA-API registration until organic traffic exists. Reason: Associates approval starts a **180-day window to make 3 qualifying sales** — connecting before traffic guarantees the test fails.

**What this means in practice:**
- Do NOT propose scheduling `pw2d:sync-offer-prices` — scraping cadence doesn't matter because we can't legally display the prices anyway
- Do NOT propose PA-API integration as a "next step"
- Do NOT propose adding `Offer.price`/`Offer.priceCurrency` to schema
- When the owner is ready, the sequence is: traffic → Associates approval → PA-API → re-add price to schema with PA-API-fresh data

### [seo-schema-policy](../../.claude/projects/-Users-mg-projects-power-to-decide-pw2d/memory/seo-schema-policy.md) (feedback memory)

Concrete schema rules driven by the above:
- `SeoSchema::forSelectedProduct`'s Offer block emits `@type`, `availability`, `url`, `seller` — **never** `price` or `priceCurrency`
- On-page human-visible prices use `Product::estimated_price` (rounded to nearest $5/$10) — never `best_price` raw
- `SeoSchemaTest` asserts the *absence* of `price`/`priceCurrency` keys — those assertions are load-bearing

---

## Current production state

### Per-page schema stack (verified post-hotfix)

**Compare pages (`/compare/{slug}`) emit 3 JSON-LD blocks:**
- `ItemList` — top 12 ranked products
- `BreadcrumbList` — Home → (Parent) → Category
- `FAQPage` — 4-6 Q&A from category's `buying_guide.faqs`

**Product pages (`/product/{slug}`) emit 2 JSON-LD blocks:**
- `Product` — with embedded `aggregateRating` (Amazon), `review` (Pw2D AI editorial via `ai_summary`), `offers` (availability + url + seller, no price)
- `BreadcrumbList` — Home → (Parent) → Category → Product

### SEO pipeline (nightly)

- Cron at 03:00 UTC: `pw2d:seo:pull` rolling 4-day GSC window + 1-day GA4 window
- GSC API calls per tenant per night: **2** (page-level + per-query) — within quota at current scale
- Idempotent upserts via unique `(tenant_id, source, url_hash, metric_date)` index
- Status check: `pw2d:seo:status` (last verified: 2 HEALTHY, 0 STALE)

### Tenant SEO config (prod)

- `pw2d` — configured (GSC site + GA4 property), `seo_enabled=true`
- `coffee2decide` — `seo_enabled=false` (intentional)

### Categories with AI content (prod)

All 5 active leaf categories have `intro`, `methodology`, `faqs` populated in `buying_guide` JSON:
- podcast-studio-mics
- mechanical-gaming-keyboards
- productivity-ergonomic-keyboards
- wireless-lavalier-systems
- gaming-chat-headsets (5th — name from memory, verify with `php artisan tinker`)

Admin can edit per-category in Filament → Categories → Edit → Buying Guide section.

### GSC baseline (as of 2026-06-05, before recrawl signal)

This is the number to beat:

| Metric | Value |
|---|---|
| Pages with impressions (28d) | 97 |
| Total impressions (28d) | 276 |
| Total clicks (28d) | **0** |
| Average position | 17.4 |
| Products in DB | 1,226 |
| Products with any GSC data | 85 (7%) |

---

## What we expect to see in the future

### Timeline of expected signals after deploy (2026-06-06)

| When | What to check | Expected |
|---|---|---|
| **Tomorrow (2026-06-07)** morning | `seo_metrics.gsc_top_query` populated (cron ran tonight) | Most rows non-null. Check: `SELECT COUNT(*) FROM seo_metrics WHERE gsc_top_query IS NOT NULL AND metric_date >= CURDATE() - INTERVAL 4 DAY` |
| **3–5 days** | Google starts showing recrawled URLs with new titles + breadcrumb in SERP | Visual check via "site:pw2d.com" Google search |
| **1–2 weeks** | Search Console → Enhancements → Products / Breadcrumbs / **FAQ** | All three should show "valid items" — FAQ is the new one from Spec 021 |
| **2–4 weeks** | GSC Performance → CTR moves off 0% | If thesis is right. If still 0%, see [decision tree](#what-to-do-when-signal-arrives) |
| **2–4 weeks** | New gsc_top_query data should reveal what queries are matching titles vs which aren't | Use this to tune titles for the next iteration |

### What to do when signal arrives

**If CTR moves above 0% within 4 weeks:**
- The schema + content thesis is validated
- Use `gsc_top_query` data to identify which titles match user intent and which don't
- Likely next move: tune title patterns per category (extend F28 — different patterns for different category types)
- Then consider F29 narrowly-scoped (color/finish variant cleanup — see investigation below)
- Maybe spec a content-depth Phase 2: per-product reviews longer than the current 2-sentence `ai_summary`

**If impressions grow but CTR stays 0%:**
- Snippets are showing but unappealing vs competitors (Amazon, Wirecutter)
- Re-examine titles + meta-descriptions against `gsc_top_query` data
- Consider running content depth more aggressively — current `intro` is 100–150 words; competitors have 1000+
- Consider `WebSite` + `SearchAction` schema for sitelinks search box (small visible win)

**If impressions don't grow:**
- The foundation isn't the bottleneck — authority is
- This is **off-page work** (backlinks, content marketing, social) — not code
- Code-side, the next reasonable move is auto-generated landing pages targeting specific high-intent commercial queries (`/best-podcast-mics-2026`)
- Or: pause SEO and validate the product/site value-prop first via other channels

---

## Open follow-ups in [todo.md](../tasks/todo.md)

Status of every F-series item from this session's work:

| ID | Status | Notes |
|---|---|---|
| F14 | `[x]` Resolved by 018 | SeoMetric model rewrite |
| F19 | `[x]` Shipped in 016 | GSC backfill window |
| F23 | `[x]` Shipped in 017 | Single ranged GSC call |
| F24 | `[x]` Shipped in 017 | SeoStatusCommand SQL polish |
| F25 | `[x]` Shipped in 017 | pw2d:seo:pull exit code |
| F26 | `[x]` Shipped in 017 | Cron hook documentation |
| F27 | `[x]` Shipped in 020 | BreadcrumbList schema |
| F28 | `[x]` Shipped in 020 | Product title pattern |
| F30 | `[x]` Shipped in 022 | Per-URL top_query |
| **F29** | **Investigated, deferred** | Variant cleanup. Initial estimate was 43% catalog reduction; **deep investigation in this session revealed the real number is ~10–15%** because most "variants" are genuinely different products (HyperX Cloud II vs Alpha vs III). Color/finish variants and SKU-code dupes are the easy wins. See investigation summary in session history. |
| **F31** | Open | Compare page weight (167 KB). Core Web Vitals. Defer products below-fold via Livewire lazy load. ~half day. |
| **F32** | Open | Per-category `seo_description` column. Adds admin override of the Spec 018 template. Marginal value — only matters if specific categories need custom snippets. ~hour. |

Plus older items from the April audit (Q1–L12) still in todo.md untouched.

### Known dead code

- `AiService::generateCompareContent` strips markdown code fences, but `GeminiService::generate` already strips them before returning. The double-strip is harmless defensive code but technically dead. Flagged during Spec 021 audit, not blocking. Cleanup is ~3 lines.

---

## Hard lessons learned this session

These are not bugs to re-fix — they're patterns to NOT repeat.

### 1. The `schemas[0]` bug ate Specs 020 + 021 silently

`app/Livewire/ProductCompare.php` and `app/Livewire/Home.php` encoded only `$seo['schemas'][0]` for the layout. Every schema after index 0 was silently dropped. Specs 020 (BreadcrumbList) and 021 (FAQPage) both shipped with full test coverage and **never reached Google** until the hotfix (PR #9).

**Why tests didn't catch it:** the SEO schema tests use `Livewire::test()` which bypasses the layout. The bug was in the layout. The post-hotfix regression tests use full HTTP render (`$this->get()`) which exposes the layout — that's the pattern future schema tests must use.

### 2. The F24 binding-order bug surfaced only at HTTP-level

Spec 017's `SeoStatusCommand::fetchAggregates` initially used `DB::table(DB::raw())->mergeBindings()` for a two-subquery LEFT JOIN. Bindings prepended in the wrong order under tenant-filter mode. Six tests failed at tester time; switched to `DB::query()->fromSub($leftSub, 'a')`. **Lesson:** when an SQL refactor "verifies in Tinker," only test the path the verifier ran. If there are conditional branches (here: `whereIn` only when filtered), test both.

### 3. The 34-day silent cron failure

System cron hook for `php artisan schedule:run` was never installed. Laravel scheduler showed `schedule:list` correctly, but nothing fired it. Discovered when the new `pw2d:seo:status` command shipped and surfaced 34 days of zero data. Spec 017 §6 added the verification to the `/deploy` command's step 9.

**Lesson:** "registered with Laravel" ≠ "firing via OS cron." Always verify both. Check `crontab -l -u www-data` after any prod scheduler change.

### 4. The 60-day-stale price discovery

When Spec 018 added `Offer.price` from `Product::best_price`, the on-prod price data turned out to be 60 days stale. `pw2d:sync-offer-prices` was never scheduled. Surfacing exact stale prices in machine-readable schema was both a ToS violation and an embarrassing-mid-launch problem. Spec 019 reverted it.

**Lesson:** Before exposing a database field in user-visible/Google-visible context, check that the field is fresh. The fact that the team had already built `estimated_price` (rounded) was a strong signal we should have used it from the start.

### 5. The investigation matters

Initial F29 framing claimed 43% catalog consolidation potential. The deep investigation (sampling 8 representative clusters across 94 products) revealed the truth is ~10–15%. The original number was based on naive prefix-matching that over-merges product lines. Most of the time was saved by NOT building a complex variant-detection system that would have over-merged things and required manual rollback.

**Lesson:** Big numbers in early diagnosis are estimates. Quantify before speccing.

---

## Files to know

### Code surface

| File | Why it matters |
|---|---|
| `app/Support/SeoSchema.php` | The source of truth for all schema markup. Touch with care — read the policy memory first. |
| `app/Services/Seo/GoogleSearchConsoleService.php` | GSC API layer. `fetchUrlMetricsForRange()` + `fetchTopQueriesForRange()` (the second is from Spec 022) |
| `app/Services/Seo/GoogleAnalyticsService.php` | GA4 layer |
| `app/Actions/Seo/PullGscMetrics.php` | Orchestrates both GSC calls + merges top_query |
| `app/Actions/Seo/PullSeoMetrics.php` | Top-level orchestrator |
| `app/Console/Commands/Seo/PullSeoMetricsCommand.php` | `pw2d:seo:pull` |
| `app/Console/Commands/Seo/SeoStatusCommand.php` | `pw2d:seo:status` |
| `app/Console/Commands/GenerateCompareContent.php` | `pw2d:generate-compare-content` (Spec 021) |
| `app/Services/AiService.php` | `generateCompareContent()` for Spec 021. AI calls MUST go through this. |
| `app/Livewire/ProductCompare.php` | The compare/product page Livewire component. Layout-data assembly. |
| `resources/views/livewire/product-compare.blade.php` | The main view. Renders intro + tabs + methodology + FAQ partial. |
| `resources/views/livewire/partials/compare-faqs.blade.php` | FAQ accordion partial (Alpine.js) |
| `resources/views/components/layouts/app.blade.php` | Layout. Where the hotfix landed — now loops `$schemasJson` array. |
| `app/Filament/Resources/CategoryResource.php` | Admin edit surface for `buying_guide` keys including the 3 new from Spec 021 |
| `docs/seo/operations.md` | Runbook for SEO on-call. Tour, manual backfill, content generation, failure modes. |

### Memory pointers

- `~/.claude/projects/-Users-mg-projects-power-to-decide-pw2d/memory/MEMORY.md` — index
- `amazon-associates-strategy.md` — see above
- `seo-schema-policy.md` — see above

---

## Quick verification commands

```bash
# Live status on prod
ssh root@209.97.153.234 'cd /var/www/pw2d && php artisan pw2d:seo:status'

# Schema counts on a category page (expect 3)
curl -s https://pw2d.com/compare/podcast-studio-mics | grep -c 'application/ld+json'

# Schema counts on a product page (expect 2)
curl -s https://pw2d.com/product/redragon-k668-nlnoc | grep -c 'application/ld+json'

# Verify Review schema is in Product
curl -s https://pw2d.com/product/redragon-k668-nlnoc | python3 -c "
import sys, re, json
m = re.search(r'<script type=\"application/ld\+json\">(\{.*?\})</script>', sys.stdin.read())
print('Has review:', 'review' in json.loads(m.group(1)))
"

# top_query rows after tonight's cron (run this 2026-06-07 or later)
ssh root@209.97.153.234 'cd /var/www/pw2d && mysql -u root pw2d -e "
  SELECT metric_date, COUNT(*) FROM seo_metrics
  WHERE tenant_id=\"pw2d\" AND source=\"gsc\" AND gsc_top_query IS NOT NULL
  GROUP BY metric_date ORDER BY metric_date DESC LIMIT 7;
"'

# Local test suite
php artisan test --filter='Seo|Ai|Compare'    # expect 202 passing
```

---

## When the next session starts

If the user opens with "check SEO status" or similar, the right opening moves are:

1. Run `php artisan pw2d:seo:status` on prod
2. Pull the GSC 28-day numbers (impressions, clicks, CTR, position) and compare to the **baseline above**
3. If `gsc_top_query` data is populated, query the top 20 (url, top_query, impressions) combinations to see what's matching
4. Don't propose new specs until you've looked at the numbers

If the user says "what should we do next" without showing you data:
1. Reference [What to do when signal arrives](#what-to-do-when-signal-arrives)
2. Default recommendation: **wait for the signal** if it's been <3 weeks since 2026-06-06
3. If >3 weeks: time to look at the data and pick a path based on what's visible

If the user proposes adding price to schema or scheduling sync-offer-prices: **don't agree**, point them at the [memory constraints](#critical-memory--policy-constraints), confirm the strategy is unchanged.

---

## Commits this session (in order)

| Commit | Spec | What |
|---|---|---|
| `4e5002f` | 016 | (Pre-session) Status command + F19 backfill + ops runbook |
| `939fae6` | 017 | F23/F24/F25/F26 hardening |
| `ee8b6a4` | 018 | Schema fixes pack |
| `10b70db` | 019 | Drop price from Offer |
| `8014aa1` | 020 | Titles + BreadcrumbList |
| `2ae7e58` | 021 | Compare-page content depth |
| `2bfa532` | hotfix | Emit all JSON-LD schemas |
| `c681f61` | 022 | top_query + Review schema |

All on `main`. All deployed to prod (209.97.153.234, path `/var/www/pw2d`).
