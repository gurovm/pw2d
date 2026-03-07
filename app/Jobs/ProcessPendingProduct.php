<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessPendingProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        private readonly int $productId,
        private readonly int $categoryId,
    ) {}

    public function handle(): void
    {
        $product  = Product::find($this->productId);
        $category = Category::with('features')->find($this->categoryId);

        if (!$product || !$category || $category->features->isEmpty()) {
            Log::warning('ProcessPendingProduct: product or category not found', [
                'product_id'  => $this->productId,
                'category_id' => $this->categoryId,
            ]);
            return;
        }

        if ($product->status !== 'pending_ai') {
            return; // Already processed or manually cleared
        }

        try {
            $featureMap = $category->features->mapWithKeys(fn ($f) => [
                $f->name => ['unit' => $f->unit, 'is_higher_better' => $f->is_higher_better],
            ])->toArray();

            $priceNote = match ($product->price_tier) {
                1       => 'Budget (under $50)',
                2       => 'Mid-range ($50–$150)',
                3       => 'Premium (over $150)',
                default => 'unknown price',
            };
            $ratingNote = $product->amazon_rating
                ? "{$product->amazon_rating}/5 stars ({$product->amazon_reviews_count} reviews)"
                : 'no rating data available';

            $prompt = "You are a ruthless, highly skeptical technology appraiser for a premium comparison website.\n"
                . "Your primary job is to score this product using your WORLD KNOWLEDGE of the brand and model.\n\n"
                . "Product name: \"{$product->name}\"\n"
                . "Price tier: {$priceNote}\n"
                . "Amazon rating: {$ratingNote}\n\n"
                . "Category features to score:\n"
                . json_encode($featureMap, JSON_PRETTY_PRINT) . "\n\n"
                . "CRITICAL SCORING RULES:\n"
                . "1. WORLD KNOWLEDGE OVERRIDES EVERYTHING: Base scores on your internal knowledge of this specific model.\n"
                . "2. ABSOLUTE SCORING (1-100): 50 = average/mediocre. Budget brands CANNOT score 80+ on quality features.\n"
                . "3. STRICT TRADE-OFFS: Create contrast. If a feature is irrelevant or bad, score it 20-40.\n"
                . "4. OBSCURE PRODUCTS: If you don't recognise the model, infer from brand tier + price. Default to 40-50.\n"
                . "5. CRITICAL RULE: If this is an accessory, cable, mount, or replacement part — NOT a main device — return EXACTLY:\n"
                . '   {"status": "ignored", "reason": "brief explanation"}' . "\n\n"
                . "Return ONLY a valid JSON object in this EXACT format (no markdown, no code blocks):\n"
                . '{"name": "Clean Product Name", "brand": "Brand Name", "ai_summary": "Brutal 2-sentence summary.", '
                . '"price_tier": 2, "amazon_rating": null, "amazon_reviews_count": null, '
                . '"features": {"Feature_Name": {"score": 75, "reason": "One sentence."}, "Other_Feature": null}}';

            $apiKey   = config('services.gemini.api_key');
            $model    = config('services.gemini.site_model');
            $response = Http::timeout(30)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                [
                    'contents'         => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 3000],
                ]
            );

            if (!$response->successful()) {
                throw new \Exception('Gemini API error: ' . $response->status());
            }

            $content = trim(preg_replace('/^```json\s*|\s*```$/m', '', trim(
                $response->json('candidates.0.content.parts.0.text', '')
            )));
            $parsed = json_decode($content, true);

            // AI identified this as an accessory — suppress it
            if (($parsed['status'] ?? null) === 'ignored') {
                $product->update(['status' => null, 'is_ignored' => true]);
                Log::info('ProcessPendingProduct: marked as ignored', [
                    'product_id' => $product->id,
                    'reason'     => $parsed['reason'] ?? '',
                ]);
                return;
            }

            if (empty($parsed['name']) || empty($parsed['brand'])) {
                throw new \Exception('Invalid AI response: missing name or brand field');
            }

            $brand = Brand::firstOrCreate(['name' => $parsed['brand']], ['name' => $parsed['brand']]);

            $product->update([
                'name'                 => $parsed['name'],
                'slug'                 => Str::slug($parsed['name'] . '-' . Str::random(5)),
                'brand_id'             => $brand->id,
                'ai_summary'           => $parsed['ai_summary'] ?? null,
                'price_tier'           => $parsed['price_tier']           ?? $product->price_tier,
                'amazon_rating'        => $parsed['amazon_rating']        ?? $product->amazon_rating,
                'amazon_reviews_count' => $parsed['amazon_reviews_count'] ?? $product->amazon_reviews_count,
                'status'               => null, // fully processed
            ]);

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

            Log::info('ProcessPendingProduct: completed', [
                'product_id' => $product->id,
                'name'       => $product->name,
            ]);

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
