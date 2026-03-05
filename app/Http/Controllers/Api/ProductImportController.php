<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImportController extends Controller
{
    /**
     * Get all categories with their feature counts
     */
    public function categories()
    {
        $categories = Category::withCount('features')
            ->orderBy('name')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'features_count' => $category->features_count,
                ];
            });
        
        return response()->json([
            'success' => true,
            'categories' => $categories,
        ]);
    }
    
    /**
     * Get list of all existing external IDs (ASINs) to prevent duplicate scraping
     */
    public function existingAsins(Request $request)
    {
        $query = Product::whereNotNull('external_id');
        
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        
        $asins = $query->pluck('external_id');
            
        return response()->json([
            'success' => true,
            'asins' => $asins,
        ]);
    }
    
    public function import(Request $request)
    {
        // Validate input
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'raw_text'    => 'required|string|min:50|max:50000',
            'image_url'   => 'required|url',
            'product_url' => 'nullable|url|max:1000',
        ]);

        try {
            // Load category with features
            $category = Category::with('features')->findOrFail($validated['category_id']);
            
            if ($category->features->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'error' => 'No Features',
                    'message' => 'The selected category has no features defined.',
                ], 400);
            }
            
            // Build feature map for Gemini prompt
            $featureMap = $category->features->mapWithKeys(function ($feature) {
                return [$feature->name => [
                    'unit' => $feature->unit,
                    'is_higher_better' => $feature->is_higher_better,
                ]];
            })->toArray();
            
// Build system prompt
$systemPrompt = "You are a ruthless, highly skeptical technology appraiser for a premium comparison website. 
Your primary job is to score products based on your vast WORLD KNOWLEDGE of brands and market tiers, using the provided raw text mainly for exact prices and real-time review counts.

Here are the specific features you need to score:\n\n" 
    . json_encode($featureMap, JSON_PRETTY_PRINT) 
    . "\n\nCRITICAL SCORING RULES:\n"
    . "1. WORLD KNOWLEDGE OVERRIDES TEXT: The provided raw text is written by marketers. You MUST IGNORE subjective adjectives like 'crystal clear', 'premium', or 'ultimate'. Rely on your internal knowledge of the brand. If it's a known budget brand (e.g., Tonor, Fifine, generic Chinese letters), its 'Sound Quality' or 'Build Quality' MUST NOT exceed 55-65. Scores of 80-100 are strictly reserved for industry-leading premium brands (e.g., Sony, Shure, Bose).\n"
    . "2. ABSOLUTE SCORING (1-100): 50 represents an average, mediocre product. A cheap $30 product physically cannot score 90 in qualitative features, even if reviewers say 'it is good for the price'. Value for money is NOT what you are scoring. Score the ABSOLUTE quality.\n"
    . "3. OBSCURE PRODUCTS: If you do not recognize the brand or model at all, use the raw text to understand basic specs, but penalize it. Give it neutral/low scores (40-50) for quality features. Do not hallucinate greatness.\n"
    . "4. REAL-TIME DATA EXTRACTION: You must find and extract the exact current 'price', 'amazon_rating', and 'amazon_reviews_count' from the raw text.\n"
    . "5. MISSING DATA: If a feature is completely irrelevant to the product, return null for that name.\n"
    . "- PRODUCT NAME: Create a SHORT, meaningful product name (5-6 words max). Brand + Model + Key Feature. Example: 'Sony WH-1000XM5 Wireless Headphones'.\n"
    . "- THE VERDICT (ai_summary): Write a critical but practical 2-sentence summary. Be honest about the compromises (e.g., 'plasticky build', 'flat sound'), but explicitly identify WHO should buy it (e.g., 'Ideal for students on a strict budget' or 'A reliable backup for basic office calls'). Avoid marketing fluff, but validate the product's value within its specific price tier.\n"
    . "Return ONLY a valid JSON object in this EXACT format:\n"
    . '{"name": "Clean Product Name", "brand": "Brand Name", "ai_summary": "Brutal 2-sentence summary...", "price_tier": 2, "amazon_rating": 4.8, "amazon_reviews_count": 1500, "features": {"price": 149.99, "Feature_Name_1": 85, "Feature_Name_2": 250, "Feature_Name_3": null}}'
    . "\n\nIMPORTANT: Do not use markdown or code blocks. Just raw JSON.\n\n"
    . "Raw product text:\n" . $validated['raw_text'];
            
            // Call Gemini API
            $apiKey = config('services.gemini.api_key');
            $response = Http::timeout(30)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/" . config('services.gemini.admin_model') . ":generateContent?key={$apiKey}",
                [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $systemPrompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.3,
                        'maxOutputTokens' => 4000,
                    ],
                ]
            );
            
            if (!$response->successful()) {
                Log::error('Gemini API Error in API Import', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => 'AI Service Error',
                    'message' => 'Failed to process product data with AI.',
                ], 500);
            }
            
            $result = $response->json();
            
            // Check for truncation
            $finishReason = $result['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            if ($finishReason === 'MAX_TOKENS') {
                Log::warning('AI Import - Response Truncated', [
                    'finishReason' => $finishReason,
                    'usageMetadata' => $result['usageMetadata'] ?? [],
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => 'Response Truncated',
                    'message' => 'The product description is too long. Try with a shorter page.',
                ], 400);
            }
            
            $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            // Strip markdown code blocks if present
            $content = preg_replace('/^```json\s*|\s*```$/m', '', trim($content));
            $content = trim($content);
            
            // Parse JSON response
            $parsed = json_decode($content, true);
            
            if (!isset($parsed['name']) || !isset($parsed['brand'])) {
                Log::error('Invalid AI Response in API Import', [
                    'content' => $content,
                    'parsed' => $parsed,
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid AI Response',
                    'message' => 'Could not parse product data from AI response.',
                ], 400);
            }
            
            // Download and store image — SSRF protection: only allow known Amazon CDN domains
            $imagePath = null;
            $allowedImageHosts = [
                'm.media-amazon.com',
                'images-na.ssl-images-amazon.com',
                'images-eu.ssl-images-amazon.com',
                'images-fe.ssl-images-amazon.com',
            ];
            try {
                $parsedHost = parse_url($validated['image_url'], PHP_URL_HOST);
                if (!in_array($parsedHost, $allowedImageHosts)) {
                    throw new \Exception('Image host not in allowed list: ' . $parsedHost);
                }

                $imageResponse = Http::timeout(15)->get($validated['image_url']);

                if ($imageResponse->successful()) {
                    $contentType = $imageResponse->header('Content-Type');
                    if (!str_starts_with($contentType, 'image/')) {
                        throw new \Exception('URL does not point to an image');
                    }

                    $extension = match(true) {
                        str_contains($contentType, 'jpeg'), str_contains($contentType, 'jpg') => 'jpg',
                        str_contains($contentType, 'png') => 'png',
                        str_contains($contentType, 'webp') => 'webp',
                        default => 'jpg',
                    };

                    $filename = Str::ulid() . '.' . $extension;
                    $path = 'products/images/' . $filename;
                    Storage::disk('public')->put($path, $imageResponse->body());
                    $imagePath = $path;
                    Log::info('Image downloaded successfully', ['path' => $imagePath]);
                }
            } catch (\Exception $e) {
                Log::warning('Image download skipped', [
                    'url'   => $validated['image_url'],
                    'error' => $e->getMessage(),
                ]);
                // Continue without image
            }
            
            // Create or find brand
            $brand = Brand::firstOrCreate(
                ['name' => $parsed['brand']],
                ['name' => $parsed['brand']]
            );
            
            $productData = [
                'category_id' => $category->id, // Ensure category from request is used
                'brand_id' => $brand->id,
                'name' => $parsed['name'],
                'slug' => Str::slug($parsed['name'] . '-' . Str::random(5)), // Create safe slug
                'ai_summary' => $parsed['ai_summary'] ?? null,
                'image_path' => $imagePath, // Update image if new one downloaded
                'external_image_path' => $validated['image_url'], // Always save original external URL
                'affiliate_url' => $validated['product_url'] ?? null,
                'price_tier' => $parsed['price_tier'] ?? null,
                'amazon_rating' => $parsed['amazon_rating'] ?? null,
                'amazon_reviews_count' => $parsed['amazon_reviews_count'] ?? 0,
            ];

            // Anti-Duplicate Logic: Update or Create
            // Priority 1: Check by distinct external_id (ASIN)
            $externalId = $request->input('external_id');
            $categoryId = $category->id;
            
            if ($externalId) {
            $product = Product::updateOrCreate(
                [
                    'external_id' => $externalId,
                    'category_id' => $categoryId
                ],
                $productData
            );
        } else {
            // Priority 2: Fallback to Name + Brand (if no ASIN)
            $product = Product::updateOrCreate(
                [
                    'name' => $parsed['name'],
                    'brand_id' => $brand->id,
                    'category_id' => $categoryId
                ],
                $productData
            );
        }    
            // Attach feature values (Sync/Update)
            $attachedCount = 0;
            $features = $parsed['features'] ?? [];
            
            foreach ($features as $featureName => $value) {
                if ($value === null) {
                    continue;
                }
                
                // Skip 'price' feature - we use price_tier instead
                if (strtolower($featureName) === 'price') {
                    continue;
                }
                
                // Find feature by name
                $feature = $category->features->firstWhere('name', $featureName);
                
                if ($feature) {
                    // Update existing feature value or create new one
                    $product->featureValues()->updateOrCreate(
                        ['feature_id' => $feature->id],
                        ['raw_value' => $value]
                    );
                    $attachedCount++;
                }
            }
            
            Log::info('Product imported/updated successfully via API', [
                'product_id' => $product->id,
                'external_id' => $product->external_id,
                'action' => $product->wasRecentlyCreated ? 'created' : 'updated',
                'features_processed' => $attachedCount,
            ]);
            
            return response()->json([
                'success' => true,
                'action' => $product->wasRecentlyCreated ? 'created' : 'updated',
                'product' => [
                    'id' => $product->id,
                    'external_id' => $product->external_id,
                    'name' => $product->name,
                    'brand' => $brand->name,
                    'image_path' => $imagePath,
                    'features_attached' => $attachedCount,
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Product import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Import Failed',
                'message' => 'An error occurred while processing this product. Please try again.',
            ], 500);
        }
    }
}
