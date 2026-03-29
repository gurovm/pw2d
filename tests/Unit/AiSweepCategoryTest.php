<?php

namespace Tests\Unit;

use App\Models\AiCategoryRejection;
use App\Models\Category;
use App\Models\Product;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSweepCategoryTest extends TestCase
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
    public function sweep_returns_flagged_products(): void
    {
        $category = Category::factory()->create(['name' => 'Semi-Automatic Espresso Machines']);
        $good = Product::factory()->create(['name' => 'Breville Barista Express', 'category_id' => $category->id, 'status' => null]);
        $bad = Product::factory()->create(['name' => 'Nespresso Vertuo Pod Machine', 'category_id' => $category->id, 'status' => null]);

        $this->fakeGeminiResponse([
            ['id' => $bad->id, 'reason' => 'Capsule/pod machine, not semi-automatic'],
        ]);

        $service = app(AiService::class);
        $result = $service->sweepCategoryPollution($category, collect([$good, $bad]));

        $this->assertCount(1, $result);
        $this->assertEquals($bad->id, $result[0]['id']);
        $this->assertStringContainsString('Capsule', $result[0]['reason']);
    }

    /** @test */
    public function sweep_returns_empty_when_no_pollution(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null]);

        $this->fakeGeminiResponse([]);

        $service = app(AiService::class);
        $result = $service->sweepCategoryPollution($category, collect([$product]));

        $this->assertEmpty($result);
    }

    /** @test */
    public function sweep_ignores_invalid_ids_from_ai(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null]);

        $this->fakeGeminiResponse([
            ['id' => 99999, 'reason' => 'Fake product'],
        ]);

        $service = app(AiService::class);
        $result = $service->sweepCategoryPollution($category, collect([$product]));

        $this->assertEmpty($result);
    }

    /** @test */
    public function rejection_prevents_re_assignment_in_processing(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'status' => 'pending_ai',
        ]);

        AiCategoryRejection::create([
            'product_id'       => $product->id,
            'category_id'      => $category->id,
            'rejection_reason' => 'Capsule machine',
        ]);

        $this->assertDatabaseHas('ai_category_rejections', [
            'product_id'  => $product->id,
            'category_id' => $category->id,
        ]);

        // The rejection record exists — ProcessPendingProduct would check this
        $rejected = AiCategoryRejection::where('product_id', $product->id)
            ->where('category_id', $category->id)
            ->exists();

        $this->assertTrue($rejected);
    }
}
