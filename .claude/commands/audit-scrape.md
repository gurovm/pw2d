# Post-Scrape Quality Audit

Audit a category after a Chrome-extension scrape: Bouncer kill rate, score quality, variant dupes. Argument: `{tenant} {category-slug}` (e.g. `coffee2decide gooseneck-kettles`).

Prod: `ssh root@209.97.153.234`, app at `/var/www/pw2d`, DB via `mysql -u root pw2d`.

**Read-only by default.** The only mutating actions allowed from this skill are `products:recalculate-tiers` and a merge run — and a merge requires showing the owner the `--dry-run` output first and getting explicit confirmation.

## Procedure

1. **Queue state first** — is processing even done?
   ```sql
   SELECT COUNT(*) AS total,
     SUM(status='pending_ai') AS pending, SUM(status='failed') AS failed,
     SUM(is_ignored=1) AS ignored, SUM(status IS NULL AND is_ignored=0) AS live
   FROM products WHERE tenant_id='{tenant}'
     AND category_id=(SELECT id FROM categories WHERE slug='{slug}' AND tenant_id='{tenant}');
   ```
   If `pending` is high and not shrinking, check the queue workers (`supervisorctl status`, 2 workers expected) before auditing anything.

2. **Bouncer kill-rate + over-kill judgment.** Sample the ignored:
   ```sql
   SELECT name, ai_summary FROM products WHERE tenant_id='{tenant}' AND is_ignored=1
     AND category_id=(...) LIMIT 15;
   ```
   Judgment call — the Bouncer kills "accessories", but in gear categories (kettles, scales, grinders) **the accessory IS the product**. Kills of filters/consumables/bundles/2-packs = correct. Kills of legitimate primary gear = over-kill → the fix is tuning the `evaluateProduct` category context in `AiService`, **not** manually un-ignoring rows (that leaves the next scrape broken). Expected healthy kill rate: roughly 15–35%; >50% on a gear category is a red flag.

3. **Score sanity** on live products:
   ```sql
   SELECT p.name, p.ai_summary IS NULL OR p.ai_summary='' AS no_summary,
     COUNT(pfv.id) AS features_scored, ROUND(AVG(pfv.raw_value),0) AS avg_score,
     MIN(pfv.raw_value) AS min_s, MAX(pfv.raw_value) AS max_s, p.amazon_reviews_count
   FROM products p LEFT JOIN product_feature_values pfv ON pfv.product_id=p.id
   WHERE p.tenant_id='{tenant}' AND p.category_id=(...) AND p.status IS NULL AND p.is_ignored=0
   GROUP BY p.id ORDER BY avg_score DESC LIMIT 25;
   ```
   Smells: `min_s = max_s` or everything ~50 (model didn't recognize the product — default-scoring); `features_scored` < the category's feature count; empty summaries; `amazon_reviews_count = 0` in bulk (known extension extraction issue — see todo.md "Chrome extension: fix Amazon reviews_count"). Healthy scoring shows contrast (spec rule: budget brands can't score 80+ on quality features).

4. **Variant dupes** (F29 lesson):
   ```
   php artisan pw2d:merge-duplicates {tenant?} --category={slug} --dry-run
   ```
   (Check the command signature for the tenant arg form before running.) Judgment: color/finish variants and pack/SKU dupes (SM58 vs SM58-LC vs 2-Pack) = merge. **Different product lines are NOT variants** (HyperX Cloud II vs Alpha vs III) — the naive estimate always overstates merge potential (~10–15% real, not 40%+). Never run a destructive merge without owner sign-off on the dry-run list.

5. **Price tiers:** if the category has `budget_max`/`midrange_max` set, run `products:recalculate-tiers`. If not set, flag it to the owner.

6. **Spot-check the live page:** `curl` the compare page — schema count (3 JSON-LD blocks), ItemList populated, no `priceCurrency` anywhere (load-bearing policy — memory: `seo-schema-policy`).

## Verdict format

One of three, led with in the first sentence:
- **GREEN** — kill rate sane, scores show contrast, dupes ≤ a handful → green-light the next scrape batch (and, if this was the last planned category: content generation → GSC connection).
- **TUNE** — Bouncer over/under-killing or default-scoring → propose the specific `AiService::evaluateProduct` context fix; re-test on a small batch before mass scraping.
- **MERGE** — real dupes found → present the dry-run list for owner confirmation, then merge and re-run tiers.

Then: the counts table, 3–5 named examples per finding, and what step the owner does next.
