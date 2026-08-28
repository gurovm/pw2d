<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessPendingProduct;
use App\Jobs\RescanProductFeatures;
use App\Models\AiMatchingDecision;
use App\Models\AiUsage;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 038 B1 (audit A1) — regression coverage for the queued-AI-call tenant
 * attribution bug: every AI call made from a job (ProcessPendingProduct,
 * RescanProductFeatures, matchProduct()) runs on a worker where stancl/tenancy
 * is deliberately never initialized (see AiUsage model docblock). Before this
 * fix, `AiUsageService::record()`'s only source of a tenant was `tenant('id')`,
 * which is null whenever tenancy isn't initialized — so every job-originated
 * `ai_usage` row recorded `tenant_id = NULL`, unattributable and unrecoverable.
 *
 * These tests deliberately never call tenancy()->initialize() — that is the
 * whole point: the fix is threading `$product->tenant_id` explicitly through
 * the job -> AiService -> GeminiService -> AiUsageService chain, not relying
 * on an initialized tenant context that queued jobs structurally never have.
 */
class AiUsageJobAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'acme', 'name' => 'Acme Tenant']);

        config([
            'services.gemini.api_key'     => 'test-api-key',
            'services.gemini.admin_model' => 'gemini-2.5-pro',
            'services.gemini.site_model'  => 'gemini-2.5-flash',
            'services.gemini.pricing'     => [
                'gemini-2.5-pro'   => ['input' => 1.25, 'output' => 10.00],
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ],
        ]);
    }

    private function fakeGeminiResponse(array $jsonBody): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => json_encode($jsonBody)]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount'     => 500,
                    'candidatesTokenCount' => 200,
                ],
            ]),
        ]);
    }

    /** @test */
    public function process_pending_product_attributes_ai_usage_to_the_products_tenant_without_initialized_tenancy(): void
    {
        $this->assertFalse(tenancy()->initialized, 'precondition: tenancy must not be initialized for this test to be meaningful');

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id, 'name' => 'Comfort']);

        $product = Product::create([
            'tenant_id'   => 'acme',
            'category_id' => $category->id,
            'name'        => 'Some Wireless Headset',
            'slug'        => 'stub-slug-' . uniqid(),
            'price_tier'  => 2,
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);

        $this->fakeGeminiResponse([
            'name'       => 'Sony WH-1000XM5',
            'brand'      => 'Sony',
            'ai_summary' => 'Solid, if unremarkable.',
            'price_tier' => 2,
            'features'   => ['Comfort' => ['score' => 80, 'reason' => 'Plush earcups.']],
        ]);

        ProcessPendingProduct::dispatch($product->id, $category->id);

        $this->assertFalse(tenancy()->initialized, 'the job must not have flipped tenancy into an initialized state');

        $row = AiUsage::sole();
        $this->assertSame('acme', $row->tenant_id);
        $this->assertSame('evaluate_product', $row->purpose);
    }

    /** @test */
    public function rescan_product_features_attributes_ai_usage_to_the_products_tenant_without_initialized_tenancy(): void
    {
        $this->assertFalse(tenancy()->initialized, 'precondition: tenancy must not be initialized for this test to be meaningful');

        $category = Category::factory()->create();
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Comfort']);

        $product = Product::create([
            'tenant_id'   => 'acme',
            'category_id' => $category->id,
            'name'        => 'Sony WH-1000XM5',
            'slug'        => 'sony-wh-1000xm5-' . uniqid(),
            'price_tier'  => 2,
            'status'      => null,
            'is_ignored'  => false,
        ]);

        $this->fakeGeminiResponse([
            'features' => ['Comfort' => ['score' => 85, 'reason' => 'Plush earcups.']],
        ]);

        RescanProductFeatures::dispatch($product->id, $category->id);

        $this->assertFalse(tenancy()->initialized, 'the job must not have flipped tenancy into an initialized state');

        $row = AiUsage::sole();
        $this->assertSame('acme', $row->tenant_id);
        $this->assertSame('rescan_features', $row->purpose);

        $this->assertDatabaseHas('product_feature_values', [
            'product_id' => $product->id,
            'feature_id' => $feature->id,
            'raw_value'  => 85,
        ]);
    }

    /** @test */
    public function match_product_called_with_an_explicit_tenant_id_and_no_tenancy_attributes_usage_to_that_tenant(): void
    {
        $this->assertFalse(tenancy()->initialized, 'precondition: tenancy must not be initialized for this test to be meaningful');

        $brand = Brand::create(['tenant_id' => 'acme', 'name' => 'Sony']);
        Product::create([
            'tenant_id'  => 'acme',
            'brand_id'   => $brand->id,
            'name'       => 'Sony WH-1000XM5',
            'slug'       => 'sony-wh-1000xm5-' . uniqid(),
            'price_tier' => 2,
            'status'     => null,
            'is_ignored' => false,
        ]);

        $this->fakeGeminiResponse(['is_match' => false]);

        $result = app(AiService::class)->matchProduct(
            'Sony WH-1000XM5 Wireless Noise Canceling Headphones',
            'Sony',
            'acme',
        );

        $this->assertNull($result);
        $this->assertFalse(tenancy()->initialized, 'matchProduct() must not initialize tenancy as a side effect');

        $row = AiUsage::sole();
        $this->assertSame('acme', $row->tenant_id);
        $this->assertSame('match_product', $row->purpose);

        $this->assertSame(1, AiMatchingDecision::where('tenant_id', 'acme')->count());
    }
}
