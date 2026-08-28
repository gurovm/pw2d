<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 039 §2 T5 — pw2d:ai:eval-model {tenant} {--from-file=} {--json=}. This
 * build ships only the `--from-file` read-only diff path (Spec 037 T2's
 * live-model runner is not built yet — see EvalModelCommand's class
 * docblock). Same tenancy pattern as ApplyProductEvaluationsCommandTest: seed
 * under an initialized tenant, `tenancy()->end()` before Artisan::call so the
 * command initializes tenancy itself.
 */
class EvalModelCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Category $category;
    protected Feature $feature;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'eval-tenant', 'name' => 'Eval Tenant']);
        $this->tenant = Tenant::find('eval-tenant');
        tenancy()->initialize($this->tenant);

        $this->category = Category::factory()->create(['slug' => 'eval-cat']);
        $this->feature  = Feature::factory()->create(['category_id' => $this->category->id, 'name' => 'Build Quality']);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function processedProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $this->category->id,
            'name'        => 'Stored Product',
            'slug'        => 'eval-' . Str::random(8),
            'status'      => null,
            'is_ignored'  => false,
        ], $overrides));
    }

    private function writeEvaluationsFile(array $evaluations): string
    {
        $path = storage_path('app/bouncer/eval-test-' . Str::random(8) . '.json');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode(['evaluations' => $evaluations]));

        return $path;
    }

    // =========================================================================
    // --from-file required
    // =========================================================================

    /** @test */
    public function missing_from_file_option_fails_cleanly(): void
    {
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:ai:eval-model', ['tenant' => 'eval-tenant']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--from-file is required', Artisan::output());
    }

    // =========================================================================
    // Unknown tenant
    // =========================================================================

    /** @test */
    public function an_unknown_tenant_fails_cleanly(): void
    {
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:ai:eval-model', [
            'tenant'      => 'no-such-tenant',
            '--from-file' => '/does/not/matter.json',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    // =========================================================================
    // File not found / malformed
    // =========================================================================

    /** @test */
    public function a_missing_file_fails_cleanly(): void
    {
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:ai:eval-model', [
            'tenant'      => 'eval-tenant',
            '--from-file' => storage_path('app/bouncer/does-not-exist-' . Str::random(8) . '.json'),
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    /** @test */
    public function a_file_missing_the_evaluations_key_fails_cleanly(): void
    {
        $path = storage_path('app/bouncer/eval-malformed-' . Str::random(8) . '.json');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode(['not_evaluations' => []]));

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:ai:eval-model', ['tenant' => 'eval-tenant', '--from-file' => $path]);

        $this->assertSame(Command::FAILURE, $exitCode);

        unlink($path);
    }

    // =========================================================================
    // Exit codes: PASS -> 0, FAIL -> 1, INSUFFICIENT -> 1
    // =========================================================================

    /** @test */
    public function exits_zero_when_the_gate_passes(): void
    {
        $brand = Brand::factory()->create(['name' => 'Anker']);
        $product = $this->processedProduct(['brand_id' => $brand->id, 'is_ignored' => false]);
        ProductFeatureValue::create(['product_id' => $product->id, 'feature_id' => $this->feature->id, 'raw_value' => 80]);
        tenancy()->end();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Anker Product',
            'brand'      => 'Anker',
            'ai_summary' => 'Clean, no issues.',
            'features'   => ['Build Quality' => ['score' => 81, 'reason' => null]],
        ]]);

        $exitCode = Artisan::call('pw2d:ai:eval-model', ['tenant' => 'eval-tenant', '--from-file' => $file]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Gate: PASS', Artisan::output());

        unlink($file);
    }

    /** @test */
    public function exits_one_when_the_gate_fails(): void
    {
        $brand = Brand::factory()->create(['name' => 'Anker']);
        $product = $this->processedProduct(['brand_id' => $brand->id, 'is_ignored' => true]);
        tenancy()->end();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Anker Product',
            'brand'      => 'CompletelyDifferentBrand',
            'ai_summary' => 'Clean, no issues.',
            'features'   => [],
        ]]);

        $exitCode = Artisan::call('pw2d:ai:eval-model', ['tenant' => 'eval-tenant', '--from-file' => $file]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Gate: FAIL', Artisan::output());

        unlink($file);
    }

    /** @test */
    public function exits_one_when_the_gate_is_insufficient(): void
    {
        tenancy()->end();

        $file = $this->writeEvaluationsFile([]);

        $exitCode = Artisan::call('pw2d:ai:eval-model', ['tenant' => 'eval-tenant', '--from-file' => $file]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Gate: INSUFFICIENT', Artisan::output());

        unlink($file);
    }

    // =========================================================================
    // Read-only — writes nothing
    // =========================================================================

    /** @test */
    public function the_command_writes_nothing_to_products_or_feature_values(): void
    {
        $brand = Brand::factory()->create(['name' => 'Anker']);
        $product = $this->processedProduct(['brand_id' => $brand->id, 'is_ignored' => false]);
        ProductFeatureValue::create(['product_id' => $product->id, 'feature_id' => $this->feature->id, 'raw_value' => 80]);
        tenancy()->end();

        $before = Product::withoutGlobalScopes()->find($product->id)->getAttributes();
        $featureValuesBefore = ProductFeatureValue::where('product_id', $product->id)->get()->toArray();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Anker Product',
            'brand'      => 'RavPower',
            'ai_summary' => 'Clean, no issues.',
            'features'   => ['Build Quality' => ['score' => 20, 'reason' => null]],
        ]]);

        Artisan::call('pw2d:ai:eval-model', ['tenant' => 'eval-tenant', '--from-file' => $file]);

        $after = Product::withoutGlobalScopes()->find($product->id)->getAttributes();
        $featureValuesAfter = ProductFeatureValue::where('product_id', $product->id)->get()->toArray();

        $this->assertSame($before, $after);
        $this->assertSame($featureValuesBefore, $featureValuesAfter);

        unlink($file);
    }

    // =========================================================================
    // --json round trip
    // =========================================================================

    /** @test */
    public function json_option_writes_the_full_result_and_round_trips(): void
    {
        $brand = Brand::factory()->create(['name' => 'Anker']);
        $product = $this->processedProduct(['brand_id' => $brand->id, 'is_ignored' => false]);
        ProductFeatureValue::create(['product_id' => $product->id, 'feature_id' => $this->feature->id, 'raw_value' => 80]);
        tenancy()->end();

        $file = $this->writeEvaluationsFile([[
            'product_id' => $product->id,
            'name'       => 'Anker Product',
            'brand'      => 'Anker',
            'ai_summary' => 'Clean, no issues.',
            'features'   => ['Build Quality' => ['score' => 84, 'reason' => null]],
        ]]);

        $jsonPath = storage_path('app/bouncer/eval-result-' . Str::random(8) . '.json');

        $exitCode = Artisan::call('pw2d:ai:eval-model', [
            'tenant'      => 'eval-tenant',
            '--from-file' => $file,
            '--json'      => $jsonPath,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($jsonPath);

        $decoded = json_decode((string) file_get_contents($jsonPath), true);

        $this->assertSame('pass', $decoded['gate']['verdict']);
        $this->assertSame(1, $decoded['rows']['compared']);
        $this->assertSame(0, $decoded['rows']['unmatched']);
        $this->assertSame($product->id, $decoded['diffs'][0]['product_id']);
        $this->assertEqualsWithDelta(1.0, (float) $decoded['brand']['normalized_exact_rate'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $decoded['features']['mad'], 0.0001);

        unlink($file);
        unlink($jsonPath);
    }
}
