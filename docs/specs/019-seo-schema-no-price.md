# Spec 019: SEO Schema — Drop Price From Offer (Amazon Associates Compliance)

**Status:** Shipped (hotfix on top of Spec 018)
**Authors:** @architect (2026-06-05)
**Depends on:** Spec 018 (which introduced the problem)
**Branch:** `fix/seo-schema-drop-price`

---

## 1. Why

Spec 018 added `Offer.price` (e.g. `"price": "39.99"`) to the Product schema, sourced from `Product::best_price` (scraped data, updated by the chrome extension via `pw2d:sync-offer-prices`).

Two problems with this in combination:

1. **Amazon Associates Operating Agreement** requires that displayed prices come from PA-API and refresh within 24 hours. We use scraping, not PA-API. Emitting an exact scraped price as machine-readable schema is a ToS violation that scanners can detect at scale.
2. **`pw2d:sync-offer-prices` is not scheduled** — on 2026-06-05, the newest price refresh in prod was 2026-04-05 (60+ days stale). So the schema was advertising prices that were two months old as current.

Strategic context (see memory `amazon-associates-strategy`): the owner is **deliberately deferring** Amazon Associates registration until organic traffic exists. Associates approval starts a 180-day window to land 3 qualifying sales; connecting before there's traffic guarantees failure. So we cannot use PA-API "for now" — and "for now" might be months.

Conclusion: remove the price disclosure from schema until PA-API is integrated.

## 2. Change

In `app/Support/SeoSchema.php::forSelectedProduct`, the Offer block now emits:

```json
{
  "@type": "Offer",
  "availability": "https://schema.org/InStock",
  "url": "https://amazon.com/dp/...",
  "seller": { "@type": "Organization", "name": "Amazon" }
}
```

…with no `price` or `priceCurrency`. The `availability`, `url`, and `seller` fields stay because they signal "this product is for sale here" to Google without making a price claim.

A 5-line code comment in `SeoSchema.php` references this spec and the strategic memory.

## 3. Trade-offs accepted

- **No price-rich-snippet in Google SERPs.** This was the headline win of Spec 018; it's gone. We trade snippet CTR for ToS safety and authorial control. The `aggregateRating` field (Amazon-sourced) still qualifies the page for the Product rich result; we just won't show price.
- **The on-page price display is unaffected.** Live pages already use `Product::estimated_price` (rounded to nearest $5/$10) — see `Product.php`. That obfuscation existed precisely for this reason and is allowed.

## 4. When to reverse this

When all three conditions are true:
1. Amazon Associates account approved
2. PA-API integration shipped (prices refresh ≤24h from PA-API, not scraping)
3. Tests in this spec are updated to assert the new policy

Until then, the `Offer` block in `forSelectedProduct` must NOT contain `price` or `priceCurrency`. Tests at `tests/Feature/Seo/SeoSchemaTest.php` assert their absence — those assertions are load-bearing.

## 5. Tests

- `test_for_selected_product_emits_offer_without_price_keys` — asserts Offer present, `price`/`priceCurrency` absent, `availability`/`url`/`seller` present
- `test_for_selected_product_omits_offer_when_product_has_no_offers` — pre-existing, still valid
- `test_for_selected_product_omits_offer_when_all_scraped_prices_are_null` — renamed for clarity; tests `Product::best_offer` null-handling
- Stock-status, seller fallback, affiliate URL tests — unchanged (they never asserted on price)

## 6. Verification on prod

After deploy:

```bash
curl -s https://pw2d.com/product/<slug> | python3 -c "
import sys, re, json
m = re.search(r'<script type=\"application/ld\\+json\">(\\{.*?\\})</script>', sys.stdin.read())
offers = json.loads(m.group(1)).get('offers', {})
print('price in offer:', 'price' in offers, '(must be False)')
print('availability:', offers.get('availability', '(missing)'))
"
```
