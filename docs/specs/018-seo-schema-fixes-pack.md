# Spec 018: SEO Schema Fixes Pack

**Status:** Draft (architect handoff)
**Authors:** @architect (2026-06-05)
**Depends on:** Spec 015 (SeoSchema), Spec 017 (status pipeline)
**Branch:** `feat/seo-schema-fixes-018`

---

## 1. Motivation

Live audit of pw2d.com on 2026-06-05 surfaced three P0 schema bugs that are preventing rich snippet eligibility across all 1,226 product pages and all category compare pages:

1. **Product schema emits a relative image URL** — `"image":"/storage/products/images/redragon-redragon-k668-B0CDWP1D58.webp"`. Google requires absolute URLs in `Product.image`. Currently invalid → no product rich results anywhere.
2. **Product schema lacks `Offer`** — no price, no availability, no seller. Without `Offer{price,priceCurrency,availability,url}`, products can't get the price-rich-snippet that's typical for affiliate/comparison sites and roughly doubles CTR on commercial queries.
3. **Compare-page meta description is the buying-guide intro dump** — current output literally starts: `"Choosing the right microphone is about matching it to your space and your voice. Here's how to use our sliders to find your perfect match:Start with V..."`. This talks about using the site, not about the products being compared. Google typically auto-rewrites these, losing snippet control.

This spec is bounded: three precise edits to `app/Support/SeoSchema.php` and tests. No migrations, no new fields, no architectural change.

## 2. Goals (in scope)

- A — Product schema image becomes absolute
- B — Product schema gains a valid `Offer` block when a best-offer exists
- C — Compare-page meta description switches from buying-guide dump to templated SEO description, preserving the preset-override path

## 3. Non-goals (out of scope)

- Adding a `seo_description` column to Categories (would be a follow-up if templates don't suffice)
- BreadcrumbList schema (next spec)
- Title-tag rewrites (next spec)
- Duplicate-product cleanup (a separate spec — F23-style indexation work)
- F7 per-URL top_query (still open, separate effort)

---

## 4. Item A — Absolute Product schema image

### 4.1 Where

`app/Support/SeoSchema.php`, `forSelectedProduct()` method, lines 119-121:

```php
if ($product->image_path) {
    $schema['image'] = Storage::url($product->image_path);
}
```

`Storage::url($path)` returns a relative URL like `/storage/products/images/foo.webp`. Google's Product schema requires absolute URLs.

### 4.2 Fix

Mirror the pattern already used for `ogImage` at line 131-134:

```php
if ($product->image_path) {
    $relative = Storage::url($product->image_path);
    $schema['image'] = str_starts_with($relative, 'http') ? $relative : url($relative);
}
```

`url()` resolves to the current request host. On the central domain (pw2d.com) it produces `https://pw2d.com/storage/...`. On tenant subdomains it would correctly produce `https://tenant.example.com/storage/...`. No tenancy-specific code needed.

### 4.3 Tests

Add to `tests/Feature/Seo/SeoSchemaTest.php` (or whichever test file currently exercises `forSelectedProduct`):

- `forSelectedProduct emits an absolute image URL when image_path is set` — assert `str_starts_with($schema['image'], 'http')`.
- `forSelectedProduct omits image when image_path is empty` — assert `array_key_exists('image', $schema) === false`.

---

## 5. Item B — Product schema `Offer` block

### 5.1 Where

`app/Support/SeoSchema.php`, `forSelectedProduct()` method. Add a new block after the `aggregateRating` conditional at line 129.

### 5.2 Design

```php
$bestOffer = $product->best_offer;
$bestPrice = $product->best_price;

if ($bestOffer && $bestPrice > 0) {
    $availability = match ($bestOffer->stock_status) {
        'in_stock'      => 'https://schema.org/InStock',
        'out_of_stock'  => 'https://schema.org/OutOfStock',
        default         => 'https://schema.org/InStock',  // default to InStock if unknown
    };

    $schema['offers'] = [
        '@type'         => 'Offer',
        'price'         => number_format($bestPrice, 2, '.', ''),
        'priceCurrency' => 'USD',
        'availability'  => $availability,
        'url'           => $product->affiliate_url,
        'seller'        => [
            '@type' => 'Organization',
            'name'  => $bestOffer->store?->name ?? 'Multiple retailers',
        ],
    ];
}
```

### 5.3 Decisions

- **Currency hardcoded USD** — matches the US market target (per `docs/project_context.md` §1).
- **`stock_status` unknown values default to `InStock`** — many scraped offers don't set this. Defaulting to OutOfStock would hide most products from rich results; defaulting to InStock matches the conservative-but-helpful Amazon-affiliate convention.
- **`affiliate_url` is the right URL** — it already includes affiliate params per `Product::affiliate_url` accessor.
- **`seller` falls back to "Multiple retailers"** — almost-never-triggered but defensive.
- **No `Offer.priceValidUntil`** — we don't track this and Google treats it as optional.
- **No `MerchantReturnPolicy` / `OfferShippingDetails`** — these would unlock the highest-quality "Merchant listing" rich result but require return-policy + shipping data we don't have. Out of scope; a future iteration could pull from store settings.

### 5.4 Tests

- `forSelectedProduct emits an Offer when best_price > 0` — assert `$schema['offers']['@type'] === 'Offer'`, price string format `"99.99"`, currency USD.
- `forSelectedProduct omits Offer when best_price is null/zero` — `array_key_exists('offers', $schema) === false`.
- `Offer availability respects stock_status` — three cases: in_stock → InStock, out_of_stock → OutOfStock, unknown → InStock.
- `Offer.seller falls back when store is null` — assert "Multiple retailers".
- `Offer.url uses affiliate_url, not raw url` — assert presence of an affiliate tag param.

---

## 6. Item C — Compare-page meta description

### 6.1 Current behavior

`forLeafCategory()`, lines 202-210:

```php
$descriptionText = '';
if (is_array($category->buying_guide) && !empty($category->buying_guide['how_to_decide'])) {
    $descriptionText = strip_tags($category->buying_guide['how_to_decide']);
}

$description = !empty($descriptionText)
    ? Str::limit($descriptionText, 150)
    : "Compare the absolute best {$category->name} on the market. Use our AI-driven sliders to find the perfect match for your exact needs.";
```

Problem: `buying_guide['how_to_decide']` is the in-page buying-guide intro, written for human readers, not for SEO snippets. The result on prod literally starts "Choosing the right microphone is about matching it to your space and your voice. Here's how to use our sliders..." — a meta description should describe what's on the page, not how to use it.

### 6.2 New rule

Replace the description-resolution logic with a precedence chain:

1. **Preset override path (unchanged)** — when an `activePreset` is selected and has `seo_description`, use it. Lines 219-225 handle this; preserve.
2. **Default category description (new template)** — generate from product count + category name + top features.

New template for the default case:

```php
// Pull up to top 3 feature names for this category (ordered by id for stability).
$topFeatures = $category->features->take(3)->pluck('name')->all();
$featuresClause = match (count($topFeatures)) {
    0       => '',
    1       => " AI-ranks them by {$topFeatures[0]}.",
    2       => " AI-ranks them by {$topFeatures[0]} and {$topFeatures[1]}.",
    default => " AI-ranks them by {$topFeatures[0]}, {$topFeatures[1]}, and {$topFeatures[2]}.",
};

$productCount = $visibleProducts->count();
$description = $productCount > 0
    ? "Compare {$productCount} top {$category->name}.{$featuresClause} Find your perfect match in seconds."
    : "Compare the absolute best {$category->name} on the market. AI-ranks by the features that matter most for your needs.";
```

Result for the example URL `pw2d.com/compare/podcast-studio-mics`:
> "Compare 12 top Podcast & Studio Mics. AI-ranks them by Sound Quality, Build Quality, and Value. Find your perfect match in seconds."

**Length check**: roughly 140-155 chars depending on category name length. Within Google's effective limit of ~155-160 chars for desktop.

### 6.3 What about `buying_guide` for the on-page content?

**Unchanged.** The buying guide still appears in the page body — `ComparisonHeader` / `ProductCompare` render it from `category->buying_guide`. Only the meta-description usage is changed.

**`buildItemListSchema`** at line 247+ may still use `descriptionText` — keep that path so the schema's `description` field uses the buying-guide text (which is fine in schema context — it describes the category). Only the `<meta name="description">` output changes.

### 6.4 Tests

- `forLeafCategory description uses templated form with product count` — seed 5 products, assert description contains "Compare 5 top".
- `forLeafCategory description includes top 3 feature names` — seed category with 5 features, assert first 3 are in the description (commas + "and").
- `forLeafCategory description handles 0/1/2 features` — three cases asserting the match-arm output.
- `forLeafCategory description with 0 visible products falls back to generic form` — assert default fallback text.
- `forLeafCategory with active preset uses preset.seo_description` (regression test for the unchanged path).
- `forLeafCategory description no longer uses buying_guide['how_to_decide']` — explicit regression: seed a buying_guide and assert the description does NOT contain its text.

---

## 7. File-level summary

| File | Action |
|---|---|
| `app/Support/SeoSchema.php` | MODIFY — three precise edits per §4, §5, §6 |
| `tests/Feature/Seo/SeoSchemaTest.php` (or current location) | MODIFY — new tests per each item |
| `docs/tasks/todo.md` | UPDATE — track follow-ups (BreadcrumbList, title patterns, duplicate cleanup, F7) |

## 8. Acceptance

- [ ] `php artisan test --filter='Seo'` — all green
- [ ] On prod after deploy: `curl https://pw2d.com/product/<slug> | grep '"image"'` returns an `https://` URL
- [ ] On prod after deploy: same curl shows an `"offers":{"@type":"Offer",...}` block with `price`, `priceCurrency`, `url`, `seller`
- [ ] On prod after deploy: `curl https://pw2d.com/compare/podcast-studio-mics | grep 'meta name="description"'` shows the templated form, NOT the buying-guide intro
- [ ] Google's Rich Results Test (https://search.google.com/test/rich-results) reports the product page as eligible for "Product snippet" (was failing on image; now valid). Tester or user manually verifies one product URL after deploy.

## 9. Rollout

1. PR titled `feat(seo): schema fixes pack (spec 018) — absolute image URLs, Offer block, compare meta`
2. Optional audit pass (small enough that a single reviewer agent should suffice)
3. Merge
4. `/deploy`
5. Wait 2-3 days for Google to recrawl. Then check **Search Console → Enhancements → Products** to confirm valid items increase and errors decrease. The status command's `pw2d:seo:status` should also show CTR start ticking up (currently 0%) once snippets re-render with price.
