<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 041 Part 3 — pw2d:products:apply-models. Tenancy pattern as in
 * ApplyProductEvaluationsCommandTest: seed under an initialized tenant, end it
 * before Artisan::call so the command initializes tenancy itself.
 */
class ApplyProductModelsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;
    private Brand $brand;
    /** @var list<Product> */
    private array $jets = [];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'models-tenant', 'name' => 'Models Tenant']);
        tenancy()->initialize(Tenant::find('models-tenant'));

        $this->category = Category::factory()->create(['slug' => 'models-cat', 'name' => 'Models Cat']);
        $feature = Feature::factory()->create(['category_id' => $this->category->id, 'is_higher_better' => true, 'unit' => null]);
        $this->brand = Brand::factory()->create(['name' => 'Breville']);

        // Two Oracle Jet colourways ranked 1 and 2, then five other brands.
        foreach (['Breville Oracle Jet Black Truffle', 'Breville Oracle Jet Sea Salt'] as $i => $name) {
            $this->jets[] = $this->makeProduct($name, $this->brand, $feature, 99 - $i);
        }
        foreach (range(1, 5) as $i) {
            $this->makeProduct("Other{$i} Machine{$i}", Brand::factory()->create(['name' => "Other{$i}"]), $feature, 80 - $i);
        }

        // A published page whose stored picks are what the picker returns today
        // (names differ enough that the similarity fallback keeps both jets).
        $picks = collect((new \App\Actions\SelectLandingPagePicks())->execute($this->category))
            ->map(fn (array $p) => $p + ['headline' => 'H', 'body' => 'B'])->all();
        LandingPage::factory()->create([
            'category_id' => $this->category->id, 'slug' => 'best-models-cat', 'status' => 'published', 'picks' => $picks,
        ]);

        tenancy()->end();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function makeProduct(string $name, Brand $brand, Feature $feature, float $score): Product
    {
        $slug = 'models-' . Str::random(8);
        $product = Product::factory()->create([
            'category_id' => $this->category->id, 'brand_id' => $brand->id, 'name' => $name, 'slug' => $slug,
            'ai_summary' => 'Summary', 'is_ignored' => false, 'status' => null, 'amazon_rating' => null, 'price_tier' => 2,
        ]);
        ProductOffer::create([
            'product_id' => $product->id, 'url' => "https://example.com/{$slug}", 'raw_title' => $name,
            'image_url' => "https://images.example.com/{$slug}.jpg", 'scraped_price' => 100, 'health_checked_at' => now(),
        ]);
        ProductFeatureValue::factory()->create(['product_id' => $product->id, 'feature_id' => $feature->id, 'raw_value' => $score]);

        return $product;
    }

    /** @param array<int|string, mixed> $models */
    private function file(array $models): string
    {
        $path = storage_path('app/bouncer/test-models-' . Str::random(8) . '.json');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($models));

        return $path;
    }

    /** @test */
    public function dry_run_writes_nothing_and_reports_groups_and_the_page_diff(): void
    {
        [$a, $b] = $this->jets;

        $code = Artisan::call('pw2d:products:apply-models', [
            'tenant' => 'models-tenant',
            'file'   => $this->file([$a->id => 'Oracle Jet', $b->id => 'Oracle Jet']),
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('Same-model groups: 1', $output);
        $this->assertStringContainsString('Breville / "Oracle Jet" (2)', $output);
        $this->assertStringContainsString('picks WOULD CHANGE', $output);
        $this->assertStringContainsString("- #{$b->id}", $output);

        // Nothing persisted, including the rolled-back transaction the diff ran in.
        $this->assertNull(Product::withoutGlobalScopes()->find($a->id)->model);
        $this->assertNull(Product::withoutGlobalScopes()->find($b->id)->model);
    }

    /** @test */
    public function apply_writes_the_models_after_a_dry_run_has_rolled_back(): void
    {
        [$a, $b] = $this->jets;
        $file = $this->file([$a->id => ' Oracle Jet ', $b->id => 'Oracle Jet']);

        Artisan::call('pw2d:products:apply-models', ['tenant' => 'models-tenant', 'file' => $file, '--dry-run' => true]);
        $code = Artisan::call('pw2d:products:apply-models', ['tenant' => 'models-tenant', 'file' => $file]);

        $this->assertSame(0, $code);
        $this->assertSame('Oracle Jet', Product::withoutGlobalScopes()->find($a->id)->model);
        $this->assertSame('Oracle Jet', Product::withoutGlobalScopes()->find($b->id)->model);
    }

    /** @test */
    public function a_product_from_another_tenant_is_rejected_and_nothing_is_written(): void
    {
        Tenant::create(['id' => 'other-tenant', 'name' => 'Other']);
        tenancy()->initialize(Tenant::find('other-tenant'));
        $foreignCategory = Category::factory()->create(['slug' => 'foreign-cat']);
        $foreign = Product::factory()->create(['category_id' => $foreignCategory->id, 'is_ignored' => false, 'status' => null]);
        tenancy()->end();

        $code = Artisan::call('pw2d:products:apply-models', [
            'tenant' => 'models-tenant',
            'file'   => $this->file([$this->jets[0]->id => 'Oracle Jet', $foreign->id => 'Intruder']),
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Product {$foreign->id}: not found in tenant models-tenant", Artisan::output());
        $this->assertNull(Product::withoutGlobalScopes()->find($this->jets[0]->id)->model, 'one bad id aborts the whole file');
        $this->assertNull(Product::withoutGlobalScopes()->find($foreign->id)->model);
    }

    /** @test */
    public function an_ignored_product_and_a_blank_model_are_rejected(): void
    {
        tenancy()->initialize(Tenant::find('models-tenant'));
        $ignored = Product::factory()->create(['category_id' => $this->category->id, 'is_ignored' => true, 'status' => null]);
        tenancy()->end();

        $code = Artisan::call('pw2d:products:apply-models', [
            'tenant' => 'models-tenant',
            'file'   => $this->file([$ignored->id => 'X', $this->jets[0]->id => '  ']),
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Product {$ignored->id}: not live", $output);
        $this->assertStringContainsString("Product {$this->jets[0]->id}: model must be a non-empty string", $output);
    }

    /** @test */
    public function an_unknown_tenant_and_a_bad_file_fail_cleanly(): void
    {
        $this->assertSame(1, Artisan::call('pw2d:products:apply-models', ['tenant' => 'nope', 'file' => $this->file(['1' => 'x'])]));
        $this->assertSame(1, Artisan::call('pw2d:products:apply-models', ['tenant' => 'models-tenant', 'file' => '/no/such/file.json']));
        $this->assertSame(1, Artisan::call('pw2d:products:apply-models', ['tenant' => 'models-tenant', 'file' => $this->file(['a', 'b'])]));
    }
}
