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
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $product  = Product::find($this->productId);
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

            $prompt = "You are a ruthless, highly skeptical technology appraiser AND data architect for a premium comparison website.\n"
                . "Your primary job is to score this product using your WORLD KNOWLEDGE of the brand and model.\n"
                . "You are also the last line of defense against dirty, polluted data entering our database.\n\n"
                . "Product name: \"{$product->name}\"\n"
                . "Scraped price: \${$product->scraped_price}\n"
                . "Price tier: {$priceNote}\n"
                . "Amazon rating: {$ratingNote}\n\n"
                . "Category features to score:\n"
                . json_encode($featureMap, JSON_PRETTY_PRINT) . "\n\n"
                . "=== STAGE 1: DATA QUALITY GATE ===\n\n"
                . "CRITICAL: Only ignore products that are CLEARLY not a main device in the \"{$category->name}\" category.\n"
                . "When in doubt, SCORE the product — do NOT ignore it. False ignores are worse than scoring a marginal product.\n\n"
                . "IGNORE RULE A — ACCESSORIES ONLY: Ignore ONLY if the product is clearly NOT a standalone main device in \"{$category->name}\":\n"
                . "  - Replacement parts, spare components, or consumables\n"
                . "  - Accessories, add-ons, stands, mounts, cases, or cleaning supplies\n"
                . "  - Cables, adapters, or converters\n"
                . "  - Multi-item bundles that are NOT centered on a single main device\n"
                . "DO NOT ignore: color/size variants, refurbished units, or products with verbose titles.\n"
                . "If the product functions as a standalone {$category->name} device, you MUST score it.\n"
                . "To ignore, return EXACTLY: " . '{"status": "ignored", "reason": "accessory_or_bundle"}' . "\n\n"
                . "IGNORE RULE B — GENERIC / WHITE-LABEL: If the product has no recognizable, reputable brand.\n"
                . "This includes 'Generic', 'Unbranded', random Chinese model numbers as brands, and ultra-cheap no-name products.\n"
                . "Only ignore true no-name items with titles like 'Generic', 'Unbranded', or random model numbers as the brand.\n"
                . "To ignore, return EXACTLY: " . '{"status": "ignored", "reason": "generic_white_label"}' . "\n\n"
                . "=== STAGE 2: BRAND NORMALIZATION (apply before writing the brand field) ===\n\n"
                . "Unify brand names to their most common, clean English-language form. Strict rules:\n"
                . "- Strip non-ASCII characters used as stylistic affectations: 'RØDE' → 'Rode', 'Beyerdynamic' stays.\n"
                . "- Remove subsidiary/division suffixes: 'AKG Professional' → 'AKG', 'Blue Microphones' → 'Blue'.\n"
                . "- Resolve umbrella brands: '512 Audio by Warm Audio' → 'Warm Audio'.\n"
                . "- Always use the parent consumer brand, not the Amazon storefront name.\n"
                . "- Capitalize correctly: 'BRANDNAME' → 'Brandname'.\n\n"
                . "=== STAGE 2.5: NAME NORMALIZATION (derive the 'name' field) ===\n\n"
                . "The raw Amazon title is verbose marketing copy. You MUST produce a clean, short product name:\n"
                . "- Keep ONLY: Brand + Model name + essential differentiator (e.g. color or size variant if it's the main SKU distinction).\n"
                . "- STRIP everything after a comma or slash in the title that lists specs or compatibility:\n"
                . "  'Hollyland Lark M2 Wireless Microphone for iPhone/Camera/Android/PC, 48kHz/24-bit...' → 'Hollyland Lark M2'\n"
                . "- STRIP parenthetical variant/bundle info entirely: '(Black, with Camera RX + USB-C RX)' → remove.\n"
                . "- STRIP marketing adjectives that are not part of the official model name: 'High Fidelity', 'Premium', 'Professional'.\n"
                . "- Maximum 60 characters. When in doubt, use only Brand + Model (e.g. 'Sony WH-1000XM5', 'Shure MV7+', 'Rode NT-USB Mini').\n\n"
                . "=== STAGE 3: SCORING RULES ===\n\n"
                . "1. WORLD KNOWLEDGE OVERRIDES EVERYTHING: Base scores on your internal knowledge of this specific model.\n"
                . "2. ABSOLUTE SCORING (1-100): 50 = average/mediocre. Budget brands CANNOT score 80+ on quality features.\n"
                . "3. STRICT TRADE-OFFS: Create contrast. If a feature is irrelevant or bad, score it 20-40.\n"
                . "4. OBSCURE PRODUCTS: If you don't recognise the model, infer from brand tier + price. Default to 40-50.\n\n"
                . "Return ONLY a valid JSON object in this EXACT format (no markdown, no code blocks):\n"
                . '{"name": "Brand Model", "brand": "Normalized Brand Name", "ai_summary": "Brutal 2-sentence summary.", '
                . '"price_tier": 2, "amazon_rating": null, "amazon_reviews_count": null, '
                . '"features": {"Feature_Name": {"score": 75, "reason": "One sentence."}, "Other_Feature": null}}';

            $gemini = app(GeminiService::class);
            $result = $gemini->generate($prompt, ['maxOutputTokens' => 4096]);
            $parsed = $result['parsed'];

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

            // Guard: if AI returned just the brand name (e.g. "Breville") instead of a
            // real product name, keep the original scraped title which has more detail.
            $aiName = $parsed['name'];
            $originalName = $product->name;
            if (mb_strlen($aiName) < 20 && mb_strlen($originalName) > mb_strlen($aiName)) {
                $aiName = mb_substr($originalName, 0, 255);
            }

            $brand = Brand::firstOrCreate(
                ['name' => $parsed['brand'], 'tenant_id' => $product->tenant_id],
                ['name' => $parsed['brand'], 'tenant_id' => $product->tenant_id]
            );

            $product->update([
                'name'                 => $aiName,
                'slug'                 => Str::slug($aiName . '-' . Str::random(5)),
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

            // Download and store the product image locally (non-fatal — wrapped in its own try/catch)
            $this->downloadAndStoreImage($product, $parsed['brand'], $parsed['name']);

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

    /**
     * Download the product image from Amazon and store it locally.
     * Failures are logged but never propagate — a missing image must not abort the AI job.
     *
     * Filename format: {brand-slug-max-4-words}-{asin}.{ext}
     * Example: razer-seiren-mini-usb-B0D3MB36XV.jpg
     */
    private function downloadAndStoreImage(Product $product, string $brandName, string $productName): void
    {
        $imageUrl = $product->external_image_path;

        if (empty($imageUrl)) {
            return;
        }

        try {
            // SSRF protection: only allow known Amazon CDN domains
            $host = parse_url($imageUrl, PHP_URL_HOST);
            if (!in_array($host, config('services.amazon.allowed_image_hosts', []))) {
                Log::warning('ProcessPendingProduct: image host not allowed', ['host' => $host]);
                return;
            }

            $response = Http::timeout(15)->get($imageUrl);

            if (!$response->successful()) {
                Log::warning('ProcessPendingProduct: image download failed', ['status' => $response->status(), 'url' => $imageUrl]);
                return;
            }

            $contentType = $response->header('Content-Type');
            if (!str_starts_with($contentType, 'image/')) {
                Log::warning('ProcessPendingProduct: URL did not return an image', ['content_type' => $contentType]);
                return;
            }

            $extension = match (true) {
                str_contains($contentType, 'png')  => 'png',
                str_contains($contentType, 'webp') => 'webp',
                default                            => 'jpg',
            };

            // Build a short, meaningful filename from brand + first words of product name
            $allWords  = array_filter(explode(' ', "{$brandName} {$productName}"));
            $slugWords = array_slice($allWords, 0, 4);
            $stem      = Str::slug(implode(' ', $slugWords));
            $asin      = $product->external_id ?? Str::random(10);
            $filename  = "{$stem}-{$asin}.{$extension}";
            $path      = "products/images/{$filename}";

            Storage::disk('public')->put($path, $response->body());

            // Optimize: convert to WebP, resize to 800px max width
            $absolutePath = Storage::disk('public')->path($path);
            $webpPath = \App\Services\ImageOptimizer::toWebp($absolutePath);
            $path = str_replace(Storage::disk('public')->path(''), '', $webpPath);

            $product->update(['image_path' => $path]);

            Log::info('ProcessPendingProduct: image stored', ['path' => $path]);
        } catch (\Exception $e) {
            Log::warning('ProcessPendingProduct: image download skipped', [
                'product_id' => $product->id,
                'url'        => $imageUrl,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
