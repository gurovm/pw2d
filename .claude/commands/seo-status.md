# SEO Status Check

Weekly SEO checkpoint. Argument: tenant id (default `pw2d`; use `coffee2decide` once its GSC is connected).

Prod: `ssh root@209.97.153.234`, app at `/var/www/pw2d`, DB via `mysql -u root pw2d`.

## Before you start — standing policies (do not violate)

- **Never** propose adding `Offer.price`/`priceCurrency` to schema, scheduling `pw2d:sync-offer-prices`, or PA-API integration. See memory: `amazon-associates-strategy` + `seo-schema-policy`.
- Never deploy from this skill. Deployment is `/deploy` only.
- Baselines and the running decision log live in `docs/summaries/2026-06-13-seo-status-checkpoint.md` (append dated UPDATE sections there; keep the table trajectory going).

## Procedure

1. **Pipeline health:** `php artisan pw2d:seo:status` on prod.
   - GSC has a normal ~3-day lag.
   - **GA4 "STALE" is usually a false positive at low traffic** (F34): GA4 only writes rows for URLs with sessions, so quiet days lag the threshold. Before alarming, confirm GSC rows are advancing and there are no GA4 errors in `storage/logs/laravel.log`.

2. **28d aggregate**, diff against the baseline table in the checkpoint doc:
   ```sql
   SELECT COUNT(DISTINCT url_hash) AS pages, SUM(gsc_impressions) AS impr, SUM(gsc_clicks) AS clicks,
     ROUND(SUM(gsc_impressions*gsc_position)/NULLIF(SUM(gsc_impressions),0),1) AS wpos,
     ROUND(100*SUM(gsc_clicks)/NULLIF(SUM(gsc_impressions),0),2) AS ctr_pct
   FROM seo_metrics WHERE tenant_id='{tenant}' AND source='gsc'
     AND metric_date >= CURDATE() - INTERVAL 28 DAY;
   ```
   ⚠️ **Composition artifact:** `wpos` is impression-weighted. New long-tail pages enter at pos 30–50 and drag the average down while nothing real got worse. Never judge from the aggregate alone — always check the target queries (step 3).

3. **Target queries** (the pages that matter — preset compare URLs):
   ```sql
   SELECT gsc_top_query AS query, SUBSTRING_INDEX(url,'{tenant-domain}',-1) AS path,
          SUM(gsc_impressions) AS impr, SUM(gsc_clicks) AS clicks, ROUND(AVG(gsc_position),1) AS pos
   FROM seo_metrics
   WHERE tenant_id='{tenant}' AND source='gsc' AND gsc_top_query IS NOT NULL
     AND metric_date >= CURDATE() - INTERVAL 14 DAY AND url LIKE '%preset=%'
   GROUP BY gsc_top_query, url ORDER BY impr DESC LIMIT 15;
   ```
   Compare positions to the prior checkpoint's table.

4. **If the read is ambiguous** (e.g. impressions collapsed but position improved), pull the weekly trend for the key page before concluding anything:
   ```sql
   SELECT YEARWEEK(metric_date) AS wk, SUM(gsc_impressions) AS impr, SUM(gsc_clicks) AS clicks,
     ROUND(SUM(gsc_impressions*gsc_position)/NULLIF(SUM(gsc_impressions),0),1) AS wpos
   FROM seo_metrics WHERE tenant_id='{tenant}' AND source='gsc' AND url LIKE '%{page}%'
   GROUP BY YEARWEEK(metric_date) ORDER BY wk DESC LIMIT 6;
   ```
   **Known pattern ("Google dance"):** impression/ranking whiplash for 1–3 weeks after significant page changes is re-evaluation churn, not a verdict. If a major deploy happened <2 weeks ago, say "too noisy to call" and re-check in a week rather than forcing a conclusion.

5. **Verdict** via the standing decision tree (full version in the checkpoint doc):
   - Target pages **climbed** (→ top 5) → on-page validated; next: measure engagement (PostHog — key is in local `.env` `POSTHOG_PERSONAL_API_KEY`, project 133580; engagement is only readable once clicks exist).
   - Target pages **stuck** (~pos 10 for 3–4 weeks post-change) → on-page is not the lever; the constraint is authority/off-page (backlinks, content marketing, landing pages). F35 (LCP pass) stays parked unless explicitly revived.
   - **Ambiguous/churning** → wait one week, no new on-page specs.

6. **Log it:** append a dated UPDATE section to the checkpoint doc (metrics table row, target-query table, verdict, decision). Add/adjust todo.md items only for genuinely new findings.

## Report format

Lead with the verdict in one sentence. Then: trajectory table (all checkpoints), target-query table with prior positions, pipeline health one-liner, and the recommended next action with its date.
