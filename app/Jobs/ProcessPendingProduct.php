<?php

namespace App\Jobs;

use App\Actions\FinalizeProductEvaluation;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\AiService;
use App\Support\ProductEvaluation;
use Illuminate\Support\Facades\Log;

class ProcessPendingProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;
    public array $backoff = [10, 60, 300]; // 10s, 1min, 5min

    public function __construct(
        private readonly int $productId,
        private readonly int $categoryId,
    ) {}

    public function handle(): void
    {
        $product  = Product::with('offers.store')->find($this->productId);
        $category = Category::with('features')->find($this->categoryId);

        if (!$product || !$category || $category->features->isEmpty()) {
            Log::warning('ProcessPendingProduct: product or category not found', [
                'product_id'  => $this->productId,
                'category_id' => $this->categoryId,
            ]);
            return;
        }

        // Log a warning if status is not pending_ai (e.g., already processed by a duplicate job),
        // but continue anyway — the database queue prevents duplicate job execution.
        if ($product->status !== 'pending_ai') {
            Log::warning('ProcessPendingProduct: unexpected status, processing anyway', [
                'product_id' => $this->productId,
                'status'     => $product->status,
            ]);
        }

        try {
            $featureMap = $category->features->mapWithKeys(fn($f) => [
                $f->name => ['unit' => $f->unit, 'is_higher_better' => $f->is_higher_better],
            ])->toArray();

            $budgetMax   = $category->budget_max ?? 50;
            $midrangeMax = $category->midrange_max ?? 150;
            $priceNote = match ($product->price_tier) {
                1       => "Budget (under \${$budgetMax})",
                2       => "Mid-range (\${$budgetMax}–\${$midrangeMax})",
                3       => "Premium (over \${$midrangeMax})",
                default => 'unknown price',
            };
            $ratingNote = $product->amazon_rating
                ? "{$product->amazon_rating}/5 stars ({$product->amazon_reviews_count} reviews)"
                : 'no rating data available';

            $aiService = app(AiService::class);
            $result = $aiService->evaluateProduct(
                $product->name, $product->best_price, $priceNote, $ratingNote, $category->name, $featureMap, $product->tenant_id
            );

            // Spec 039 T1 — single validated schema shared with the (future)
            // operator-session producer. A response missing name/brand now
            // surfaces as InvalidProductEvaluation instead of a bare
            // \Exception; both are caught below with identical retry semantics.
            $eval = ProductEvaluation::fromArray($result['parsed']);

            // Spec 039 T2 — single finalize path shared with the (future)
            // operator-session producer.
            app(FinalizeProductEvaluation::class)->execute($product, $category, $eval, source: 'gemini');
        } catch (\Exception $e) {
            Log::error('ProcessPendingProduct: failed', [
                'product_id' => $this->productId,
                'error'      => $e->getMessage(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $product->update(['status' => 'failed']);
            } else {
                throw $e; // Trigger queue retry with backoff
            }
        }
    }
}
