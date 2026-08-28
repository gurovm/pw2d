<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessPendingProduct;
use App\Models\AiCategoryRejection;
use App\Models\AiMatchingDecision;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 039 T1/T2 — ProcessPendingProduct wired through ProductEvaluation +
 * FinalizeProductEvaluation. Covers what's new/at-risk in the extraction:
 * the ignored-reason mapping still working through the value object, the
 * new wrong_category wiring end to end, and applyFeatureScores() (shared
 * with RescanProductFeatures) being reached correctly from this job. Also
 * covers the review-2026-08-28 HIGH-1 fix (non-JSON Gemini reply) and the
 * two branches (merge, pre-existing rejection) that
 * FinalizeProductEvaluationTest's docblock previously — incorrectly —
 * claimed were "covered end to end" by other suites; they were not (review
 * LOW-6): none of BrandNormalizationTest/AiMatchProductTest actually run
 * this job.
 */
class ProcessPendingProductEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create([
            'budget_max'   => 50,
            'midrange_max' => 150,
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
            ]),
        ]);
    }

    /** @test */
    public function a_gemini_ignored_generic_white_label_payload_maps_through_the_value_object_exactly_as_before(): void
    {
        Feature::factory()->create(['category_id' => $this->category->id]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name'        => 'Unbranded Wireless Charger',
            'slug'        => 'stub-slug-' . uniqid(),
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);

        $this->fakeGeminiResponse(['status' => 'ignored', 'reason' => 'generic_white_label']);

        (new ProcessPendingProduct($product->id, $this->category->id))->handle();

        $product->refresh();
        $this->assertTrue($product->is_ignored);
        $this->assertNull($product->status);
        // Category untouched by an ordinary ignore — only wrong_category detaches.
        $this->assertSame($this->category->id, $product->category_id);
    }

    /** @test */
    public function a_gemini_wrong_category_payload_detaches_the_product_and_records_a_rejection(): void
    {
        Feature::factory()->create(['category_id' => $this->category->id]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name'        => 'Shotgun Mic Wrongly Imported As Lavalier',
            'slug'        => 'stub-slug-' . uniqid(),
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);

        $this->fakeGeminiResponse(['status' => 'ignored', 'reason' => 'wrong_category']);

        (new ProcessPendingProduct($product->id, $this->category->id))->handle();

        $product->refresh();
        $this->assertNull($product->category_id);
        $this->assertNull($product->status);
        $this->assertFalse($product->is_ignored);

        $this->assertDatabaseHas('ai_category_rejections', [
            'product_id'  => $product->id,
            'category_id' => $this->category->id,
        ]);
    }

    /**
     * Proves ProcessPendingProduct reaches the shared
     * FinalizeProductEvaluation::applyFeatureScores() (not a private copy of
     * the loop), via a `null` feature entry (dropped by ProductEvaluation
     * itself, never reaching applyFeatureScores at all). A `0`-score entry
     * from Gemini IS still reachable through this producer (review M3 — the
     * value object accepts it) and is exercised at the shared-method level by
     * FinalizeProductEvaluationTest::apply_feature_scores_skips_a_null_entry_and_a_zero_score_identically_and_writes_the_rest,
     * with the raw-AI-response half of the "skipped identically" proof in
     * RescanProductFeaturesTest.
     */
    /** @test */
    public function a_scored_response_skips_a_null_feature_via_the_shared_apply_method(): void
    {
        $scored   = Feature::factory()->create(['category_id' => $this->category->id, 'name' => 'Scored']);
        $unscored = Feature::factory()->create(['category_id' => $this->category->id, 'name' => 'Unscored']);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name'        => 'Some Wireless Mic',
            'slug'        => 'stub-slug-' . uniqid(),
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);

        $this->fakeGeminiResponse([
            'name'       => 'Rode Wireless GO II',
            'brand'      => 'Rode',
            'ai_summary' => 'A capable wireless mic for the price.',
            'price_tier' => 2,
            'features'   => [
                'Scored'   => ['score' => 82, 'reason' => 'Clear capture.'],
                'Unscored' => null,
            ],
        ]);

        (new ProcessPendingProduct($product->id, $this->category->id))->handle();

        $product->refresh();
        $this->assertNull($product->status, 'product should be fully processed');

        $this->assertDatabaseHas('product_feature_values', [
            'product_id' => $product->id,
            'feature_id' => $scored->id,
            'raw_value'  => 82,
        ]);
        $this->assertSame(
            0,
            ProductFeatureValue::where('product_id', $product->id)->where('feature_id', $unscored->id)->count()
        );
    }

    // -------------------------------------------------------------------
    // Review HIGH-1 — non-JSON Gemini reply must not strand the product
    // -------------------------------------------------------------------

    /**
     * Before the fix: `ProductEvaluation::fromArray($result['parsed'])`
     * received `null` (Gemini's non-JSON/prose reply — `json_decode()`
     * yields `null`) and threw a bare `TypeError`. `TypeError` extends
     * `\Error`, not `\Exception`, so the job's own `catch (\Exception $e)`
     * never saw it: the exception reached the queue worker directly, the
     * `ProcessPendingProduct: failed` log line and the final-attempt
     * `status = 'failed'` write never ran, and the product was stranded at
     * `pending_ai` forever — invisible to Filament's "Retry Failed" filter.
     *
     * `Illuminate\Queue\Jobs\SyncJob::attempts()` is hardcoded to return 1
     * (see RescanProductFeaturesTest's docblock for the full explanation), so
     * telling "attempt 1 of 3" apart from "attempt 3 of 3" requires the real
     * `database` queue connection driven with `queue:work --once`, exactly
     * like that file.
     */
    /** @test */
    public function a_non_json_gemini_reply_throws_on_attempt_one_and_fails_the_product_after_three_attempts(): void
    {
        Feature::factory()->create(['category_id' => $this->category->id]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name'        => 'Some Wireless Mic',
            'slug'        => 'stub-slug-' . uniqid(),
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => 'Sorry, I cannot evaluate this product.']]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        config(['queue.default' => 'database']);
        ProcessPendingProduct::dispatch($product->id, $this->category->id);

        // Attempt 1 of 3 — must be caught by the job's own catch (\Exception
        // $e), logged, and rethrown for the queue's own retry/backoff. The
        // product must NOT be marked failed yet.
        Artisan::call('queue:work', [
            'connection' => 'database', '--once' => true, '--queue' => 'default', '--sleep' => 0,
        ]);

        $product->refresh();
        $this->assertSame('pending_ai', $product->status, 'attempt 1 of 3 must retry, not fail, the product');
        $this->assertDatabaseCount('jobs', 1); // still queued for retry after attempt 1
        $this->assertDatabaseCount('failed_jobs', 0);

        // Drive to attempts 2 and 3 (reset available_at to skip real backoff waits).
        for ($i = 0; $i < 2; $i++) {
            DB::table('jobs')->update(['available_at' => now()->getTimestamp()]);
            Artisan::call('queue:work', [
                'connection' => 'database', '--once' => true, '--queue' => 'default', '--sleep' => 0,
            ]);
        }

        $product->refresh();
        $this->assertSame('failed', $product->status, 'the 3rd of 3 attempts must mark the product failed');

        // Unlike RescanProductFeatures (which always rethrows — Spec 036
        // H-C), ProcessPendingProduct writes status=failed itself on the
        // final attempt and does NOT rethrow — pre-existing/unchanged
        // behaviour (spec §2 T1's "retry/failed semantics ... unchanged"),
        // so the job completes normally and never reaches failed_jobs.
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    // -------------------------------------------------------------------
    // Review LOW-6 — merge and pre-existing-rejection branches, run through
    // the actual job (previously only traced, never exercised end to end).
    // -------------------------------------------------------------------

    /** @test */
    public function a_gemini_scored_response_merges_into_an_existing_product_transferring_offers_and_deleting_the_duplicate(): void
    {
        Feature::factory()->create(['category_id' => $this->category->id]);

        $amazon  = Store::create(['name' => 'Amazon', 'slug' => 'amazon']);
        $bestBuy = Store::create(['name' => 'Best Buy', 'slug' => 'best-buy']);

        $existing = Product::create([
            'category_id' => $this->category->id,
            'name'        => 'Rode Wireless GO II',
            'slug'        => 'rode-wireless-go-ii',
            'status'      => null,
            'is_ignored'  => false,
        ]);
        $existingAmazonOffer = ProductOffer::create([
            'product_id'    => $existing->id,
            'store_id'      => $amazon->id,
            'url'           => 'https://www.amazon.com/dp/EXISTING',
            'scraped_price' => 199.00,
            'raw_title'     => 'Rode Wireless GO II',
        ]);

        $duplicate = Product::create([
            'category_id' => $this->category->id,
            'name'        => 'Rode Wireless GO II Duplicate Listing',
            'slug'        => 'stub-slug-' . uniqid(),
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);
        // Same store as the existing product's offer, and CHEAPER — must win
        // the same-store-cheaper rule (existing's row updated, this deleted).
        $duplicateAmazonOffer = ProductOffer::create([
            'product_id'    => $duplicate->id,
            'store_id'      => $amazon->id,
            'url'           => 'https://www.amazon.com/dp/DUPLICATE',
            'scraped_price' => 179.00,
            'raw_title'     => 'Rode Wireless GO II Duplicate Listing',
        ]);
        // Different store — plain transfer to the matched product.
        $duplicateBestBuyOffer = ProductOffer::create([
            'product_id'    => $duplicate->id,
            'store_id'      => $bestBuy->id,
            'url'           => 'https://www.bestbuy.com/DUPLICATE',
            'scraped_price' => 189.00,
            'raw_title'     => 'Rode Wireless GO II Duplicate Listing',
        ]);

        // matchProduct()'s cache-check (STEP 1) short-circuits on this —
        // deliberately avoids any HTTP call, per the review's suggestion.
        AiMatchingDecision::create([
            'tenant_id'           => $duplicate->tenant_id,
            'scraped_raw_name'    => $duplicate->name,
            'existing_product_id' => $existing->id,
            'is_match'            => true,
        ]);

        $this->fakeGeminiResponse([
            'name'       => 'Rode Wireless GO II',
            'brand'      => 'Rode',
            'ai_summary' => 'A capable wireless mic for the price.',
            'price_tier' => 2,
            'features'   => [],
        ]);

        (new ProcessPendingProduct($duplicate->id, $this->category->id))->handle();

        $this->assertDatabaseMissing('products', ['id' => $duplicate->id]);
        $this->assertDatabaseHas('products', ['id' => $existing->id]);

        $existingAmazonOffer->refresh();
        $this->assertEquals(179.00, (float) $existingAmazonOffer->scraped_price, 'same-store cheaper offer must win');
        $this->assertSame('https://www.amazon.com/dp/DUPLICATE', $existingAmazonOffer->url);

        $this->assertDatabaseMissing('product_offers', ['id' => $duplicateAmazonOffer->id]);

        $duplicateBestBuyOffer->refresh();
        $this->assertSame($existing->id, $duplicateBestBuyOffer->product_id, 'different-store offer must transfer');
    }

    /**
     * The `AiCategoryRejection` check (pre-existing rejection, distinct from
     * the NEW `wrong_category` verdict tested above) — a product previously
     * swept out of this exact category by `AiSweepCategory` and re-queued
     * must be detached again WITHOUT the scored payload's name/brand/
     * features ever being written, even though Gemini returns an ordinary
     * scored verdict this time.
     */
    /** @test */
    public function a_pre_existing_category_rejection_detaches_the_product_before_any_scored_write(): void
    {
        Feature::factory()->create(['category_id' => $this->category->id]);

        $product = Product::create([
            'category_id' => $this->category->id,
            'name'        => 'Previously Swept Shotgun Mic',
            'slug'        => 'stub-slug-' . uniqid(),
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);

        AiCategoryRejection::create([
            'product_id'       => $product->id,
            'category_id'      => $this->category->id,
            'rejection_reason' => 'wrong_category',
        ]);

        // An ordinary SCORED payload — the pre-existing rejection must still win.
        $this->fakeGeminiResponse([
            'name'       => 'Should Never Be Written',
            'brand'      => 'Should Never Be Written',
            'ai_summary' => 'Should never be written.',
            'price_tier' => 2,
            'features'   => [],
        ]);

        (new ProcessPendingProduct($product->id, $this->category->id))->handle();

        $product->refresh();
        $this->assertNull($product->category_id);
        $this->assertNull($product->status);
        $this->assertNotSame('Should Never Be Written', $product->name);
        $this->assertNull($product->brand_id);

        // Only the ONE pre-existing rejection row — the action must not
        // create a duplicate via firstOrCreate.
        $this->assertSame(
            1,
            AiCategoryRejection::where('product_id', $product->id)->where('category_id', $this->category->id)->count()
        );
    }
}
