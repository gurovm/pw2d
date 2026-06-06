# Spec 022: GSC top_query + Product Review Schema

**Status:** Draft (architect handoff)
**Authors:** @architect (2026-06-06)
**Depends on:** Spec 014 (SEO pipeline), Spec 017 (ranged GSC call), Spec 018 (SeoSchema)
**Closes:** F30 (per-URL top_query — was F7 originally), adds Review schema
**Branch:** `feat/seo-top-query-and-review-022`

---

## 1. Motivation

Specs 018–021 + the hotfix landed the snippet-quality work for the current SERP problem. While Google recrawls (2–4 weeks), two bounded improvements compound:

1. **F30 — per-URL `top_query` data**: the `gsc_top_query` column in `seo_metrics` is null across the entire table. We're blind to what users actually search for. Without it, the next iteration decision (which titles to tune, which content to expand) will be made guessing. Building F30 now means in 2-4 weeks we have real query data flowing.

2. **Review schema on product pages**: each product's `ai_summary` is essentially a 2-sentence editorial review. Wrapping it in schema.org `Review` (author = Pw2D AI) compounds with the existing `aggregateRating` (Amazon-sourced) and is eligible for Google's review snippet enhancement. Tiny addition, real SERP value.

Both changes are small, both target the next data round.

## 2. Goals

- F30 — populate `gsc_top_query` on every `seo_metrics` row going forward
- Embed a `review` field on Product schema using `ai_summary` as `reviewBody`
- Tests for both

## 3. Non-goals

- Historical backfill of `gsc_top_query` for already-stored rows (forward-only)
- Critic Review rich result eligibility (would need `reviewRating` — we deliberately skip to avoid the "what's our score?" question)
- Top-query analytics dashboard widget (the data flows in; UI work is a separate spec if needed)
- Re-rendering existing summaries (the AI summary is already on the product)

---

## 4. F30 — per-URL top_query

### 4.1 Design

GSC API: when `dimensions` includes `'query'`, response rows are per (date, page, query) tuple. To get the **top query per (date, page)** without losing the page-level aggregate (impressions, clicks, ctr, position):

- Make TWO calls per window: existing per-(date, page) call (page-level totals) + new per-(date, page, query) call.
- Merge in PHP before upsert: each (date, page) row gets `top_query = the query with max impressions for that (date, page)`.

**Why two calls instead of one with all dimensions?**
- Mixing dimensions changes how GSC aggregates: with `query` included, per-page totals become sums of per-query rows, which can disagree with un-queried totals due to GSC's data-privacy rounding.
- Two calls keep the existing data semantics intact and just *add* top_query.

**Cost:** GSC API calls per nightly run double (was 1 per tenant post-F23, becomes 2 per tenant). Trivially within quota at current scale. At >100 tenants, becomes the same growth-trigger as F23 — note in todo.md.

### 4.2 Service changes — `app/Services/Seo/GoogleSearchConsoleService.php`

Add a new method:

```php
/**
 * Fetch per-(date, URL) top queries — the query with highest impressions
 * per (date, URL) tuple — across the given date range.
 *
 * Returns Collection<string, Collection<int, array{url: string, top_query: string, top_query_impressions: int}>>
 * keyed by Y-m-d.
 */
public function fetchTopQueriesForRange(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
```

Implementation mirrors `fetchUrlMetricsForRange()` but:
- `dimensions = ['date', 'page', 'query']`
- After pagination collects all rows, group by date → group by page → pick the per-(date, page) row with max impressions → that row's query becomes `top_query`
- Returns the date-keyed Collection with one row per (date, page) — same shape pattern as the existing method, with the top_query field.

Pagination: same loop as the existing method. Per-query rows multiply the result set ~5-10×; pagination must handle it.

### 4.3 Action changes — `app/Actions/Seo/PullGscMetrics.php`

In `execute()`:

1. Call `fetchUrlMetricsForRange()` (existing) → per-(date, url) page-level rows
2. Call `fetchTopQueriesForRange()` (new) → per-(date, url) top query
3. Merge by (date, url) key: each page row gains `top_query` from the matching top-queries row (or null if not present)
4. Build the upsert batch with `gsc_top_query` populated

If `fetchTopQueriesForRange()` throws but `fetchUrlMetricsForRange()` succeeded, log a warning and continue with `top_query=null` for all rows that window. Don't fail the whole pull because of the optional top-query data.

### 4.4 No schema migration needed

`seo_metrics.gsc_top_query` column already exists (Spec 014 migration). Just stops being null.

### 4.5 Tests

Modify `tests/Feature/Seo/Services/GoogleSearchConsoleServiceTest.php`:
- `fetchTopQueriesForRange returns date-keyed buckets with top_query per page` — multi-row response, assert each page's top_query is the max-impressions query
- `fetchTopQueriesForRange handles pagination` — page 1 fills chunk, page 2 has fewer; assert both pages merge correctly

Modify `tests/Feature/Seo/Actions/PullGscMetricsTest.php`:
- `top_query is merged from the second API call into upsert rows` — fake both service calls; assert upsert batch contains `gsc_top_query` values matching expected
- `top_query failure does not block the main pull` — fake `fetchTopQueriesForRange` to throw; assert `fetchUrlMetricsForRange` data still upserts (with null top_query) and a warning is logged

---

## 5. Product Review schema

### 5.1 Design

Embed a `review` field inside the existing Product schema in `SeoSchema::forSelectedProduct`, only when `ai_summary` is non-empty.

Shape:
```json
{
  "@context": "https://schema.org/",
  "@type": "Product",
  "name": "...",
  "aggregateRating": { ... existing ... },
  "review": {
    "@type": "Review",
    "reviewBody": "<the ai_summary text, plain text, HTML stripped>",
    "author": {
      "@type": "Organization",
      "name": "Pw2D"
    }
  },
  "offers": { ... existing ... }
}
```

### 5.2 Decisions

- **No `reviewRating`** — we don't publish a Pw2D score on a 1-5 scale; only the Match Score (0-100) which doesn't translate cleanly to schema.org `Rating`. Skipping avoids "the score is X but actually Y" confusion. Loses Critic Review rich result eligibility but the review is still valid markup that Google can use for snippet enhancement.
- **No `datePublished`** — we don't track when `ai_summary` was generated. Adding it as `updated_at` is misleading (last DB update ≠ when AI wrote the review). Omit; it's optional per schema.org.
- **Author = "Pw2D" Organization** — matches the tenant brand. Could use `tenant_seo('brand_name')` for true tenancy.
- **`reviewBody`**: `Str::limit(strip_tags($product->ai_summary), 1000)` to handle any inline HTML and cap length.

### 5.3 Where in code

`app/Support/SeoSchema.php::forSelectedProduct`, inside the `$schema` array construction, append `review` key after `aggregateRating` (line ~129) and before the Offer block.

### 5.4 Tests

Modify `tests/Feature/Seo/SeoSchemaTest.php`:
- `Product schema includes review when ai_summary is set` — assert `$schema['review']['@type'] === 'Review'`, `reviewBody` equals stripped/limited ai_summary, `author.name === 'Pw2D'` (or whatever tenant_seo returns)
- `Product schema omits review when ai_summary is empty/null` — assert `array_key_exists('review', $schema) === false`
- `review.reviewBody strips HTML and limits to 1000 chars` — seed ai_summary with `<p>` tags + long text; assert clean output

---

## 6. File-level summary

| File | Action |
|---|---|
| `app/Services/Seo/GoogleSearchConsoleService.php` | MODIFY — add `fetchTopQueriesForRange` |
| `app/Actions/Seo/PullGscMetrics.php` | MODIFY — call both services, merge top_query, error-isolate |
| `app/Support/SeoSchema.php` | MODIFY — `forSelectedProduct` adds `review` field |
| `tests/Feature/Seo/Services/GoogleSearchConsoleServiceTest.php` | MODIFY — new test methods |
| `tests/Feature/Seo/Actions/PullGscMetricsTest.php` | MODIFY — top_query merge tests |
| `tests/Feature/Seo/SeoSchemaTest.php` | MODIFY — Review schema tests |
| `docs/tasks/todo.md` | UPDATE — mark F30 [x], note 2x GSC call cost |

## 7. Acceptance

- [ ] `php artisan test --filter='Seo'` — all green (target: 162 → ~170+ passing)
- [ ] After deploy: nightly cron run produces `seo_metrics` rows with `gsc_top_query` populated (check via `mysql -e "SELECT COUNT(*) FROM seo_metrics WHERE gsc_top_query IS NOT NULL AND metric_date = CURDATE() - INTERVAL 3 DAY"`)
- [ ] After deploy: `curl https://pw2d.com/product/<slug>` shows `review` field inside Product schema with `reviewBody` matching the product's ai_summary
- [ ] Google Rich Results Test reports the Product schema as valid with the review field
- [ ] Within 1 week of recrawl: Search Console → Performance shows query-level data we can now correlate with our seo_metrics rows

## 8. Rollout

1. PR `feat(seo): top_query + Review schema (spec 022)`
2. Optional reviewer audit
3. Merge
4. `/deploy`
5. Wait for tonight's 03:00 UTC cron run. Verify `gsc_top_query` populated.
6. After 3 days of cron runs: query `seo_metrics` for top_query distribution by URL.

## 9. Growth-trigger note for F23 follow-up

GSC API call count per tenant per nightly run:
- Pre-F23: 4 (one per date in 4-day window)
- After F23: 1 (single ranged call)
- After F30 (this spec): **2** (one ranged call + one top-queries ranged call)

At current scale (1 active tenant) → 2 calls/night. At >100 tenants → 200+ calls/night, still within GSC quota. At >500 tenants, consider combining into a single call with `query` dimension and post-processing the page totals (the complexity-vs-cost trade-off we deliberately deferred in §4.1).
