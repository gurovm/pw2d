# Spec 020: SEO Titles + BreadcrumbList

**Status:** Draft (architect handoff)
**Authors:** @architect (2026-06-05)
**Depends on:** Spec 018 (SeoSchema), Spec 019 (no-price policy)
**Closes:** F27, F28
**Branch:** `feat/seo-titles-breadcrumb-020`

---

## 1. Motivation

Spec 018/019 cleaned up the product/category schema. The fundamental SEO problem on pw2d.com is now **0 clicks across 276 impressions** in 28 days — pages show up in SERPs but nothing wins the click. Two cheap fixes target this:

1. **F28 — product titles don't include the category.** Current pattern `"Redragon K668 - AI Review & Match Score"` doesn't tell Google (or users) what category the product is in. Searchers looking for "best mechanical gaming keyboard" can't connect the result to their intent.
2. **F27 — no BreadcrumbList schema anywhere.** Without it, SERP listings don't show the breadcrumb trail (`pw2d.com › Mechanical Gaming Keyboards › Redragon K668`). Breadcrumbs visually distinguish results, add topical context, and Google's documentation confirms they affect ranking signals.

Both items modify `app/Support/SeoSchema.php` and tests only. No migrations, no new dependencies.

## 2. Goals (in scope)

- F28: include category name in product page titles, with graceful fallback when category is null
- F27: emit a `BreadcrumbList` schema entry on product pages AND on leaf category pages, with parent-category chain support

## 3. Non-goals

- Singular/plural handling for category names (use as-is — most categories are already in noun-phrase form)
- BreadcrumbList on parent category pages (`forParentCategory`) — out of scope; can be a follow-up
- Title rewrites for compare pages — already include category and year (Spec 018)
- Breadcrumb visual rendering on-page — schema only (the schema enables Google's breadcrumb display in SERPs)

---

## 4. Item A (F28) — Product title pattern

### 4.1 Current behavior

`SeoSchema.php::forSelectedProduct` line 101:

```php
$title = "{$product->name} - AI Review & Match Score";
```

Example output: `Redragon K668 - AI Review & Match Score` (39 chars)

### 4.2 New behavior

When the product has a category, inject the category name:

```php
$category = $product->category;
$title = $category
    ? "{$product->name} {$category->name} — AI Review & Match Score"
    : "{$product->name} — AI Review & Match Score";
```

Examples:
- With category: `Redragon K668 Mechanical Gaming Keyboards — AI Review & Match Score` (67 chars)
- Fallback (no category): `Redragon K668 — AI Review & Match Score` (39 chars)

**Length consideration:** 67 chars exceeds Google's ~60-char desktop SERP truncation. Google will truncate to roughly `Redragon K668 Mechanical Gaming Keyboards — AI Re…`. The full title is still indexed and used for ranking; only the visual SERP display is truncated. The product name + category is the load-bearing part for query matching, which is preserved.

Use the **em-dash (`—`)** consistently (matches what Spec 018 introduced for compare pages). Replace the existing hyphen.

### 4.3 Why no tenant_suffix?

The current product title omits `tenant_seo('title_suffix')` (the " | Pw2D" pattern used on category pages). Keep it that way for length budget — adding `" | Pw2D"` would push the title to 73 chars, truncating more of the category name.

### 4.4 og:title

`forSelectedProduct` already passes `title` through to the layout-data return at line 137. og:title is set via `data-default` on the Blade template. No additional changes needed — the new title flows through.

### 4.5 Tests

- `forSelectedProduct title includes category name when category is set` — seed product with category named "Mechanical Gaming Keyboards", assert title contains `"Mechanical Gaming Keyboards"`.
- `forSelectedProduct title falls back to no-category form when category is null` — seed product with `category_id = null`, assert title equals `"{Name} — AI Review & Match Score"`.
- `forSelectedProduct title uses em-dash separator` — seed any product, assert title contains `" — "` (em-dash with spaces), NOT `" - "` (hyphen).

---

## 5. Item B (F27) — BreadcrumbList schema

### 5.1 Where to add

Two methods in `SeoSchema.php`:
- `forSelectedProduct` — chain ending at the product
- `forLeafCategory` — chain ending at the category

Both methods return `'schemas' => [$schema]` — an array. We'll append the BreadcrumbList as a second schema entry: `'schemas' => [$primarySchema, $breadcrumbSchema]`.

### 5.2 Chain rules

**Product page:**
- Home → (parent category if exists) → leaf category → product

**Leaf category page:**
- Home → (parent category if exists) → category

If the leaf category has no parent (it's a top-level category), the chain is shorter.

### 5.3 Implementation

Add a new private helper to `SeoSchema`:

```php
/**
 * Build a BreadcrumbList schema from an ordered list of [name, url] pairs.
 *
 * @param array<int, array{name: string, url: string}> $items
 */
private static function buildBreadcrumbList(array $items): array
{
    return [
        '@context' => 'https://schema.org/',
        '@type'    => 'BreadcrumbList',
        'itemListElement' => array_map(
            fn (array $item, int $i) => [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $item['name'],
                'item'     => $item['url'],
            ],
            $items,
            array_keys($items),
        ),
    ];
}
```

**In `forSelectedProduct`** (after the existing `$schema = [...]` block, before the return):

```php
$breadcrumbItems = [
    ['name' => 'Home', 'url' => url('/')],
];

$category = $product->category;
if ($category) {
    if ($category->parent) {
        $breadcrumbItems[] = [
            'name' => $category->parent->name,
            'url'  => route('category.show', ['slug' => $category->parent->slug]),
        ];
    }
    $breadcrumbItems[] = [
        'name' => $category->name,
        'url'  => route('category.show', ['slug' => $category->slug]),
    ];
}

$breadcrumbItems[] = [
    'name' => $product->name,
    'url'  => route('product.show', ['product' => $product->slug]),
];

$breadcrumbSchema = self::buildBreadcrumbList($breadcrumbItems);
```

Then update the return: `'schemas' => [$schema, $breadcrumbSchema]`.

**In `forLeafCategory`** (similar, but ending at the category):

```php
$breadcrumbItems = [
    ['name' => 'Home', 'url' => url('/')],
];

if ($category->parent) {
    $breadcrumbItems[] = [
        'name' => $category->parent->name,
        'url'  => route('category.show', ['slug' => $category->parent->slug]),
    ];
}

$breadcrumbItems[] = [
    'name' => $category->name,
    'url'  => route('category.show', ['slug' => $category->slug]),
];

$breadcrumbSchema = self::buildBreadcrumbList($breadcrumbItems);
```

Then update return: `'schemas' => [$schema, $breadcrumbSchema]`.

### 5.4 Use `url('/')`, not `route('home')`

Per F6 in todo.md, `route('home')` depends on an active HTTP request context. `url('/')` is safer and produces the same result (`https://pw2d.com/` on prod). Use `url('/')` consistently for the home item.

### 5.5 Eager loading

`$product->category` triggers a query. `$category->parent` triggers another. Both are one-off per request — no loop — so no N+1. But it's wasteful if the caller hasn't eager-loaded.

Don't add `with(['category.parent'])` here — leave it to callers. The product compare page already loads the category. If a small perf hit shows up, address in a follow-up.

### 5.6 Tests

- `forSelectedProduct emits a BreadcrumbList schema as the second schemas entry`.
- `BreadcrumbList chain for product with top-level category` — assert items: `[Home, Category, Product]`.
- `BreadcrumbList chain for product with parent-child category` — assert items: `[Home, ParentCategory, Category, Product]`.
- `BreadcrumbList chain for product with null category` — assert items: `[Home, Product]`.
- `forLeafCategory emits a BreadcrumbList for the category`.
- `BreadcrumbList for top-level category` — assert items: `[Home, Category]`.
- `BreadcrumbList for child category` — assert items: `[Home, ParentCategory, Category]`.
- `BreadcrumbList position values are 1-indexed and sequential`.
- `BreadcrumbList item URLs are absolute` — assert all `item` values start with `http`.

---

## 6. File-level summary

| File | Action |
|---|---|
| `app/Support/SeoSchema.php` | MODIFY — title pattern in `forSelectedProduct` + new `buildBreadcrumbList` helper + breadcrumb emission in both methods |
| `tests/Feature/Seo/SeoSchemaTest.php` | MODIFY — new tests per §4.5 and §5.6 |
| `docs/tasks/todo.md` | UPDATE — mark F27 and F28 as `[x]` |

## 7. Acceptance

- [ ] `php artisan test --filter='Seo'` — all green (target: 101 → ~110+ passing)
- [ ] After deploy: `curl https://pw2d.com/product/<slug>` — title includes category, schemas array has 2 entries (Product + BreadcrumbList)
- [ ] After deploy: `curl https://pw2d.com/compare/podcast-studio-mics` — schemas array has 2 entries (ItemList + BreadcrumbList)
- [ ] Google Rich Results Test reports both Product and Breadcrumb as valid for at least one product URL

## 8. Rollout

1. PR `feat(seo): titles + breadcrumb (spec 020) — F27 + F28`
2. Optional audit pass (small enough that a single reviewer agent should suffice)
3. Merge
4. `/deploy`
5. Wait 1 week for Google to recrawl. Then re-baseline GSC. If CTR moves above 0, the title+breadcrumb hypothesis is validated.
