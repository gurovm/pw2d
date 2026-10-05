<?php

declare(strict_types=1);

namespace Tests\Feature\VsPages;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Tenant;

/**
 * Shared setup for the Spec 043 tests. Re-fetches the tenant after create()
 * (docs/lessons.md 2026-04-11: stancl string-PK leak).
 */
trait VsFixtures
{
    protected Tenant $tenant;

    protected function initTenant(string $id = 'vs-tenant'): Tenant
    {
        Tenant::create(['id' => $id, 'name' => $id]);
        $tenant = Tenant::find($id);
        tenancy()->initialize($tenant);

        return $this->tenant = $tenant;
    }

    protected function endTenancy(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    /**
     * A live, pick-eligible product with a single offer at $price.
     *
     * @param array<int, float|int> $scores feature_id => raw_value
     */
    protected function makeLiveProduct(Category $category, string $name, int $price, array $scores = [], array $overrides = [], ?Brand $brand = null): Product
    {
        $product = Product::factory()->create(array_merge([
            'category_id' => $category->id,
            'brand_id'    => ($brand ?? Brand::factory()->create())->id,
            'name'        => $name,
            'slug'        => \Illuminate\Support\Str::slug($name),
            'ai_summary'  => 'Summary.',
            'is_ignored'  => false,
            'status'      => null,
        ], $overrides));

        ProductOffer::create([
            'product_id'        => $product->id,
            'store_id'          => null,
            'url'               => 'https://amazon.com/' . $product->slug,
            'raw_title'         => $name,
            'scraped_price'     => $price,
            'image_url'         => 'https://images.example.com/' . $product->slug . '.jpg',
            'stock_status'      => 'in_stock',
            'health_checked_at' => now(),
        ]);

        foreach ($scores as $featureId => $raw) {
            ProductFeatureValue::factory()->create([
                'product_id' => $product->id,
                'feature_id' => $featureId,
                'raw_value'  => $raw,
            ]);
        }

        return $product->fresh();
    }

    protected function makeFeature(Category $category, string $name, int $sort = 0): Feature
    {
        return Feature::factory()->create(['category_id' => $category->id, 'name' => $name, 'sort_order' => $sort]);
    }

    /** @return array<string, mixed> a valid save-command draft for the two products */
    protected function draftFor(Product $a, Product $b, array $overrides = []): array
    {
        return array_merge([
            'product_a_id'   => $a->id,
            'product_b_id'   => $b->id,
            'intro'          => '<p>Two machines at similar money.</p>',
            'sections'       => [
                'a_wins'         => '<p>' . $a->name . ' is quicker.</p>',
                'b_wins'         => '<p>' . $b->name . ' is cheaper.</p>',
                'who_should_buy' => '<p>Pick on budget.</p>',
            ],
            'verdict'        => '<p>Buy the cheaper one unless speed matters.</p>',
            'faqs'           => [['q' => 'Which is quieter?', 'a' => '<p>Neither is loud.</p>']],
            'price_snapshot' => [(string) $a->id => $a->estimated_price, (string) $b->id => $b->estimated_price],
        ], $overrides);
    }
}
