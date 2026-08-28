<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 039 T3 — pw2d:products:export-pending. Tests run on the central
 * domain; tenancy is initialized manually for seeding, then ended before
 * Artisan::call so the command initializes tenancy itself (established
 * pattern — see GenerateLandingPageCommandTest).
 */
class ExportPendingProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'export-tenant', 'name' => 'Export Tenant']);
        $this->tenant = Tenant::find('export-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function makeCategory(string $slug, array $overrides = []): Category
    {
        return Category::factory()->create(array_merge([
            'slug'         => $slug,
            'name'         => ucfirst(str_replace('-', ' ', $slug)),
            'budget_max'   => 100,
            'midrange_max' => 300,
        ], $overrides));
    }

    private function makeStore(string $name = 'Amazon'): Store
    {
        return Store::create([
            'name'      => $name,
            'slug'      => Str::slug($name),
            'is_active' => true,
        ]);
    }

    private function makePendingProduct(Category $category, Store $store, array $overrides = []): Product
    {
        $product = Product::create(array_merge([
            'category_id' => $category->id,
            'name'        => 'Raw Stub Title',
            'slug'        => 'stub-' . Str::random(8),
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ], $overrides));

        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/' . $product->slug,
            'raw_title'     => 'Raw Amazon Title For ' . $product->slug,
            'scraped_price' => 129.00,
        ]);

        return $product->fresh();
    }

    private function makeProcessedProduct(Category $category, Store $store, Feature $feature, array $overrides = []): Product
    {
        $brand = Brand::factory()->create(['name' => 'DJI']);

        $product = Product::create(array_merge([
            'category_id'           => $category->id,
            'brand_id'              => $brand->id,
            'name'                  => 'DJI Mic 2',
            'slug'                  => 'dji-mic-2-' . Str::random(8),
            'status'                => null,
            'is_ignored'            => false,
            'ai_summary'            => 'A great mic.',
            'price_tier'            => 2,
            'amazon_rating'         => 4.6,
            'amazon_reviews_count'  => 500,
        ], $overrides));

        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/' . $product->slug,
            'raw_title'     => 'DJI Mic 2 Raw Listing Title',
            'scraped_price' => 279.00,
        ]);

        ProductFeatureValue::create([
            'product_id' => $product->id,
            'feature_id' => $feature->id,
            'raw_value'  => 85,
        ]);

        return $product->fresh();
    }

    private function readExportedFile(int $exitCode, string $outPath): array
    {
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($outPath);

        return json_decode(file_get_contents($outPath), true);
    }

    // =========================================================================
    // Export shape for pending + failed
    // =========================================================================

    /** @test */
    public function export_shape_matches_the_spec_for_pending_and_failed_products(): void
    {
        $category = $this->makeCategory('export-shape-cat');
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Audio Quality', 'is_higher_better' => true]);
        $store    = $this->makeStore();

        $pending = $this->makePendingProduct($category, $store, ['status' => 'pending_ai']);
        $failed  = $this->makePendingProduct($category, $store, ['status' => 'failed']);
        $this->makeProcessedProduct($category, $store, $feature); // anchor candidate, not in products[]

        $out = storage_path('app/bouncer/test-export-shape.json');
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:products:export-pending', [
            'tenant'        => 'export-tenant',
            'category-slug' => 'export-shape-cat',
            '--out'         => $out,
        ]);

        $data = $this->readExportedFile($exitCode, $out);

        $this->assertSame('export-tenant', $data['meta']['tenant']);
        $this->assertSame(['pending_ai', 'failed'], $data['meta']['status_filter']);
        $this->assertSame(2, $data['meta']['count']);

        $this->assertSame($category->id, $data['category']['id']);
        $this->assertSame('Audio Quality', $data['category']['features'][0]['name']);
        $this->assertTrue($data['category']['features'][0]['is_higher_better']);

        $this->assertStringContainsString('STAGE 1', $data['rules']);
        $this->assertStringContainsString('wrong_category', $data['rules']);

        $this->assertIsArray($data['brands']);
        $this->assertIsArray($data['anchors']);
        $this->assertCount(1, $data['anchors']);
        $this->assertSame('DJI Mic 2', $data['anchors'][0]['name']);
        $this->assertSame('DJI', $data['anchors'][0]['brand']);
        $this->assertEquals(85.0, $data['anchors'][0]['features']['Audio Quality']);

        $this->assertCount(2, $data['products']);
        $productIds = array_column($data['products'], 'product_id');
        $this->assertContains($pending->id, $productIds);
        $this->assertContains($failed->id, $productIds);

        $entry = collect($data['products'])->firstWhere('product_id', $pending->id);
        $this->assertSame('Raw Amazon Title For ' . $pending->slug, $entry['raw_title']);
        $this->assertEquals(129.0, $entry['price']);
        $this->assertStringContainsString('Mid-range', $entry['price_note']);
        $this->assertSame('Amazon', $entry['store']);
        $this->assertSame('https://example.com/' . $pending->slug, $entry['url']);
        $this->assertSame('pending_ai', $entry['status']);

        unlink($out);
    }

    // =========================================================================
    // --status=processed is blind
    // =========================================================================

    /** @test */
    public function processed_export_is_blind_no_stored_scores_brand_or_ai_summary(): void
    {
        $category = $this->makeCategory('blind-export-cat');
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Battery Life']);
        $store    = $this->makeStore();

        $this->makeProcessedProduct($category, $store, $feature);

        $out = storage_path('app/bouncer/test-blind-export.json');
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:products:export-pending', [
            'tenant'        => 'export-tenant',
            'category-slug' => 'blind-export-cat',
            '--status'      => 'processed',
            '--out'         => $out,
        ]);

        $data = $this->readExportedFile($exitCode, $out);

        $this->assertSame(['processed'], $data['meta']['status_filter']);
        $this->assertCount(1, $data['products']);

        $entry = $data['products'][0];
        $this->assertArrayNotHasKey('status', $entry, 'blind export must never reveal pipeline status');
        $this->assertArrayNotHasKey('brand', $entry, 'blind export must never reveal the stored brand');
        $this->assertArrayNotHasKey('ai_summary', $entry, 'blind export must never reveal the stored ai_summary');
        $this->assertArrayNotHasKey('price_tier', $entry, 'blind export must never reveal the stored price_tier');
        $this->assertArrayNotHasKey('features', $entry, 'blind export must never reveal stored feature scores');

        // Only raw/import-time data survives.
        $this->assertArrayHasKey('raw_title', $entry);
        $this->assertArrayHasKey('price', $entry);
        $this->assertArrayHasKey('price_note', $entry);
        $this->assertArrayHasKey('rating_note', $entry);
        $this->assertArrayHasKey('store', $entry);
        $this->assertArrayHasKey('url', $entry);

        unlink($out);
    }

    // =========================================================================
    // Blind calibration export excludes exported ids from the anchor set
    // (review M3) — otherwise a product under test also appears as its own
    // anchor, contaminating the T5 gate.
    // =========================================================================

    /** @test */
    public function blind_export_never_uses_an_exported_product_as_its_own_anchor(): void
    {
        $category = $this->makeCategory('blind-anchor-cat');
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Range']);
        $store    = $this->makeStore();

        $productIdsByName = [];
        foreach (range(1, 6) as $i) {
            $product = $this->makeProcessedProduct($category, $store, $feature, [
                'name'                 => "Processed Product {$i}",
                'slug'                 => "processed-product-{$i}",
                'amazon_reviews_count' => $i * 100,
            ]);
            $productIdsByName["Processed Product {$i}"] = $product->id;
        }

        $out = storage_path('app/bouncer/test-blind-anchor-exclusion.json');
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:products:export-pending', [
            'tenant'        => 'export-tenant',
            'category-slug' => 'blind-anchor-cat',
            '--status'      => 'processed',
            '--limit'       => 3,
            '--anchors'     => 5,
            '--out'         => $out,
        ]);

        $data = $this->readExportedFile($exitCode, $out);

        // Products are ordered by id ascending, so the first 3 created
        // ("Processed Product 1..3") are the exported ones.
        $this->assertCount(3, $data['products']);
        $exportedIds = array_column($data['products'], 'product_id');
        $this->assertEqualsCanonicalizing(
            [
                $productIdsByName['Processed Product 1'],
                $productIdsByName['Processed Product 2'],
                $productIdsByName['Processed Product 3'],
            ],
            $exportedIds
        );

        // 6 processed products, 3 exported → only 3 remain eligible as
        // anchors, even though --anchors=5 was requested.
        $this->assertCount(3, $data['anchors']);

        $anchorIds = collect($data['anchors'])
            ->pluck('name')
            ->map(fn ($name) => $productIdsByName[$name])
            ->all();

        foreach ($anchorIds as $anchorId) {
            $this->assertNotContains($anchorId, $exportedIds, 'an exported product must never also appear as an anchor');
        }

        // Deterministic: highest review count among the non-exported remainder first.
        $this->assertSame(
            ['Processed Product 6', 'Processed Product 5', 'Processed Product 4'],
            array_column($data['anchors'], 'name')
        );

        unlink($out);
    }

    // =========================================================================
    // Anchors count
    // =========================================================================

    /** @test */
    public function anchors_option_caps_the_number_of_anchor_products_returned(): void
    {
        $category = $this->makeCategory('anchors-cat');
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Range']);
        $store    = $this->makeStore();

        foreach (range(1, 3) as $i) {
            $this->makeProcessedProduct($category, $store, $feature, [
                'name'                 => "Anchor Product {$i}",
                'slug'                 => "anchor-product-{$i}",
                'amazon_reviews_count' => $i * 100,
            ]);
        }

        $out = storage_path('app/bouncer/test-anchors.json');
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:products:export-pending', [
            'tenant'        => 'export-tenant',
            'category-slug' => 'anchors-cat',
            '--anchors'     => 2,
            '--out'         => $out,
        ]);

        $data = $this->readExportedFile($exitCode, $out);

        $this->assertCount(2, $data['anchors']);
        // Deterministic: highest review count first.
        $this->assertSame('Anchor Product 3', $data['anchors'][0]['name']);
        $this->assertSame('Anchor Product 2', $data['anchors'][1]['name']);

        unlink($out);
    }

    // =========================================================================
    // Brands are tenant-scoped
    // =========================================================================

    /** @test */
    public function brands_list_is_tenant_scoped(): void
    {
        Tenant::create(['id' => 'export-tenant-b', 'name' => 'Export Tenant B']);

        $category = $this->makeCategory('brands-cat');
        Feature::factory()->create(['category_id' => $category->id]);
        Brand::factory()->create(['name' => 'Tenant A Brand']);

        tenancy()->end();
        tenancy()->initialize(Tenant::find('export-tenant-b'));
        Brand::factory()->create(['name' => 'Tenant B Brand']);
        tenancy()->end();

        $out = storage_path('app/bouncer/test-brands.json');

        $exitCode = Artisan::call('pw2d:products:export-pending', [
            'tenant'        => 'export-tenant',
            'category-slug' => 'brands-cat',
            '--out'         => $out,
        ]);

        $data = $this->readExportedFile($exitCode, $out);

        $this->assertContains('Tenant A Brand', $data['brands']);
        $this->assertNotContains('Tenant B Brand', $data['brands']);

        unlink($out);
    }

    // =========================================================================
    // Products of another tenant never appear
    // =========================================================================

    /** @test */
    public function products_of_another_tenant_never_appear_in_the_export(): void
    {
        Tenant::create(['id' => 'export-tenant-c', 'name' => 'Export Tenant C']);

        $category = $this->makeCategory('cross-tenant-cat');
        Feature::factory()->create(['category_id' => $category->id]);
        $store = $this->makeStore();
        $ownProduct = $this->makePendingProduct($category, $store);

        tenancy()->end();
        tenancy()->initialize(Tenant::find('export-tenant-c'));
        $otherCategory = $this->makeCategory('cross-tenant-cat'); // same slug, different tenant
        Feature::factory()->create(['category_id' => $otherCategory->id]);
        $otherStore = $this->makeStore();
        $this->makePendingProduct($otherCategory, $otherStore, ['name' => 'Other Tenant Product']);
        tenancy()->end();

        $out = storage_path('app/bouncer/test-cross-tenant.json');

        $exitCode = Artisan::call('pw2d:products:export-pending', [
            'tenant'        => 'export-tenant',
            'category-slug' => 'cross-tenant-cat',
            '--out'         => $out,
        ]);

        $data = $this->readExportedFile($exitCode, $out);

        $this->assertCount(1, $data['products']);
        $this->assertSame($ownProduct->id, $data['products'][0]['product_id']);

        unlink($out);
    }

    // =========================================================================
    // Empty backlog → file with count: 0, exit 0
    // =========================================================================

    /** @test */
    public function empty_backlog_writes_a_file_with_zero_count_and_exits_successfully(): void
    {
        $category = $this->makeCategory('empty-backlog-cat');
        Feature::factory()->create(['category_id' => $category->id]);

        $out = storage_path('app/bouncer/test-empty-backlog.json');
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:products:export-pending', [
            'tenant'        => 'export-tenant',
            'category-slug' => 'empty-backlog-cat',
            '--out'         => $out,
        ]);

        $data = $this->readExportedFile($exitCode, $out);

        $this->assertSame(0, $data['meta']['count']);
        $this->assertSame([], $data['products']);

        unlink($out);
    }

    // =========================================================================
    // Default --out path + directory creation
    // =========================================================================

    /** @test */
    public function default_out_path_is_created_under_storage_app_bouncer(): void
    {
        $category = $this->makeCategory('default-out-cat');
        Feature::factory()->create(['category_id' => $category->id]);

        tenancy()->end();

        Artisan::call('pw2d:products:export-pending', [
            'tenant'        => 'export-tenant',
            'category-slug' => 'default-out-cat',
        ]);

        $matches = glob(storage_path('app/bouncer/export-tenant-default-out-cat-*.json'));
        $this->assertNotEmpty($matches, 'expected a default-path export file under storage/app/bouncer');

        foreach ($matches as $match) {
            unlink($match);
        }
    }

    // =========================================================================
    // No slug → multi-category export
    // =========================================================================

    /** @test */
    public function no_slug_exports_every_leaf_category_with_matching_products(): void
    {
        $categoryA = $this->makeCategory('multi-cat-a');
        Feature::factory()->create(['category_id' => $categoryA->id]);
        $storeA = $this->makeStore('Store A');
        $this->makePendingProduct($categoryA, $storeA);

        $categoryB = $this->makeCategory('multi-cat-b');
        Feature::factory()->create(['category_id' => $categoryB->id]);
        $storeB = $this->makeStore('Store B');
        $this->makePendingProduct($categoryB, $storeB);

        // A leaf category with a feature but no matching pending/failed
        // products must be excluded entirely.
        $categoryC = $this->makeCategory('multi-cat-c-empty');
        Feature::factory()->create(['category_id' => $categoryC->id]);

        $out = storage_path('app/bouncer/test-multi-cat.json');
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:products:export-pending', [
            'tenant' => 'export-tenant',
            '--out'  => $out,
        ]);

        $data = $this->readExportedFile($exitCode, $out);

        $this->assertArrayNotHasKey('category', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertCount(2, $data['categories']);

        $slugs = array_column(array_column($data['categories'], 'category'), 'slug');
        $this->assertContains('multi-cat-a', $slugs);
        $this->assertContains('multi-cat-b', $slugs);
        $this->assertNotContains('multi-cat-c-empty', $slugs);

        foreach ($data['categories'] as $block) {
            $this->assertArrayHasKey('rules', $block);
            $this->assertArrayHasKey('anchors', $block);
            $this->assertArrayHasKey('products', $block);
        }

        $this->assertSame(2, $data['meta']['count']);

        unlink($out);
    }
}
