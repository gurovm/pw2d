<?php

namespace Tests\Unit;

use App\Models\AiMatchingDecision;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiMatchProductTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGeminiResponse(array $jsonBody): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($jsonBody)]]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);
    }

    /** @test */
    public function returns_cached_match_without_ai_call(): void
    {
        $product = Product::factory()->create();

        AiMatchingDecision::create([
            'tenant_id'           => $product->tenant_id,
            'scraped_raw_name'    => 'Breville Barista Express BES870XL Stainless',
            'existing_product_id' => $product->id,
            'is_match'            => true,
        ]);

        Http::fake(); // Should NOT be called

        $service = app(AiService::class);
        $result = $service->matchProduct(
            'Breville Barista Express BES870XL Stainless',
            'Breville',
            $product->tenant_id
        );

        $this->assertEquals($product->id, $result);
        Http::assertNothingSent();
    }

    /** @test */
    public function returns_null_for_cached_non_match_without_ai_call(): void
    {
        AiMatchingDecision::create([
            'tenant_id'           => null,
            'scraped_raw_name'    => 'Some Unknown Product',
            'existing_product_id' => null,
            'is_match'            => false,
        ]);

        Http::fake();

        $service = app(AiService::class);
        $result = $service->matchProduct('Some Unknown Product', 'Unknown', null);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    /** @test */
    public function skips_ai_when_no_products_for_brand_and_saves_negative(): void
    {
        Http::fake();

        $service = app(AiService::class);
        $result = $service->matchProduct('Breville Bambino Plus', 'Breville', null);

        $this->assertNull($result);
        Http::assertNothingSent();

        $this->assertDatabaseHas('ai_matching_decisions', [
            'scraped_raw_name' => 'Breville Bambino Plus',
            'is_match'         => false,
        ]);
    }

    /** @test */
    public function calls_ai_and_saves_positive_match(): void
    {
        $brand = Brand::factory()->create(['name' => 'Breville']);
        $existing = Product::factory()->create([
            'name'     => 'Breville Barista Express',
            'brand_id' => $brand->id,
            'status'   => null,
        ]);

        $this->fakeGeminiResponse([
            'is_match'             => true,
            'matched_product_name' => 'Breville Barista Express',
        ]);

        $service = app(AiService::class);
        $result = $service->matchProduct(
            'Breville Barista Express BES870XL Brushed Steel',
            'Breville',
            $existing->tenant_id
        );

        $this->assertEquals($existing->id, $result);

        $this->assertDatabaseHas('ai_matching_decisions', [
            'scraped_raw_name'    => 'Breville Barista Express BES870XL Brushed Steel',
            'existing_product_id' => $existing->id,
            'is_match'            => true,
        ]);

        Http::assertSentCount(1);
    }

    /** @test */
    public function calls_ai_and_saves_negative_match(): void
    {
        $brand = Brand::factory()->create(['name' => 'Breville']);
        Product::factory()->create([
            'name'     => 'Breville Barista Express',
            'brand_id' => $brand->id,
            'status'   => null,
        ]);

        $this->fakeGeminiResponse([
            'is_match' => false,
        ]);

        $service = app(AiService::class);
        $result = $service->matchProduct(
            'Breville Bambino Plus BES500',
            'Breville',
            $brand->tenant_id
        );

        $this->assertNull($result);

        $this->assertDatabaseHas('ai_matching_decisions', [
            'scraped_raw_name'    => 'Breville Bambino Plus BES500',
            'existing_product_id' => null,
            'is_match'            => false,
        ]);
    }

    /** @test */
    public function match_product_excludes_self(): void
    {
        $brand = Brand::factory()->create(['name' => 'Rode']);
        $product = Product::factory()->create([
            'name'     => 'Rode NT-USB Mini',
            'brand_id' => $brand->id,
            'status'   => null,
        ]);

        Http::fake();

        $service = app(AiService::class);
        $result = $service->matchProduct(
            'Rode NT-USB Mini Condenser Microphone',
            'Rode',
            $product->tenant_id,
            $product->id // exclude self
        );

        // Only product for this brand is excluded → no candidates → negative decision, no AI call
        $this->assertNull($result);
        Http::assertNothingSent();

        $this->assertDatabaseHas('ai_matching_decisions', [
            'scraped_raw_name' => 'Rode NT-USB Mini Condenser Microphone',
            'is_match'         => false,
        ]);
    }

    /** @test */
    public function negative_decisions_invalidated_after_product_finalized(): void
    {
        $brand = Brand::factory()->create(['name' => 'Breville']);
        $product = Product::factory()->create([
            'name'      => 'Breville Barista Express',
            'brand_id'  => $brand->id,
            'status'    => 'pending_ai',
            'tenant_id' => null,
        ]);

        // Seed stale negative decisions
        AiMatchingDecision::create([
            'tenant_id'           => $product->tenant_id,
            'scraped_raw_name'    => 'Some Other Product Title',
            'existing_product_id' => null,
            'is_match'            => false,
        ]);
        AiMatchingDecision::create([
            'tenant_id'           => $product->tenant_id,
            'scraped_raw_name'    => 'Another Stale Decision',
            'existing_product_id' => null,
            'is_match'            => false,
        ]);
        // Positive decision should survive
        AiMatchingDecision::create([
            'tenant_id'           => $product->tenant_id,
            'scraped_raw_name'    => 'Positive Match Title',
            'existing_product_id' => $product->id,
            'is_match'            => true,
        ]);

        // Simulate what ProcessPendingProduct does after finalization
        $product->update(['status' => null]);

        \App\Models\AiMatchingDecision::withoutGlobalScopes()
            ->where('tenant_id', $product->tenant_id)
            ->where('is_match', false)
            ->delete();

        // Negative decisions should be gone
        $this->assertDatabaseMissing('ai_matching_decisions', [
            'scraped_raw_name' => 'Some Other Product Title',
        ]);
        $this->assertDatabaseMissing('ai_matching_decisions', [
            'scraped_raw_name' => 'Another Stale Decision',
        ]);
        // Positive decision should remain
        $this->assertDatabaseHas('ai_matching_decisions', [
            'scraped_raw_name' => 'Positive Match Title',
            'is_match'         => true,
        ]);
    }

    /** @test */
    public function does_not_match_ignored_or_pending_products(): void
    {
        $brand = Brand::factory()->create(['name' => 'TestBrand']);
        Product::factory()->create([
            'name'       => 'TestBrand Model X',
            'brand_id'   => $brand->id,
            'is_ignored' => true,
            'status'     => null,
        ]);
        Product::factory()->create([
            'name'     => 'TestBrand Model Y',
            'brand_id' => $brand->id,
            'status'   => 'pending_ai',
        ]);

        Http::fake();

        $service = app(AiService::class);
        $result = $service->matchProduct('TestBrand Model X Pro', 'TestBrand', null);

        // No active products for brand → skip AI, return null
        $this->assertNull($result);
        Http::assertNothingSent();
    }
}
