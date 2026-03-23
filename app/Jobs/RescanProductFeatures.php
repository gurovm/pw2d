<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

/**
 * Re-scores a product's feature values against the current category feature set
 * and recalculates its price tier using the category's dynamic thresholds.
 *
 * Unlike ProcessPendingProduct, this job intentionally skips:
 *  - Stage 1: Data Quality Gate (product is already approved)
 *  - Stage 2/2.5: Name & brand normalization (no identity changes)
 *  - Image download (image is already stored)
 *
 * Use this when category features are added/changed and existing products
 * need their scores refreshed.
 */
class RescanProductFeatures implements ShouldQueue
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
        $product  = Product::find($this->productId);
        $category = Category::with('features')->find($this->categoryId);

        if (!$product || !$category || $category->features->isEmpty()) {
            Log::warning('RescanProductFeatures: product or category not found', [
                'product_id'  => $this->productId,
                'category_id' => $this->categoryId,
            ]);
            return;
        }

        try {
            // Recalculate price tier from scraped_price + category thresholds (no AI needed)
            $newPriceTier = $category->priceTierFor($product->scraped_price);
            if ($newPriceTier !== null && $newPriceTier !== $product->price_tier) {
                $product->update(['price_tier' => $newPriceTier]);
                $product->refresh();
            }

            $featureMap = $category->features->mapWithKeys(fn ($f) => [
                $f->name => ['unit' => $f->unit, 'is_higher_better' => $f->is_higher_better],
            ])->toArray();

            $budgetMax   = $category->budget_max ?? 50;
            $midrangeMax = $category->midrange_max ?? 150;
            $priceNote = match ($product->price_tier) {
                1       => "Budget (under \${$budgetMax})",
                2       => "Mid-range (\${$budgetMax}–\${$midrangeMax})",
                3       => "Premium (over \${$midrangeMax})",
                default => 'unknown price tier',
            };
            $ratingNote = $product->amazon_rating
                ? "{$product->amazon_rating}/5 stars ({$product->amazon_reviews_count} reviews)"
                : 'no rating data available';

            $prompt = "You are a product scoring expert for a consumer comparison website.\n"
                . "Score the following product on the listed features using WORLD KNOWLEDGE of this brand and model.\n\n"
                . "Product: \"{$product->name}\"\n"
                . "Price tier: {$priceNote}\n"
                . "Amazon rating: {$ratingNote}\n\n"
                . "Features to score:\n"
                . json_encode($featureMap, JSON_PRETTY_PRINT) . "\n\n"
                . "SCORING RULES:\n"
                . "1. WORLD KNOWLEDGE OVERRIDES EVERYTHING: Base scores on your internal knowledge of this specific model.\n"
                . "2. ABSOLUTE SCORING (1-100): 50 = average/mediocre. Budget brands CANNOT score 80+ on quality features.\n"
                . "3. STRICT TRADE-OFFS: Create contrast. If a feature is irrelevant or bad, score it 20-40.\n"
                . "4. OBSCURE PRODUCTS: If you don't recognise the model, infer from brand tier + price. Default to 40-50.\n\n"
                . "Return ONLY a valid JSON object (no markdown, no code blocks):\n"
                . '{"features": {"Feature_Name": {"score": 75, "reason": "One sentence."}, "Other_Feature": null}}';

            $gemini = app(GeminiService::class);
            $result = $gemini->generate($prompt, ['maxOutputTokens' => 1500]);
            $parsed = $result['parsed'];

            if (empty($parsed['features']) || !is_array($parsed['features'])) {
                throw new \Exception('Invalid AI response: missing features object');
            }

            foreach ($category->features as $feature) {
                $value = $parsed['features'][$feature->name] ?? null;
                if ($value === null) continue;

                $score  = is_array($value) ? (float) ($value['score'] ?? 0) : (float) $value;
                $reason = is_array($value) ? ($value['reason'] ?? null)      : null;

                if ($score > 0) {
                    $product->featureValues()->updateOrCreate(
                        ['feature_id' => $feature->id],
                        ['raw_value' => $score, 'explanation' => $reason]
                    );
                }
            }

            Log::info('RescanProductFeatures: completed', [
                'product_id' => $product->id,
                'name'       => $product->name,
            ]);

        } catch (\Exception $e) {
            Log::error('RescanProductFeatures: failed', [
                'product_id' => $this->productId,
                'error'      => $e->getMessage(),
            ]);

            if ($this->attempts() < $this->tries) {
                throw $e; // trigger queue retry with backoff
            }
        }
    }
}
