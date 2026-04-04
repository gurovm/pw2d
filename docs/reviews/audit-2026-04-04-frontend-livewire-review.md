# Review: Frontend & Livewire Chunk Audit
**Date:** 2026-04-04
**Status:** Approved with comments

## Scope
All Livewire components (`ProductCompare`, `ComparisonHeader`, `GlobalSearch`, `Home`, `Navigation`), support classes (`SamplePrompts`, `SeoSchema`, `SimilarProducts`), and all Blade templates under `resources/views/`.

---

## Critical Issues (must fix)

### 1. N+1 Queries in SimilarProducts Component
**File:** `app/View/Components/SimilarProducts.php` (lines 24-46)
**Severity:** Performance -- triggers lazy-loaded queries per product card.

Both `Product::where(...)...get()` calls fetch products without eager loading `brand` or `offers`. However, `similar-products.blade.php` accesses:
- `$similarProduct->brand?->name` (lazy loads `brand` per product)
- `$similarProduct->image_url` (accessor triggers `offers` relation)
- `$similarProduct->affiliate_url` (accessor triggers `best_offer`, which reads `offers`)

This causes up to 3 extra queries per similar product (up to 12 extra queries for 4 products), and the result is cached for 7 days -- so the N+1 runs once then freezes. But the initial cache-fill hit is still significant and the pattern is incorrect.

**Fix:** Add `->with(['brand', 'offers.store'])` to both query chains:
```php
$sameTier = Product::where('category_id', $product->category_id)
    // ...
    ->with(['brand', 'offers.store'])
    ->get();
```

### 2. Fabricated reviewCount in Schema.org Markup
**File:** `app/Support/SeoSchema.php` (lines 237-239)

When `amazon_reviews_count` is 0 (or null), the code falls back to `50`:
```php
'reviewCount' => $product->amazon_reviews_count > 0
    ? $product->amazon_reviews_count
    : 50,
```

Fabricating a `reviewCount` of 50 violates Google's structured data guidelines and could result in a manual action penalty. If the real review count is unavailable, the `aggregateRating` block should be omitted entirely rather than invented.

**Fix:** Only emit the `aggregateRating` when both `amazon_rating` and `amazon_reviews_count` have real values:
```php
if (!empty($product->amazon_rating) && $product->amazon_reviews_count > 0) {
    $item['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => $product->amazon_rating,
        'bestRating'  => 5,
        'worstRating' => 1,
        'reviewCount' => $product->amazon_reviews_count,
    ];
}
```

### 3. Hardcoded Amazon Orange (#FF9900) on CTA Buttons
**Files:**
- `resources/views/livewire/product-compare.blade.php` (lines 457, 589, 798)
- `resources/views/components/similar-products.blade.php` (line 60)
- `resources/css/app.css` (line 117 -- `.product-card:hover .amazon-cta`)

The "Check Current Price" buttons use `bg-[#FF9900]` and `hover:bg-[#E68A00]` as hardcoded hex values. While this is intentionally Amazon's branded color (and the default `--color-primary` falls back to `#FF9900`), the platform is multi-store. If a tenant's primary affiliate store is not Amazon (e.g., Clive Coffee), these buttons should use the tenant's primary color, not Amazon's orange.

**Fix:** Replace `bg-[#FF9900]` with `bg-tenant-primary` (or `style="background: var(--color-primary)"`) and derive the hover state similarly. The `app.css` `.product-card:hover .amazon-cta` rule (line 117) should also use `var(--color-primary)`.

---

## Suggestions (recommended improvements)

### 4. Duplicated Typewriter Animation Code (DRY violation)
**Files:**
- `resources/views/livewire/comparison-header.blade.php` (lines 110-136)
- `resources/views/livewire/global-search.blade.php` (lines 21-44)

The exact same Alpine.js typewriter animation (`_tick()` method with `_pi`, `_ci`, `_del` state) is copy-pasted across two templates. If the timing or behavior needs to change, both must be updated in lockstep.

**Suggestion:** Extract into a shared Alpine component (e.g., `Alpine.data('typewriter', ...)`) in `resources/js/app.js`, then reference it with `x-data="typewriter(@js($samplePrompts))"` in both templates.

### 5. Raw DB Query in Blade Template (ComparisonHeader)
**File:** `resources/views/livewire/comparison-header.blade.php` (line 15)
```php
@php $categoryName = \App\Models\Category::find($categoryId)->name ?? 'Unknown Category'; @endphp
```

This executes a DB query inside a Blade template on every render. The category name is already available -- it is loaded in `ComparisonHeader::mount()` via the `$categoryId` param, and the parent `ProductCompare` component already has the full `$category` model.

**Fix:** Pass `$category->name` as a prop from `ProductCompare` to `ComparisonHeader` (e.g., `:categoryName="$category->name"`), or resolve it once in `mount()` and store as a public property on `ComparisonHeader`.

### 6. Repeated Dispatch Logic in ComparisonHeader (DRY)
**File:** `app/Livewire/ComparisonHeader.php`

The `'weights-updated'` dispatch call appears 6 times with the same payload pattern. The three `updated*` lifecycle hooks (`updatedWeights`, `updatedPriceWeight`, `updatedAmazonRatingWeight`) are identical in behavior.

**Suggestion:** Extract a private `dispatchCurrentWeights()` helper:
```php
private function dispatchCurrentWeights(): void
{
    $this->dispatch('weights-updated',
        weights: $this->weights,
        priceWeight: $this->priceWeight,
        amazonRatingWeight: $this->amazonRatingWeight,
    );
}
```
Then call it from all six locations. The three `updated*` hooks can also be consolidated into a single `updated()` lifecycle method.

### 7. Missing `declare(strict_types=1)` on Most Livewire Components
Only `GlobalSearch.php` uses `declare(strict_types=1)`. The project standard (PHP 8.3, CLAUDE.md: "strict types") calls for it on all files. Missing from:
- `ProductCompare.php`
- `ComparisonHeader.php`
- `Home.php`
- `Navigation.php`
- `SeoSchema.php`
- `SimilarProducts.php`

### 8. Missing `aria-label` on Several Icon-Only Buttons
The review checklist requires `aria-label` on icon-only buttons. The following are missing:
- Desktop close button on the product modal (`product-compare.blade.php` line 527) -- has no `aria-label`
- Sidebar close button in ComparisonHeader (`comparison-header.blade.php` line 81) -- has no `aria-label`
- Compare clear button in H2H staging pill (`product-compare.blade.php` line 498) -- uses `title` but not `aria-label`
- "Back to Catalog" button has text so it is fine

**Fix:** Add `aria-label="Close"` (or more descriptive text) to each icon-only `<button>`.

### 9. Inline `<style>` Blocks Inside Blade Templates
**Files:**
- `product-compare.blade.php` lines 211-220 (scrollbar-hide)
- `product-compare.blade.php` lines 605-618 (custom-scrollbar)

These inline `<style>` blocks are embedded inside Livewire component markup. They get re-rendered on every Livewire update and are not deduplicated if the component appears twice. They should live in `resources/css/app.css`.

### 10. Hardcoded Hover Color on `.step-card` and `.cat-card`
**File:** `resources/css/app.css` (lines 401, 503)
```css
.step-card:hover { border-color: #2563EB; }
.cat-card:hover  { border-color: #2563EB; }
```

`#2563EB` (Tailwind blue-600) is a hardcoded brand color. These should use `var(--color-primary)` for consistency with the dynamic branding system.

### 11. `availableBrands` Uses `persist: true` on a Computed Property
**File:** `app/Livewire/ProductCompare.php` (line 70)
```php
#[Computed(persist: true)]
public function availableBrands()
```

`persist: true` caches the computed value across Livewire requests in the session store. This means if brands change (new product imported), the user sees stale brand lists until their session expires. For a low-cardinality, fast query this is unnecessary overhead in session size. A plain `#[Computed]` (per-request) with the existing DB-level cache would be sufficient.

### 12. `ProductScoringService` Instantiated Directly Instead of via Container
**File:** `app/Livewire/ProductCompare.php` (line 158)
```php
$scoringService = new ProductScoringService();
```

Should use `app(ProductScoringService::class)` for consistency and to support future dependency injection or mocking in tests.

### 13. Static Pages Hardcode "Pw2D" Brand Name
**Files:**
- `resources/views/pages/about.blade.php` (lines 3, 6)
- `resources/views/pages/privacy-policy.blade.php` (line 8)
- `resources/views/pages/terms-of-service.blade.php` (lines 9, 13, 17)

These pages reference "Pw2D" and "Pw2D.com" as literal strings. In a multi-tenant deployment, tenant sites like `coffee2decide.com` would still show "Pw2D" branding in their legal pages. These should use `{{ tenant('brand_name') ?? 'Pw2D' }}` or a similar dynamic reference.

### 14. `welcome.blade.php` is the Default Laravel Scaffold
**File:** `resources/views/welcome.blade.php`

This is the unmodified Laravel default welcome page (35K+ tokens of boilerplate). If the home route resolves to the `Home` Livewire component, this file is dead code and should be removed to avoid confusion.

### 15. Category Image Missing `width`/`height` Attributes
**File:** `resources/views/livewire/home.blade.php` (line 75), `resources/views/livewire/product-compare.blade.php` (line 31, 61)

Category images (`Storage::url($category->image)`) lack `width` and `height` attributes, which causes layout shift (CLS) issues. Product images correctly include these attributes, but category images do not.

### 16. `search-ai-badge` Background Hardcodes Blue
**File:** `resources/css/app.css` (line 266-268)
```css
.search-ai-badge {
    background: #EFF6FF;
    border: 1px solid rgba(37, 99, 235, 0.1);
}
```

The badge background and border use hardcoded blue tones instead of tenant-derived colors. Should use `color-mix(in srgb, var(--color-primary) 8%, white)` or similar for the background.

---

## Praise (what was done well)

### Architecture & Performance
- **Two-phase scoring architecture** in `ProductCompare` is excellent: cache raw arrays, score in memory, then fetch full Eloquent models only for the visible top-N. This avoids the classic trap of eager-loading 200+ products with all relations.
- **`tenant_cache_key()` helper** is consistently used across all components (`Home`, `ProductCompare`, `GlobalSearch`, `SimilarProducts`), preventing cross-tenant cache pollution.
- **`SeoSchema` is a clean, self-contained static class** with well-separated scenario builders. The four-path branching (selected product / parent category / leaf with preset / leaf without preset) is easy to follow and test independently.
- **`SamplePrompts` utility class** cleanly centralizes the 3-priority fallback logic, preventing it from being duplicated across `Home`, `ComparisonHeader`, and `ProductCompare`.

### Livewire Best Practices
- **Computed properties** used correctly throughout -- heavy data (`scoredProducts`, `visibleProducts`, `selectedProduct`, `availableBrands`) never appears as public properties that would bloat the Livewire payload.
- **Session-persisted H2H compare list** (`#[Session]`) is a good UX choice -- users don't lose their comparison selection when navigating.
- **`#[Url]` attributes** on `displayLimit`, `activePresetSlug`, and `focus` enable Googlebot to crawl paginated and preset-specific pages while keeping the Livewire component as the single source of truth.
- **Alpine.js ownership of slider state** (`wire:ignore` + `x-data`) with explicit Livewire event bridge (`alpine-weights-sync`) is the correct pattern for high-frequency UI updates (slider drags) that shouldn't round-trip to the server on every pixel of movement.

### Dynamic Branding
- The CSS variable injection in the layout (`--color-primary`, `--color-secondary`, `--color-text`) with the Tailwind `tenant.*` color tokens is well-implemented. Most interactive elements (FAB, search button, hero accents, hint chips, preset pills, section labels, category arrows) correctly use `var(--color-primary)` or `bg-tenant-primary`.
- `app.css` uses `color-mix(in srgb, var(--color-primary) ...)` for derived states (focus rings, glows, hover darkening) -- this is a modern, maintainable approach.

### UX & Responsiveness
- **Mobile-first product modal** with sticky header, sticky footer CTA, and separate desktop/mobile close buttons is thoughtful. The `h-dvh` (dynamic viewport height) usage on mobile prevents iOS Safari address-bar issues.
- **GlobalSearch** has proper labor-illusion UX (rotating phrases, animated progress bars) during AI search, which is appropriate for operations that take 1-3 seconds.
- **"Focus & Bump"** pattern (auto-pin a product from search, clear the URL param) creates a seamless search-to-comparison flow.

### Security & SEO
- CSRF token is properly set in the layout meta tag.
- Canonical URLs are correctly generated for all page variants (category, preset, product).
- Schema.org markup includes `ItemList` for category pages and `Product` for individual products, with proper `ListItem` positioning.
- Affiliate links consistently use `rel="noopener noreferrer"` and `target="_blank"`.
- The `strip_tags()` call on buying guide content (line 176 of `product-compare.blade.php`) allowlists only safe HTML tags, preventing XSS from admin-entered content.
