<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\SearchLog;
use App\Services\ProductScoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ProductCompare extends Component
{
    public $category;
    public $features; // Keeping this public is fine as it's a small collection

    // Feature weights (feature_id => weight 0-100)
    public $weights = [];

    // Amazon rating weight (virtual feature)
    public $amazonRatingWeight = 50;

    // Price weight (virtual feature)
    public $priceWeight = 50;

    // Limits the number of products displayed
    public $displayLimit = 12;

    // AI Concierge properties
    public $aiMessage = '';
    public $userInput = '';
    public $chatHistory = [];
    public $isAiProcessing = false;
    public $showAiChat = false;

    // Filters
    public $filterBrand = '';
    public $filterPrice = '';

    // Pinterest Modal State
    public $selectedProductSlug = null;

    #[Computed(persist: true)]
    public function availableBrands()
    {
        if (!$this->category) {
            return collect();
        }

        return \App\Models\Brand::whereHas('products', function ($query) {
            $query->where('category_id', $this->category->id);
        })
            ->withCount(['products' => function ($query) {
                $query->where('category_id', $this->category->id);
            }])
            ->orderByDesc('products_count')
            ->get();
    }

    #[Computed]
    public function selectedProduct()
    {
        return $this->selectedProductSlug
            ? \App\Models\Product::with(['brand', 'featureValues.feature'])->where('slug', $this->selectedProductSlug)->first()
            : null;
    }

    /**
     * THE MAGIC HAPPENS HERE:
     * This computed property fetches all matching products, calculates scores in server memory,
     * sorts them, and attaches the scores directly to the product object.
     * It is NEVER sent to the frontend state payload.
     */
    #[Computed]
    public function scoredProducts()
    {
        // Cache the DB query result for 90 seconds keyed by category + filters.
        // Scoring (weights) still happens fresh every render, but the expensive
        // DB round-trip with eager loads is skipped on every slider interaction.
        $cacheKey = "products:cat{$this->category->id}:b{$this->filterBrand}:p{$this->filterPrice}";
        $products = Cache::remember($cacheKey, 90, function () {
            $query = Product::where('category_id', $this->category->id)
                ->with(['brand', 'featureValues.feature']);

            if ($this->filterBrand) {
                $query->where('brand_id', $this->filterBrand);
            }

            if ($this->filterPrice) {
                $query->where('price_tier', $this->filterPrice);
            }

            return $query->get();
        });

        // scoreAllProducts pre-computes feature ranges once (O(N×F) not O(N²×F))
        $scoringService = new ProductScoringService();
        $scored = $scoringService->scoreAllProducts(
            $products,
            $this->features,
            $this->weights,
            $this->amazonRatingWeight,
            $this->priceWeight
        );

        return $scored->sortByDesc('match_score')->values();
    }

    /**
     * Returns only the top X products for the frontend to render.
     */
    #[Computed]
    public function visibleProducts()
    {
        return $this->scoredProducts->take($this->displayLimit);
    }

    public function openProduct($slug)
    {
        $this->selectedProductSlug = $slug;
    }

    public function closeProduct()
    {
        $this->selectedProductSlug = null;
    }

    public function mount($slug = null, ?Product $product = null)
    {
        if ($product && $product->exists) {
            $this->selectedProductSlug = $product->slug;
            $this->category = $product->category;
        } elseif ($slug) {
            $this->category = Category::where('slug', $slug)->firstOrFail();
        } else {
            abort(404);
        }

        $this->features = Feature::where('category_id', $this->category->id)
            ->orderBy('name')
            ->get();

        foreach ($this->features as $feature) {
            $this->weights[$feature->id] = 50;
        }

        if (session()->has('ai_initial_prompt')) {
            $this->userInput = session('ai_initial_prompt');
            $this->showAiChat = true;
            $this->analyzeUserNeeds();
        }
    }

    public function clearFilters()
    {
        $this->filterBrand = '';
        $this->filterPrice = '';
    }

    public function analyzeUserNeeds()
    {
        if (empty(trim($this->userInput))) {
            return;
        }

        $this->isAiProcessing = true;
        $this->showAiChat = true;

        try {
            $featureKeys = $this->features->mapWithKeys(function ($feature) {
                return [$feature->id => [
                    'name' => $feature->name,
                    'unit' => $feature->unit,
                    'is_higher_better' => $feature->is_higher_better,
                ]];
            })->toArray();

            $historyText = '';
            if (!empty($this->chatHistory)) {
                $historyText = "\n\n--- PREVIOUS CONVERSATION HISTORY ---\n";
                foreach ($this->chatHistory as $msg) {
                    $role = $msg['role'] === 'user' ? 'User' : 'You (AI)';
                    $historyText .= "{$role}: {$msg['content']}\n";
                }
                $historyText .= "--------------------------------------\n";
            }

            $promptText = "You are an expert shopping assistant. The user wants to buy a product in the \"{$this->category->name}\" category. Here are the available feature sliders and their details:\n\n" . json_encode($featureKeys, JSON_PRETTY_PRINT) . "\n\nAdditionally, there are two universal sliders:\n- price_weight: Importance of budget (100 = very strict budget/cheap, 50 = neutral/balanced, 0 = budget irrelevant/premium)\n- amazon_rating_weight: Importance of customer reviews (100 = very important, 50 = neutral, 0 = irrelevant)\n{$historyText}\nThe user's NEW request is: \"{$this->userInput}\"\n\nDecide if you have enough information to set the slider weights. You MUST return ONLY a JSON object with this exact structure:\n{\n  \"status\": \"complete\" OR \"needs_clarification\",\n  \"message\": \"A short, friendly message explaining what you did, OR a short clarifying question asking about a specific missing feature. You MUST briefly mention how you handled price and rating based on the implicit context.\",\n  \"weights\": {\n    \"feature_id\": 0-100\n  },\n  \"price_weight\": 0-100,\n  \"amazon_rating_weight\": 0-100\n}\n\nIMPORTANT RULES:\n1. Use feature IDs as keys in the weights object.\n2. In our system, 50 is the NEUTRAL baseline.\n3. DO NOT just ignore price_weight and amazon_rating_weight if the user didn't explicitly say the words 'price' or 'rating'. You are an intelligence system: you MUST infer implicit preferences. For example, if someone says 'for a call center', durability (build quality) and price might be more important than premium features, or rating might be very important for reliability. Adjust price_weight and amazon_rating_weight away from 50 if the context strongly implies a preference, otherwise keep them at 50.\n4. RELATIVE WEIGHTING: setting all features to 90 is mathematically identical to setting them all to 50. You MUST create contrast! If you assign a high priority (>50) to certain features, you MUST forcefully DE-PRIORITIZE (<50) features that are less relevant to the user's specific context. If they are buying for a call center, lower the priority of audiophile features like Sound Quality to below 50 to emphasize the other features.\n5. If this is a follow-up request (based on history), ONLY adjust the weights that the user is talking about, leaving the others as they were implicitly negotiated before. But you still MUST output the complete object with all weights.\n6. Do not use markdown, just raw JSON.";

            $apiKey = config('services.gemini.api_key');
            $response = Http::timeout(15)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/" . config('services.gemini.site_model') . ":generateContent?key={$apiKey}",
                [
                    'contents' => [['parts' => [['text' => $promptText]]]],
                    'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 1200],
                ]
            );

            if (!$response->successful()) {
                throw new \Exception('AI service unavailable. Please try manually adjusting the sliders.');
            }

            $result = $response->json();
            $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $content = preg_replace('/^```json\s*|\s*```$/m', '', trim($content));
            $content = trim($content);
            $parsed = json_decode($content, true);

            if (!isset($parsed['status']) || !isset($parsed['message'])) {
                throw new \Exception('Could not understand the AI response. Please try adjusting sliders manually.');
            }

            $this->aiMessage = $parsed['message'];
            $this->chatHistory[] = ['role' => 'user', 'content' => $this->userInput];
            $this->chatHistory[] = ['role' => 'ai', 'content' => $this->aiMessage];

            $this->dispatch('ai-message-received', message: $this->aiMessage);
            $this->dispatch('ai_concierge_submitted', location: 'category_page', category: $this->category->name, query: $this->userInput);

            SearchLog::create([
                'type' => 'category_ai',
                'query' => $this->userInput,
                'category_name' => $this->category->name,
                'results_count' => $this->scoredProducts->count(),
                'user_id' => auth()->id(),
                'response_summary' => $this->aiMessage,
            ]);

            if (isset($parsed['weights']) && is_array($parsed['weights'])) {
                foreach ($parsed['weights'] as $featureId => $weight) {
                    if (isset($this->weights[$featureId])) {
                        $this->weights[$featureId] = max(0, min(100, (int)$weight));
                    }
                }
            }

            if (isset($parsed['price_weight'])) {
                $this->priceWeight = max(0, min(100, (int)$parsed['price_weight']));
            }
            if (isset($parsed['amazon_rating_weight'])) {
                $this->amazonRatingWeight = max(0, min(100, (int)$parsed['amazon_rating_weight']));
            }

            $this->dispatch(
                'ai-weights-updated',
                weights: $this->weights,
                priceWeight: $this->priceWeight,
                amazonRatingWeight: $this->amazonRatingWeight
            );

            $this->userInput = '';
        } catch (\Exception $e) {
            $this->aiMessage = $e->getMessage();
            $this->dispatch('ai-message-received', message: $this->aiMessage);
        } finally {
            $this->isAiProcessing = false;
        }
    }

    public function sendMessage()
    {
        $this->analyzeUserNeeds();
    }

    #[On('weights-updated')]
    public function handleWeightsUpdated($weights, $priceWeight, $amazonRatingWeight, $isFromAi = false)
    {
        $this->weights = $weights;
        $this->priceWeight = $priceWeight;
        $this->amazonRatingWeight = $amazonRatingWeight;

        if (!$isFromAi) {
            $this->chatHistory = [];
            $this->aiMessage = '';
            $this->dispatch('ai-message-received', message: '');
        }
    }

    #[On('toggle-ai-chat')]
    public function toggleAiChat()
    {
        $this->showAiChat = !$this->showAiChat;
    }

    #[On('trigger-ai-concierge')]
    public function triggerAiConcierge($prompt)
    {
        $this->userInput = $prompt;
        $this->showAiChat = true;
        $this->analyzeUserNeeds();
    }

    public function loadMore()
    {
        $this->displayLimit += 12;
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        if ($this->selectedProductSlug && $this->selectedProduct) {
            $metaTitle = "{$this->selectedProduct->name} - AI Review & Match Score";
            $metaDescription = $this->selectedProduct->ai_summary
                ? \Illuminate\Support\Str::limit(strip_tags($this->selectedProduct->ai_summary), 150)
                : "Read the comprehensive AI review and view the Match Score for the {$this->selectedProduct->brand->name} {$this->selectedProduct->name}.";
            $canonicalUrl = route('product.show', ['product' => $this->selectedProduct->slug]);

            $schema = [
                '@context' => 'https://schema.org/',
                '@type' => 'Product',
                'name' => $this->selectedProduct->name,
                'description' => $this->selectedProduct->ai_summary ? strip_tags($this->selectedProduct->ai_summary) : $this->selectedProduct->name,
                'brand' => ['@type' => 'Brand', 'name' => $this->selectedProduct->brand->name]
            ];

            if ($this->selectedProduct->image_path) {
                $schema['image'] = \Illuminate\Support\Facades\Storage::url($this->selectedProduct->image_path);
            }

            if ($this->selectedProduct->amazon_reviews_count > 0 && $this->selectedProduct->amazon_rating) {
                $schema['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => $this->selectedProduct->amazon_rating,
                    'reviewCount' => $this->selectedProduct->amazon_reviews_count
                ];
            }
        } else {
            $currentYear = date('Y');
            $metaTitle = "{$this->category->name} - Compare Best Models in {$currentYear} | pw2d";

            $descriptionText = '';
            if (is_array($this->category->buying_guide) && !empty($this->category->buying_guide['how_to_decide'])) {
                $descriptionText = strip_tags($this->category->buying_guide['how_to_decide']);
            }
            $metaDescription = !empty($descriptionText)
                ? \Illuminate\Support\Str::limit($descriptionText, 150)
                : "Compare the absolute best {$this->category->name} on the market. Use our AI-driven sliders to find the perfect match for your exact needs.";

            $canonicalUrl = route('category.show', ['slug' => $this->category->slug]);

            $schema = [
                '@context' => 'https://schema.org/',
                '@type' => 'ItemList',
                'name' => 'Best ' . $this->category->name,
                'description' => !empty($descriptionText) ? \Illuminate\Support\Str::limit($descriptionText, 200, '') : '',
                'itemListElement' => []
            ];

            $position = 1;
            // WE NOW USE ONLY THE VISIBLE PRODUCTS FOR SCHEMA (GREAT FOR SEO!)
            foreach ($this->visibleProducts as $product) {
                $schema['itemListElement'][] = [
                    '@type' => 'ListItem',
                    'position' => $position,
                    'item' => [
                        '@type' => 'Product',
                        'name' => $product->name,
                        'url' => route('product.show', ['product' => $product->slug])
                    ]
                ];
                $position++;
            }
        }

        return view('livewire.product-compare')
            ->layoutData([
                'metaTitle' => $metaTitle,
                'metaDescription' => $metaDescription,
                'canonicalUrl' => $canonicalUrl,
                'schemaJson' => json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]);
    }
}
