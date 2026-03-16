<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Preset;
use App\Models\Product;
use App\Models\SearchLog;
use App\Services\ProductScoringService;
use App\Traits\NormalizesPrompts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProductCompare extends Component
{
    use NormalizesPrompts;
    public $category;
    public $subcategories; // Child categories (if this is a parent category)
    public $features; // Keeping this public is fine as it's a small collection

    // AI search for parent category pages
    public $searchQuery = '';
    public $isSearching = false;
    public $searchError = '';

    // Feature weights (feature_id => weight 0-100)
    public $weights = [];

    // Amazon rating weight (virtual feature)
    public $amazonRatingWeight = 50;

    // Price weight (virtual feature)
    public $priceWeight = 50;

    // Limits the number of products displayed; tracked in URL so Googlebot can crawl paginated results
    #[Url(as: 'limit')]
    public int $displayLimit = 12;

    // Active preset slug — read from URL so hero H1/description updates on re-renders too
    #[Url(as: 'preset')]
    public ?string $activePresetSlug = null;

    // AI Concierge properties
    public $aiMessage = '';
    public $userInput = '';
    public $chatHistory = [];
    public $isAiProcessing = false;
    public $showAiChat = false;

    // Filters
    public $filterBrand = '';
    public int $maxPrice = 0;
    public int $selectedPrice = 0;

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
    /**
     * Fetch and score ALL products using a lightweight query (no brand/feature joins).
     * We only need 4 columns + raw feature values for scoring — no need to JOIN brands
     * or eagerly load Feature models for 200+ products.
     */
    #[Computed]
    public function scoredProducts()
    {
        // Cache raw product data as plain arrays (not Eloquent models).
        // Eloquent unserialize for 2000+ objects takes ~90ms; plain arrays take ~5ms.
        // Scoring still runs fresh (weights change), but the DB round-trip is skipped.
        $cacheKey = "products:cat{$this->category->id}:b{$this->filterBrand}:p{$this->selectedPrice}";
        $rawData = Cache::remember($cacheKey, 90, function () {
            return Product::where('category_id', $this->category->id)
                ->where('is_ignored', false)
                ->whereNull('status') // exclude pending_ai / failed (not yet fully scored)
                ->select(['id', 'brand_id', 'amazon_rating', 'price_tier', 'scraped_price'])
                ->with(['featureValues:id,product_id,feature_id,raw_value'])
                ->when($this->filterBrand, fn($q) => $q->where('brand_id', $this->filterBrand))
                ->when($this->selectedPrice < $this->maxPrice, fn($q) => $q->where('scraped_price', '<=', $this->selectedPrice))
                ->get()
                ->map(fn($p) => [
                    'id'             => $p->id,
                    'brand_id'       => $p->brand_id,
                    'amazon_rating'  => $p->amazon_rating,
                    'price_tier'     => $p->price_tier,
                    'scraped_price'  => $p->scraped_price,
                    'fvs'            => $p->featureValues
                        ->map(fn($fv) => ['feature_id' => (int)$fv->feature_id, 'raw_value' => (float)$fv->raw_value])
                        ->toArray(),
                ])
                ->toArray();
        });

        // Convert back to lightweight objects for the scoring service
        $products = collect($rawData)->map(function ($arr) {
            $p = new \stdClass();
            $p->id = $arr['id'];
            $p->brand_id = $arr['brand_id'];
            $p->amazon_rating = $arr['amazon_rating'];
            $p->price_tier = $arr['price_tier'];
            $p->scraped_price = $arr['scraped_price'];
            $price = $arr['scraped_price'];
            $p->estimated_price = $price !== null
                ? ($price < 100 ? round($price / 5) * 5 : round($price / 10) * 10)
                : null;
            $p->featureValues = collect($arr['fvs'])->map(function ($fv) {
                $o = new \stdClass();
                $o->feature_id = $fv['feature_id'];
                $o->raw_value  = $fv['raw_value'];
                return $o;
            });
            return $p;
        });

        $scoringService = new ProductScoringService();
        return $scoringService->scoreAllProducts(
            $products,
            $this->features,
            $this->weights,
            $this->amazonRatingWeight,
            $this->priceWeight
        )->sortByDesc('match_score')->values();
    }

    /**
     * Returns only the top X products with full data (brand, feature values + names)
     * for rendering. We fetch full data for ONLY these products, not all 200+.
     */
    #[Computed]
    public function visibleProducts()
    {
        $topScored = $this->scoredProducts->take($this->displayLimit);
        $topIds = $topScored->pluck('id');
        $scoreMap = $topScored->keyBy('id');

        // Full data query for only the visible products
        $fullProducts = Product::whereIn('id', $topIds)
            ->with(['brand', 'featureValues.feature'])
            ->get()
            ->keyBy('id');

        // Restore the sorted order and attach scores
        return $topIds->map(function ($id) use ($fullProducts, $scoreMap) {
            $product = $fullProducts[$id];
            $product->match_score = $scoreMap[$id]->match_score;
            $product->feature_scores = $scoreMap[$id]->feature_scores;
            return $product;
        });
    }

    public function openProduct($slug)
    {
        $this->selectedProductSlug = $slug;
    }

    public function closeProduct()
    {
        $this->selectedProductSlug = null;
    }

    public function setQueryAndSearch($query)
    {
        $this->searchQuery = $query;
        $this->searchCategory();
    }

    public function searchCategory()
    {
        $this->searchError = '';

        if (empty(trim($this->searchQuery))) {
            $this->searchError = 'Please enter what you\'re looking for.';
            return;
        }

        $this->isSearching = true;

        try {
            $categories = Category::whereHas('products')
                ->select('name', 'slug', 'description')
                ->get();

            if ($categories->isEmpty()) {
                $this->searchError = 'No categories available yet. Please check back later.';
                $this->isSearching = false;
                return;
            }

            $categoryList = $categories->map(fn($c) => [
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
            ])->toArray();

            $contextHint = "The user is currently browsing the '{$this->category->name}' category. Use this as a soft context for ambiguous queries. However, if the user explicitly asks for a product outside this category (e.g., a keyboard while in audio), IGNORE the context and route them to the correct global category.";

            $promptText = "You are a smart routing assistant for a product comparison website. {$contextHint}\n\nThe user will describe what they want in natural language. Your job is to identify the MAIN product category they're interested in and return its slug.\n\nAvailable categories:\n" . json_encode($categoryList, JSON_PRETTY_PRINT) . "\n\nUser request: \"{$this->searchQuery}\"\n\nAnalyze the request and identify the primary product category. Return ONLY a JSON object: {\"slug\": \"category-slug\"}\n\nDo not include markdown formatting, just raw JSON.";

            $apiKey = config('services.gemini.api_key');
            $response = Http::timeout(10)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/" . config('services.gemini.site_model') . ":generateContent?key={$apiKey}",
                [
                    'contents' => [['parts' => [['text' => $promptText]]]],
                    'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 200],
                ]
            );

            if (!$response->successful()) {
                if ($response->status() === 429) {
                    throw new \Exception('AI is taking a short break (rate limit). Please wait 10 seconds and try again.');
                }
                throw new \Exception('AI service unavailable. Please try again.');
            }

            $content = $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $content = trim(preg_replace('/^```json\s*|\s*```$/m', '', trim($content)));
            $parsed = json_decode($content, true);

            if (!isset($parsed['slug'])) {
                throw new \Exception('Could not determine the best category. Please try being more specific.');
            }

            if (!$categories->where('slug', $parsed['slug'])->first()) {
                throw new \Exception('Invalid category match. Please try again.');
            }

            session()->flash('ai_initial_prompt', $this->searchQuery);

            $this->dispatch('ai_search_submitted', location: 'parent_category', query: $this->searchQuery);

            SearchLog::create([
                'type' => 'homepage_ai',
                'query' => $this->searchQuery,
                'category_name' => $categories->where('slug', $parsed['slug'])->first()->name,
                'results_count' => 1,
                'user_id' => auth()->id(),
            ]);

            return redirect()->route('category.show', $parsed['slug']);

        } catch (\Exception $e) {
            SearchLog::create([
                'type' => 'homepage_ai',
                'query' => $this->searchQuery,
                'category_name' => null,
                'results_count' => 0,
                'response_summary' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            $this->searchError = $e->getMessage();
            $this->isSearching = false;
        }
    }

    public function mount($slug = null, ?Product $product = null)
    {
        if ($product && $product->exists) {
            if ($product->is_ignored) {
                abort(404);
            }
            $this->selectedProductSlug = $product->slug;
            $this->category = $product->category;
        } elseif ($slug) {
            $this->category = Category::where('slug', $slug)->firstOrFail();
        } else {
            abort(404);
        }

        $this->subcategories = $this->category->children()->withCount('products')->get();

        // If this is a parent category, skip loading features/weights
        if ($this->subcategories->isNotEmpty()) {
            $this->features = collect();
            return;
        }

        $this->features = Feature::where('category_id', $this->category->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        foreach ($this->features as $feature) {
            $this->weights[$feature->id] = 50;
        }

        $this->maxPrice = (int) (Product::where('category_id', $this->category->id)
            ->where('is_ignored', false)
            ->whereNull('status')
            ->max('scraped_price') ?? 500);
        $this->selectedPrice = $this->maxPrice;

        if (session()->has('ai_initial_prompt')) {
            $this->userInput = session('ai_initial_prompt');
            $this->showAiChat = true;
            $this->analyzeUserNeeds();
        }

        // Clamp URL-supplied limit to a sane range (12–120) to prevent abuse
        $this->displayLimit = max(12, min(120, $this->displayLimit));
        // Round down to the nearest page boundary so ?limit=13 doesn't sneak in
        $this->displayLimit = (int) ceil($this->displayLimit / 12) * 12;
    }

    public function clearFilters()
    {
        $this->filterBrand = '';
        $this->selectedPrice = $this->maxPrice;
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

    #[On('preset-slug-changed')]
    public function handlePresetSlugChanged(?string $slug): void
    {
        $this->activePresetSlug = $slug;
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
                : "Read the comprehensive AI review and view the Match Score for the {$this->selectedProduct->name}.";
            $canonicalUrl = route('product.show', ['product' => $this->selectedProduct->slug]);

            $schema = [
                '@context' => 'https://schema.org/',
                '@type' => 'Product',
                'name' => $this->selectedProduct->name,
                'description' => $this->selectedProduct->ai_summary ? strip_tags($this->selectedProduct->ai_summary) : $this->selectedProduct->name,
                'brand' => ['@type' => 'Brand', 'name' => $this->selectedProduct->brand?->name ?? '']
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
        } elseif ($this->subcategories->isNotEmpty()) {
            $currentYear = date('Y');
            $metaTitle = "{$this->category->name} - Browse Categories | pw2d";
            $metaDescription = $this->category->description
                ? \Illuminate\Support\Str::limit($this->category->description, 150)
                : "Browse all {$this->category->name} subcategories and find the best products for your needs.";
            $canonicalUrl = route('category.show', ['slug' => $this->category->slug]);
            $schema = [
                '@context' => 'https://schema.org/',
                '@type' => 'CollectionPage',
                'name' => $this->category->name,
                'description' => $metaDescription,
            ];
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

            // Preset landing page: override title, description, and canonical with preset-specific values.
            // $activePresetSlug comes from #[Url(as: 'preset')] so it's valid on both initial load and Livewire re-renders.
            if (!empty($this->activePresetSlug)) {
                $activePreset = Preset::where('category_id', $this->category->id)
                    ->get()
                    ->first(fn(Preset $p) => \Illuminate\Support\Str::slug($p->name) === $this->activePresetSlug);

                if ($activePreset) {
                    $metaTitle = "Best {$this->category->name} for {$activePreset->name} | pw2d";
                    $metaDescription = $activePreset->seo_description
                        ?? "Top-ranked {$this->category->name} for {$activePreset->name} users. Compare by the features that matter most for your specific use case.";
                    $canonicalUrl = route('category.show', ['slug' => $this->category->slug]) . "?preset={$this->activePresetSlug}";
                }
            }

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
                $item = [
                    '@type' => 'Product',
                    'name'  => $product->name,
                    'url'   => route('product.show', ['product' => $product->slug]),
                ];

                // image — use Amazon CDN URL (complies with Associates TOS; no local paths)
                if (!empty($product->external_image_path)) {
                    $item['image'] = $product->external_image_path;
                }

                // description — strip any HTML tags from the AI-generated verdict
                if (!empty($product->ai_summary)) {
                    $item['description'] = strip_tags($product->ai_summary);
                }

                // brand — fall back to first word of product name if brand relation is missing
                $brandName = $product->brand?->name ?? explode(' ', $product->name)[0];
                $item['brand'] = ['@type' => 'Brand', 'name' => $brandName];

                // aggregateRating — use real Amazon stars/reviews; fall back reviewCount to 50
                // so Google always has a valid integer alongside ratingValue.
                // Offers (price) intentionally omitted — scraped prices are estimates and
                // violate Google's strict price-matching rules for Merchant Center rich snippets.
                if (!empty($product->amazon_rating)) {
                    $item['aggregateRating'] = [
                        '@type'       => 'AggregateRating',
                        'ratingValue' => $product->amazon_rating,
                        'bestRating'  => 5,
                        'worstRating' => 1,
                        'reviewCount' => $product->amazon_reviews_count > 0
                            ? $product->amazon_reviews_count
                            : 50,
                    ];
                }

                $schema['itemListElement'][] = [
                    '@type'    => 'ListItem',
                    'position' => $position,
                    'item'     => $item,
                ];
                $position++;
            }
        }

        // Build sample_prompts for the parent-category hero search typewriter.
        // Only computed when subcategories exist (parent category view).
        $samplePrompts = [];
        if ($this->subcategories->isNotEmpty()) {
            // Priority 1: the category's own prompts
            $samplePrompts = self::normalizePrompts($this->category->sample_prompts);

            // Priority 2: aggregate from the loaded subcategories
            if (empty($samplePrompts)) {
                $samplePrompts = $this->subcategories
                    ->pluck('sample_prompts')
                    ->map(fn($v) => self::normalizePrompts($v))
                    ->flatten()
                    ->filter()
                    ->shuffle()
                    ->take(6)
                    ->values()
                    ->toArray();
            }

            // Priority 3: category-aware fallback
            if (empty($samplePrompts)) {
                $name = strtolower($this->category->name);
                $samplePrompts = [
                    "best {$name} for beginners",
                    "top budget {$name}",
                    "professional {$name} under \$200",
                    "{$name} for everyday use",
                ];
            }
        }

        return view('livewire.product-compare', ['samplePrompts' => $samplePrompts, 'activePreset' => $activePreset ?? null])
            ->layoutData([
                'metaTitle' => $metaTitle,
                'metaDescription' => $metaDescription,
                'canonicalUrl' => $canonicalUrl,
                'schemaJson' => json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]);
    }
}

