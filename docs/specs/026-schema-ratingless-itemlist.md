# Spec 026 — Fix GSC "Product snippets" error for rating-less products in compare-page ItemList

**Status:** APPROVED (owner, 2026-07-05)
**Priority:** P1 — first GSC structured-data error on coffee2decide (detected 2026-07-05), count will grow as crawl proceeds; we are inside coffee2decide's pre-index free-fix window.
**Numbering note:** the "Spec-026 candidate" mentioned in `docs/summaries/2026-07-04-two-site-session-handoff.md` (product-page content depth) is NOT this spec — if built, it takes 027+.

## 1. Problem

GSC → coffee2decide.com → Shopping → Product snippets reports:

> **Either "offers", "review", or "aggregateRating" should be specified**
> Affected: `https://coffee2decide.com/compare/super-automatic-espresso-machines`, item "JURA X10 Dark Inox Espresso Machine" (ListItem position 12).

Root cause: `SeoSchema::buildItemListSchema()` (`app/Support/SeoSchema.php:407-469`) emits **every** visible product as a nested `@type: Product` inside the ItemList. Google requires each Product entity to have at least one of `offers` / `review` / `aggregateRating`. We only ever add `aggregateRating`, and only when `amazon_rating` + `amazon_reviews_count > 0`. Products without Amazon rating data (specialty-store ingested products like the JURA X10, plus the ~88 Amazon products with `reviews_count=0` from the extension extraction bug) emit a Product with none of the three → GSC error.

Product **detail** pages (`forSelectedProduct()`) are NOT affected — they emit a price-less `offers` block + editorial `review` and satisfy the rule. Only the ItemList path lacks a fallback.

## 2. Fix

In `buildItemListSchema()`, split per-product on the same condition already used for `aggregateRating`:

- **Product HAS rating data** (`!empty($product->amazon_rating) && (int) $product->amazon_reviews_count > 0`): emit exactly as today — full nested Product with name/url/image/description/brand + aggregateRating. **Zero change for these items.**
- **Product LACKS rating data**: emit Google's "summary page" ListItem style instead — no nested Product entity at all:

```json
{ "@type": "ListItem", "position": N, "url": "https://.../product/{slug}" }
```

Google then evaluates the linked product page (which carries valid Product markup) rather than an inline entity, and the requirement no longer applies to the list item.

### Explicitly rejected alternatives (do not implement)

- **Availability-only `offers` on list items** — no-price-in-schema policy (spec 019, memory `seo-schema-policy`) makes it an empty Offer; invites "price missing" complaints at scale.
- **Editorial `review` on list items** — Google requires `reviewRating` inside `review` for Product snippets; spec 022 §5.2 forbids publishing a 1–5 score. Would trade this error for a "reviewRating missing" error.

## 3. Invariants (must not regress)

1. **ItemList element count and positions unchanged** — schema reads `displayLimit` products (ItemList = 12) regardless of `renderLimit` (the B24-1 decoupling). Positions stay 1..N sequential across the mixed full/summary items.
2. **No `price` / `priceCurrency` anywhere** in emitted schema (spec 019).
3. Rating-qualified items emit byte-identical structure to today (name, url, image, description, brand, aggregateRating with bestRating 5 / worstRating 1).
4. Other schemas on the page (BreadcrumbList, FAQPage) untouched; 3 JSON-LD blocks still emit on preset pages with FAQs.
5. `forSelectedProduct()` untouched.

## 4. Files

- `app/Support/SeoSchema.php` — `buildItemListSchema()` only (~15 lines).
- `tests/` — existing SeoSchema/ProductCompare schema tests updated + new cases (see §5).

## 5. Test plan

Pest, `RefreshDatabase`, central-domain context, factories with explicit product `slug` (per project test directives).

1. **Rating-less product** → its `itemListElement` entry is `{@type: ListItem, position: N, url: ...}` with NO `item` key (no nested Product, no name/image/brand/description).
2. **Rated product** → unchanged full nested Product with aggregateRating (assert exact keys).
3. **Mixed list** (rated at positions 1-2, rating-less at 3) → positions remain 1,2,3 sequential; count matches product count.
4. **Zero-reviews Amazon product** (`amazon_rating=4.5, amazon_reviews_count=0`) → treated as rating-less (summary style).
5. **Regression sweep** — full `php artisan test` green (397+ passing baseline at `6eee1a7`).

## 6. Post-deploy verification (owner/next session)

- Rich Results Test on `https://coffee2decide.com/compare/super-automatic-espresso-machines` → 0 Product errors.
- GSC → Product snippets → "VALIDATE FIX" on the coffee2decide error.
- Spot-check a pw2d compare page (e.g. `/compare/podcast-studio-mics`) — rated items still show as Product snippets candidates.
