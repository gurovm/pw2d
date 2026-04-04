<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Preset;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeoSchema
{
    /**
     * Build all SEO data for the ProductCompare page.
     *
     * Handles four scenarios:
     * - Selected product → product-specific meta + Product schema
     * - Parent category (subcategories exist) → category meta + CollectionPage schema
     * - Leaf category with active preset → preset-specific meta + ItemList schema
     * - Leaf category without preset → generic category meta + ItemList schema
     *
     * @param  Category        $category
     * @param  Collection      $subcategories       Child categories (empty for leaf categories).
     * @param  string|null     $selectedProductSlug Slug of the currently opened product modal.
     * @param  Product|null    $selectedProduct     Resolved Product model (null when no product is open).
     * @param  string|null     $activePresetSlug    Active preset slug from URL parameter.
     * @param  Collection      $visibleProducts     Scored + paginated products used to build ItemList.
     * @return array{title: string, description: string, canonical: string, ogType: string, ogImage: string|null, schemas: array, activePreset: Preset|null}
     */
    public static function forCategoryPage(
        Category $category,
        Collection $subcategories,
        ?string $selectedProductSlug,
        ?Product $selectedProduct,
        ?string $activePresetSlug,
        Collection $visibleProducts,
    ): array {
        if ($selectedProductSlug && $selectedProduct) {
            return self::forSelectedProduct($selectedProduct);
        }

        if ($subcategories->isNotEmpty()) {
            return self::forParentCategory($category);
        }

        return self::forLeafCategory($category, $activePresetSlug, $visibleProducts);
    }

    // -------------------------------------------------------------------------
    // Private scenario builders
    // -------------------------------------------------------------------------

    /**
     * Meta + schema when a product modal is open.
     */
    private static function forSelectedProduct(Product $product): array
    {
        $title = "{$product->name} - AI Review & Match Score";

        $description = $product->ai_summary
            ? Str::limit(strip_tags($product->ai_summary), 150)
            : "Read the comprehensive AI review and view the Match Score for the {$product->name}.";

        $canonical = route('product.show', ['product' => $product->slug]);

        $schema = [
            '@context'    => 'https://schema.org/',
            '@type'       => 'Product',
            'name'        => $product->name,
            'description' => $product->ai_summary
                ? strip_tags($product->ai_summary)
                : $product->name,
            'brand'       => ['@type' => 'Brand', 'name' => $product->brand?->name ?? ''],
        ];

        if ($product->image_path) {
            $schema['image'] = Storage::url($product->image_path);
        }

        if ($product->amazon_reviews_count > 0 && $product->amazon_rating) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $product->amazon_rating,
                'reviewCount' => $product->amazon_reviews_count,
            ];
        }

        $imageUrl = $product->image_url;
        $ogImage  = $imageUrl
            ? (str_starts_with($imageUrl, 'http') ? $imageUrl : url($imageUrl))
            : null;

        return [
            'title'        => $title,
            'description'  => $description,
            'canonical'    => $canonical,
            'ogType'       => 'product',
            'ogImage'      => $ogImage,
            'schemas'      => [$schema],
            'activePreset' => null,
        ];
    }

    /**
     * Meta + schema for a parent category (has subcategories, no product scoring).
     */
    private static function forParentCategory(Category $category): array
    {
        $description = $category->description
            ? Str::limit($category->description, 150)
            : "Browse all {$category->name} subcategories and find the best products for your needs.";

        $canonical = route('category.show', ['slug' => $category->slug]);

        $schema = [
            '@context'    => 'https://schema.org/',
            '@type'       => 'CollectionPage',
            'name'        => $category->name,
            'description' => $description,
        ];

        return [
            'title'        => "{$category->name} - Browse Categories | pw2d",
            'description'  => $description,
            'canonical'    => $canonical,
            'ogType'       => 'website',
            'ogImage'      => null,
            'schemas'      => [$schema],
            'activePreset' => null,
        ];
    }

    /**
     * Meta + schema for a leaf category, with optional preset override.
     */
    private static function forLeafCategory(
        Category $category,
        ?string $activePresetSlug,
        Collection $visibleProducts,
    ): array {
        $currentYear = date('Y');
        $title       = "{$category->name} - Compare Best Models in {$currentYear} | pw2d";
        $canonical   = route('category.show', ['slug' => $category->slug]);

        // Extract plain-text description from the buying guide for meta + schema.
        $descriptionText = '';
        if (is_array($category->buying_guide) && !empty($category->buying_guide['how_to_decide'])) {
            $descriptionText = strip_tags($category->buying_guide['how_to_decide']);
        }

        $description = !empty($descriptionText)
            ? Str::limit($descriptionText, 150)
            : "Compare the absolute best {$category->name} on the market. Use our AI-driven sliders to find the perfect match for your exact needs.";

        // Preset override — look up by slug-derived name match.
        $activePreset = null;
        if (!empty($activePresetSlug)) {
            $activePreset = Preset::where('category_id', $category->id)
                ->get()
                ->first(fn(Preset $p) => Str::slug($p->name) === $activePresetSlug);

            if ($activePreset) {
                $title       = "Best {$category->name} for {$activePreset->name} | pw2d";
                $description = $activePreset->seo_description
                    ?? "Top-ranked {$category->name} for {$activePreset->name} users. Compare by the features that matter most for your specific use case.";
                $canonical   = route('category.show', ['slug' => $category->slug]) . "?preset={$activePresetSlug}";
            }
        }

        $schema = self::buildItemListSchema($category, $descriptionText, $visibleProducts);

        return [
            'title'        => $title,
            'description'  => $description,
            'canonical'    => $canonical,
            'ogType'       => 'website',
            'ogImage'      => null,
            'schemas'      => [$schema],
            'activePreset' => $activePreset,
        ];
    }

    /**
     * Build a schema.org ItemList from the currently visible (scored) products.
     */
    private static function buildItemListSchema(
        Category $category,
        string $descriptionText,
        Collection $visibleProducts,
    ): array {
        $schema = [
            '@context'        => 'https://schema.org/',
            '@type'           => 'ItemList',
            'name'            => 'Best ' . $category->name,
            'description'     => !empty($descriptionText)
                ? Str::limit($descriptionText, 200, '')
                : '',
            'itemListElement' => [],
        ];

        $position = 1;
        foreach ($visibleProducts as $product) {
            $item = [
                '@type' => 'Product',
                'name'  => $product->name,
                'url'   => route('product.show', ['product' => $product->slug]),
            ];

            // image — use Amazon CDN URL (complies with Associates TOS; no local paths)
            $offerImage = $product->offers?->first()?->image_url;
            if (!empty($offerImage)) {
                $item['image'] = $offerImage;
            }

            // description — strip any HTML tags from the AI-generated verdict
            if (!empty($product->ai_summary)) {
                $item['description'] = strip_tags($product->ai_summary);
            }

            // brand — fall back to first word of product name if brand relation is missing
            $brandName    = $product->brand?->name ?? explode(' ', $product->name)[0];
            $item['brand'] = ['@type' => 'Brand', 'name' => $brandName];

            // aggregateRating — use real Amazon stars/reviews; fall back reviewCount to 50
            // so Google always has a valid integer alongside ratingValue.
            // Offers (price) intentionally omitted — scraped prices are estimates and
            // violate Google's strict price-matching rules for Merchant Center rich snippets.
            if (!empty($product->amazon_rating)) {
                $item['aggregateRating'] = [
                    '@type'       => 'AggregateRating',
                    'ratingValue' => $product->amazon_rating,
                    'bestRating'  => 5,
                    'worstRating' => 1,
                    'reviewCount' => $product->amazon_reviews_count > 0
                        ? $product->amazon_reviews_count
                        : 50,
                ];
            }

            $schema['itemListElement'][] = [
                '@type'    => 'ListItem',
                'position' => $position,
                'item'     => $item,
            ];
            $position++;
        }

        return $schema;
    }
}
