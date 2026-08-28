<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RescanProductFeatures;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec 036 §1 / H-C (2026-08-21 audit) — RescanProductFeatures::handle() must
 * ALWAYS rethrow on failure so Laravel's own $tries/$backoff gating records the
 * exhausted job in `failed_jobs`. Before the fix, the final attempt swallowed
 * the exception, so the queue worker considered the job done — it was silently
 * deleted from `jobs` with nothing written to `failed_jobs` and `queue:retry
 * all` could never recover it.
 *
 * `QUEUE_CONNECTION=sync` in phpunit.xml is unusable for this scenario:
 * `Illuminate\Queue\Jobs\SyncJob::attempts()` is hardcoded to return 1, so the
 * pre-fix `$this->attempts() < $this->tries` guard (1 < 3) would always be
 * true and always rethrow — the bug this test exists to catch cannot occur on
 * the sync driver. Each test here switches to the `database` connection,
 * dispatches for real, and drains it with `queue:work --once` three times
 * (resetting `available_at` between runs to skip past backoff), which is the
 * only way to reach a real "3rd of 3 attempts" state with $job->attempts()
 * backed by the `jobs` table row instead of a hardcoded stub.
 */
class RescanProductFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'rescan-failure-tenant', 'name' => 'Rescan Failure Tenant']);
        $this->tenant = Tenant::find('rescan-failure-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    /** Binds an AiService double whose rescanFeatures() always throws. */
    private function bindAlwaysFailingAiService(): void
    {
        app()->instance(AiService::class, new class extends AiService {
            public function __construct() {}

            public function rescanFeatures(
                string $productName,
                string $priceNote,
                string $ratingNote,
                array $featureMap,
                ?string $tenantId = null,
            ): array {
                throw new \Exception('AI unavailable (test double)');
            }
        });
    }

    /**
     * Dispatches the job on the real `database` connection and drives it to
     * exhaustion: pop + run up to 3 times, forcing `available_at` back to now
     * before each run so the job's own backoff ([10, 60, 300]) never makes the
     * test wait on real time.
     */
    private function dispatchAndExhaust(int $productId, int $categoryId): void
    {
        config(['queue.default' => 'database']);

        RescanProductFeatures::dispatch($productId, $categoryId);

        for ($i = 0; $i < 3; $i++) {
            DB::table('jobs')->update(['available_at' => now()->getTimestamp()]);

            Artisan::call('queue:work', [
                'connection' => 'database',
                '--once'     => true,
                '--queue'    => 'default',
                '--sleep'    => 0,
            ]);
        }
    }

    /** @test */
    public function a_rescan_that_throws_on_all_three_attempts_is_recorded_in_failed_jobs(): void
    {
        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id, 'name' => 'Speed']);
        $product = Product::factory()->create(['category_id' => $category->id]);

        $this->bindAlwaysFailingAiService();

        $this->dispatchAndExhaust($product->id, $category->id);

        // The job must be gone from the pending queue AND present in failed_jobs —
        // a bare "no longer pending" is exactly what the pre-fix bug also produced.
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);

        $payload = json_decode(DB::table('failed_jobs')->value('payload'), true);
        $this->assertSame(
            RescanProductFeatures::class,
            $payload['displayName'] ?? null,
            'the recorded failure must be for RescanProductFeatures, not some other job'
        );
    }

    /**
     * The actual bug (H-C): an exhausted rescan is a TWO-part failure — zero
     * feature values left behind by the delete-then-rescan sequence, AND no
     * failure record to alert anyone. Either alone is recoverable (an empty
     * score profile is at least visible via a failed_jobs row; a failure
     * record with feature values intact is just a stale-score warning). Both
     * together is the silent, unrecoverable state this fix closes.
     */
    /** @test */
    public function an_exhausted_rescan_leaves_zero_feature_values_and_still_records_the_failure(): void
    {
        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id, 'name' => 'Speed']);
        $product = Product::factory()->create(['category_id' => $category->id]);

        // Simulates the observer's delete-old-values step having already run
        // (Spec 035) before this rescan was queued — the product starts with
        // no feature values, same as a fresh re-home.
        $this->assertSame(0, ProductFeatureValue::where('product_id', $product->id)->count());

        $this->bindAlwaysFailingAiService();

        $this->dispatchAndExhaust($product->id, $category->id);

        $this->assertSame(
            0,
            ProductFeatureValue::where('product_id', $product->id)->count(),
            'a failed rescan must not fabricate feature values'
        );
        // "a product left with zero feature values must always have a corresponding failure record"
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    /** @test */
    public function a_successful_rescan_updates_feature_values_and_records_no_failure(): void
    {
        $category = Category::factory()->create();
        $feature  = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Speed']);
        $product  = Product::factory()->create(['category_id' => $category->id]);

        app()->instance(AiService::class, new class extends AiService {
            public function __construct() {}

            public function rescanFeatures(
                string $productName,
                string $priceNote,
                string $ratingNote,
                array $featureMap,
                ?string $tenantId = null,
            ): array {
                return ['parsed' => ['features' => ['Speed' => 90]]];
            }
        });

        config(['queue.default' => 'database']);
        RescanProductFeatures::dispatch($product->id, $category->id);

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--once'     => true,
            '--queue'    => 'default',
            '--sleep'    => 0,
        ]);

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseHas('product_feature_values', [
            'product_id' => $product->id,
            'feature_id' => $feature->id,
            'raw_value'  => 90,
        ]);
    }

    /**
     * Spec 039 T2 — proves this job reaches the SAME
     * FinalizeProductEvaluation::applyFeatureScores() ProcessPendingProduct
     * uses (closes todo L2), not a private copy of the loop. Unlike
     * ProcessPendingProduct, this job's AI response is never run through
     * ProductEvaluation — a bare zero score can still legitimately arrive
     * here, and must be skipped identically to a missing/null entry.
     */
    /** @test */
    public function a_zero_score_and_a_missing_feature_are_skipped_identically_and_a_real_score_is_written(): void
    {
        $category = Category::factory()->create();
        $scored   = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Scored']);
        $zeroed   = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Zeroed']);
        $missing  = Feature::factory()->create(['category_id' => $category->id, 'name' => 'Missing']);
        $product  = Product::factory()->create(['category_id' => $category->id]);

        app()->instance(AiService::class, new class extends AiService {
            public function __construct() {}

            public function rescanFeatures(
                string $productName,
                string $priceNote,
                string $ratingNote,
                array $featureMap,
                ?string $tenantId = null,
            ): array {
                // 'Missing' deliberately absent — Gemini omitted it entirely.
                return ['parsed' => ['features' => ['Scored' => 82, 'Zeroed' => 0]]];
            }
        });

        (new RescanProductFeatures($product->id, $category->id))->handle();

        $this->assertDatabaseHas('product_feature_values', [
            'product_id' => $product->id,
            'feature_id' => $scored->id,
            'raw_value'  => 82,
        ]);
        $this->assertSame(
            0,
            ProductFeatureValue::where('product_id', $product->id)->where('feature_id', $zeroed->id)->count()
        );
        $this->assertSame(
            0,
            ProductFeatureValue::where('product_id', $product->id)->where('feature_id', $missing->id)->count()
        );
    }
}
