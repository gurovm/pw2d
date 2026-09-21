# Spec 040 — Outbound (buy-button) clicks in the SEO pipeline

**Status:** BUILT · TESTED · REVIEWED — ready for owner `/deploy` (approved 2026-09-21) · **Date:** 2026-09-21 · **Size:** small (1 migration, 1 service
method, 1 action change, tests). No endpoint, extension, or AI changes.

## Why

The weekly SEO check measures Google → site (impressions, clicks, position). It has never measured
site → store, which is the only step that earns money. Until today that was believed to be blocked on
PostHog. It is not: GA4 Enhanced Measurement already records an automatic `click` event for every
outbound link, on both tenants, and the service account the nightly pull already uses can read it.

Verified live on prod 2026-09-21 (read-only probe, 28 days):

| Tenant | Outbound clicks | From | To |
|---|---|---|---|
| coffee2decide | 5 (5 users) | 5 product pages | amazon.com ×4, wholelattelove.com ×1 |
| pw2d | 1 | `/product/hyperx-cloud-iii-blackred-bd0cf` | amazon.com |

Against ~26 (c2d) and ~17 (pw2d) Google clicks in the same window. Tiny, but it is the number the whole
project exists to move, and it should sit in the same table as impressions and position.

## Design

**Store it nightly, next to the GA4 rows we already keep.** Ad-hoc probing works but leaves no trend, and
the check already reads everything else from `seo_metrics` with one SQL statement.

1. **Migration** — `seo_metrics.ga4_outbound_clicks` `unsignedInteger`, default `0`, after the existing
   `ga4_*` columns. Add to `SeoMetric::$fillable` and cast to `integer`. Update `docs/database-schema.md`.

2. **`GoogleAnalyticsService::fetchOutboundClicks(CarbonImmutable $date): Collection`**
   - `RunReportRequest`: dimension `pagePath`, metric `eventCount`, dimension filter
     `eventName` EXACT `click`, single-day date range, same `limit` config as the landing-page call.
   - Returns `Collection<int, array{url: string, clicks: int}>`.
   - **CORRECTED 2026-09-21 (architect error, caught on post-build verification): use `pagePath`, NOT
     `pagePathPlusQueryString`.** The first draft claimed `landingPage` includes the query string. It does
     not — prod has 0 of 1,959 GA4 rows with a `?` in `url` (that would be `landingPagePlusQueryString`).
     With the query string kept, a click on `/compare/x?preset=streamer` could never merge with the
     `/compare/x` landing row, and tracking params (`?srsltid=`, `?fbclid=`) would fragment the counts.
     Preset-level click splits are one ad-hoc GA4 query away if ever wanted.
   - Reuse the exact row-parsing helper and URL normalisation the landing-page method uses, so
     `url` / `url_hash` match existing rows byte-for-byte. If that logic is inline today, extract it to one
     private method used by both — do not duplicate it.

3. **`PullGa4Metrics::execute()`**
   - After the landing-page fetch, call `fetchOutboundClicks()` in **its own try/catch** (same pattern as
     the GSC top-query lookup): a failure appends a warning to `PullResult::$errors`-adjacent logging but
     does **not** fail the landing-page pull. Log with tenant + date.
   - Merge by URL into the single upsert batch **before** writing. A page that had a click but was not a
     landing page that day gets a row with zeroed session metrics and its click count.
   - One upsert, and `ga4_outbound_clicks` joins the update-columns list. Do not issue a second upsert
     that could zero the session columns.
   - **ADDED 2026-09-21: a failed click fetch must not erase history.** The batch seeds
     `ga4_outbound_clicks => 0`, so if the click call throws while re-pulling a date that already has a
     stored count (window backfills do exactly this), the upsert would overwrite the good value with 0.
     When the click fetch fails, leave `ga4_outbound_clicks` **out of the update-columns list** for that
     upsert (fresh inserts still get the column default 0).

4. **Backfill** — none to build. `pw2d:seo:pull {tenant} --ga4-window-days=56` already re-pulls a window;
   after deploy the owner/Claude runs it once per tenant and history appears. **56, not 35** (review S2):
   the dashboard card compares 28 days against the *prior* 28, so a 35-day backfill would leave 20 of the
   prior days at 0 and show a fake "+N%" for three weeks.
   **Acceptance check after the backfill:** per-tenant 28-day `SUM(ga4_outbound_clicks)` must be ≥ the
   2026-09-21 probe (c2d 5, pw2d 1). If it is 0, grep `storage/logs/laravel.log` for
   `outbound clicks failed` — a permanently broken click query is otherwise indistinguishable from "no clicks".

4b. **ADDED 2026-09-21 (review S3, confirmed on prod): nightly GA4 window 1 → 3 days.** The 03:00 pull
   read each date once, before GA4 had finished processing it. Measured over 14 days, stored GA4 sessions
   were **69% (c2d) and 42% (pw2d)** of what GA4 reports for the same dates now — a pre-existing undercount
   of every GA4 number since April, not just clicks. Re-pulls are safe (replace semantics + the failed-fetch
   guard), so the schedule passes `--ga4-window-days=3`. The 56-day backfill also repairs the session history.

5. **Dashboard (optional, same PR only if trivial)** — one extra stat on `KpiCardsWidget`: "Store clicks
   (28d)", explicit `tenant_id` like the other widget queries. Skip if it needs more than a few lines.

### Deliberately out of scope
- **No per-store breakdown column.** On these sites outbound links are store links almost exclusively; a
  per-domain split is one ad-hoc GA4 query away if ever needed.
- **No PostHog pipeline.** GA4 is the counting source (already wired, both tenants, has history).
  PostHog stays a manual tool for "what did they do before the click".
- **No change to `pw2d:seo:status` health logic.** Zero clicks on a day is normal, not STALE.

## How the weekly check uses it

Add to the procedure (owner applies to `.claude/commands/seo-status.md` — the command file is guarded
against self-edits; until then the query lives in the checkpoint doc):

```sql
SELECT tenant_id,
  SUM(CASE WHEN source='gsc' THEN gsc_clicks END)          AS google_clicks,
  SUM(CASE WHEN source='ga4' THEN ga4_outbound_clicks END) AS store_clicks
FROM seo_metrics WHERE metric_date >= CURDATE() - INTERVAL 28 DAY GROUP BY tenant_id;

SELECT SUBSTRING_INDEX(url,'.com',-1) AS path, SUM(ga4_outbound_clicks) AS store_clicks
FROM seo_metrics WHERE tenant_id='{tenant}' AND source='ga4'
  AND metric_date >= CURDATE() - INTERVAL 28 DAY
GROUP BY url HAVING store_clicks > 0 ORDER BY store_clicks DESC LIMIT 15;
```

Report line: **Google clicks → store clicks** per tenant, plus which pages produced them.

## Tests (Pest, `RefreshDatabase`, fake service via container binding — external API)

- Happy path: clicks land on the matching existing GA4 row for that URL + date.
- Click on a page with no landing-page row → row created, session metrics 0.
- Clicks on a compare page merge into that page's single path-only row (no `?` ever appears in a GA4
  `url`).
- `fetchOutboundClicks` throws → landing-page metrics still upserted, warning logged, exit code rule of
  Spec 017 unchanged.
- `fetchOutboundClicks` throws on a RE-PULL of a date with a stored click count → the stored count
  survives (not zeroed), while the session columns still update.
- Re-pull of the same date is idempotent (no double count — value is replaced, not incremented).
- Tenant isolation: tenant A's pull never writes tenant B rows.
- Landing-page columns are not zeroed by the merge (regression guard for the single-upsert rule).

## Tasks
- [x] builder — migration, model, service method (+ shared parser extraction), action merge, schema doc
- [x] tester — 16 tests (`PullGa4OutboundClicksTest` 12, `GoogleAnalyticsServiceTest` 4), no bugs found;
      full suite 853 passed / 21 skipped (pre-existing) / 0 failed
- [x] reviewer — SHIP WITH FIXES, 0 blockers (`docs/reviews/review-2026-09-21-spec-040.md`). Refactor
      parity with HEAD verified by the architect via `git diff` (the reviewer has no shell).
- [x] builder — review fix round: nightly window 3 days, comment accuracy, single `buildRow()`, fetch-then-merge
      (architect re-ran the full suite: 853 passed / 21 skipped / 0 failed)
- [ ] owner — `/deploy`, then one **56-day** backfill per tenant + the acceptance check; add the SQL to the
      seo-status command file
