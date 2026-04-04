<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SearchLog;
use App\Services\ProductScoringService;
use App\Support\SamplePrompts;
use App\Support\SeoSchema;
use Illuminate\Support\Facades\Cache;
use App\Services\AiService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProductCompare extends Component
{
    public $category;
    public $subcategories; // Child categories (if this is a parent category)
    public $features; // Keeping this public is fine as it's a small collection

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

    // Focus: auto-pin a product from GlobalSearch (cleared after mount to keep URLs clean)
    #[Url]
    public string $focus = '';

    // AI Concierge properties
    public $aiMessage = '';
    public $userInput = '';
    public $chatHistory = [];
    public $isAiProcessing = false;
    public $showAiChat = false;

    // Head-to-Head compare list (max 4, persisted in session across navigations)
    #[Session]
    public array $compareList = [];
    #[Session]
    public bool $isComparing = false;

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
            ? \App\Models\Product::with(['brand', 'featureValues.feature', 'offers.store'])->where('slug', $this->selectedProductSlug)->first()
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
        $cacheKey = tenant_cache_key("products:cat{$this->category->id}:b{$this->filterBrand}:p{$this->selectedPrice}");
        $rawData = Cache::remember($cacheKey, 90, function () {
            return Product::where('category_id', $this->category->id)
                ->where('is_ignored', false)
                ->whereNull('status') // exclude pending_ai / failed (not yet fully scored)
                ->select(['id', 'brand_id', 'amazon_rating', 'price_tier'])
                ->with([
                    'featureValues:id,product_id,feature_id,raw_value',
                    'offers:id,product_id,scraped_price',
                ])
                ->when($this->filterBrand, fn($q) => $q->where('brand_id', $this->filterBrand))
                ->when($this->selectedPrice < $this->maxPrice, fn($q) => $q->whereHas('offers', fn($oq) => $oq->where('scraped_price', '<=', $this->selectedPrice)))
                ->get()
                ->map(fn($p) => [
                    'id'             => $p->id,
                    'brand_id'       => $p->brand_id,
                    'amazon_rating'  => $p->amazon_rating,
                    'price_tier'     => $p->price_tier,
                    'best_price'     => $p->offers->min('scraped_price'),
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
            $price = $arr['best_price'];
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
        // H2H Arena mode: show only the pinned products (still dynamically scored)
        if ($this->isComparing && !empty($this->compareList)) {
            $compareIds = collect($this->compareList);
            $scored = $this->scoredProducts->whereIn('id', $compareIds);
            $scoreMap = $scored->keyBy('id');

            $fullProducts = Product::whereIn('id', $compareIds)
                ->with(['brand', 'featureValues.feature', 'offers.store'])
                ->get()
                ->keyBy('id');

            return $scored->pluck('id')->map(function ($id) use ($fullProducts, $scoreMap) {
                $product = $fullProducts[$id];
                $product->match_score = $scoreMap[$id]->match_score;
                $product->feature_scores = $scoreMap[$id]->feature_scores;
                return $product;
            });
        }

        $scored = $this->scoredProducts;

        // Staging mode: bump pinned products to the top while keeping score order for the rest
        if (!empty($this->compareList)) {
            $pinned = $this->compareList;
            $scored = $scored->sortByDesc(fn($p) => in_array($p->id, $pinned) ? 1 : 0)->values();
        }

        $topScored = $scored->take($this->displayLimit);
        $topIds = $topScored->pluck('id');
        $scoreMap = $topScored->keyBy('id');

        // Full data query for only the visible products
        $fullProducts = Product::whereIn('id', $topIds)
            ->with(['brand', 'featureValues.feature', 'offers.store'])
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

    public function openProduct($slug): void
    {
        $this->selectedProductSlug = $slug;

        // Dispatch social/OG meta for client-side <head> update.
        // The computed property is freshly resolved here since $selectedProductSlug was just set.
        if ($this->selectedProduct) {
            $imageUrl = $this->selectedProduct->image_url;
            $absImage = $imageUrl
                ? (str_starts_with($imageUrl, 'http') ? $imageUrl : url($imageUrl))
                : null;

            $this->dispatch('meta:product-opened',
                title:       "{$this->selectedProduct->name} - AI Review & Match Score | pw2d",
                description: \Illuminate\Support\Str::limit(
                    strip_tags($this->selectedProduct->ai_summary ?? "Read the AI review for the {$this->selectedProduct->name}."),
                    155
                ),
                image: $absImage ?? '',
                url:   url('/product/' . $this->selectedProduct->slug),
            );
        }
    }

    public function closeProduct(): void
    {
        $this->selectedProductSlug = null;
        // JS listener will restore <head> tags from data-default attributes
        $this->dispatch('meta:product-closed');
    }

    public function mount($slug = null, ?Product $product = null)
    {
        if ($product && $product->exists) {
            if ($product->is_ignored) {
                abort(410, 'This product is no longer available in our catalog.');
            }
            if (!$product->category) {
                abort(404, 'This product has not been categorized yet.');
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

        $this->maxPrice = (int) (ProductOffer::whereHas('product', fn ($q) => $q
                ->where('category_id', $this->category->id)
                ->where('is_ignored', false)
                ->whereNull('status'))
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

        // Focus & Bump: auto-pin a product from GlobalSearch, then clear the URL param
        if (!empty($this->focus)) {
            $focusedProduct = Product::where('slug', $this->focus)
                ->where('category_id', $this->category->id)
                ->whereNull('status')
                ->where('is_ignored', false)
                ->first();

            if ($focusedProduct && !in_array($focusedProduct->id, $this->compareList) && count($this->compareList) < 4) {
                $this->compareList[] = $focusedProduct->id;
            }

            $this->focus = '';
        }
    }

    public function toggleCompare(int $productId): void
    {
        if (in_array($productId, $this->compareList)) {
            $this->compareList = array_values(array_filter(
                $this->compareList,
                fn($id) => $id !== $productId
            ));
            return;
        }

        if (count($this->compareList) >= 4) {
            $this->dispatch('compare-limit-reached');
            return;
        }

        $this->compareList[] = $productId;
    }

    public function clearCompare(): void
    {
        $this->compareList = [];
        $this->isComparing = false;
    }

    public function startComparison(): void
    {
        if (count($this->compareList) >= 2) {
            $this->isComparing = true;
        }
    }

    public function stopComparison(): void
    {
        $this->isComparing = false;
    }

    public function clearFilters(): void
    {
        $this->filterBrand = '';
        $this->selectedPrice = $this->maxPrice;
    }

    public function analyzeUserNeeds(): void
    {
        if (empty(trim($this->userInput))) {
            return;
        }

        // Rate limit: max 10 AI calls per minute per session/IP
        $rateLimitKey = 'ai-search:' . (session()->getId() ?? request()->ip());
        if (cache()->get($rateLimitKey, 0) >= 10) {
            $this->aiMessage = 'Too many requests. Please wait a moment before trying again.';
            $this->dispatch('ai-message-received', message: $this->aiMessage);
            return;
        }
        $newCount = cache()->increment($rateLimitKey);
        // Set the TTL on first increment; subsequent increments preserve the existing key
        if ($newCount === 1) {
            cache()->put($rateLimitKey, 1, 60);
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

            $aiService = app(AiService::class);
            $result = $aiService->chatResponse(
                $this->category->name, $featureKeys, $this->userInput, $this->chatHistory
            );
            $parsed = $result['parsed'];

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

    public function sendMessage(): void
    {
        $this->analyzeUserNeeds();
    }

    #[On('preset-slug-changed')]
    public function handlePresetSlugChanged(?string $slug): void
    {
        $this->activePresetSlug = $slug;
    }

    #[On('weights-updated')]
    public function handleWeightsUpdated($weights, $priceWeight, $amazonRatingWeight, $isFromAi = false): void
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
    public function toggleAiChat(): void
    {
        $this->showAiChat = !$this->showAiChat;
    }

    #[On('trigger-ai-concierge')]
    public function triggerAiConcierge($prompt): void
    {
        $this->userInput = $prompt;
        $this->showAiChat = true;
        $this->analyzeUserNeeds();
    }

    public function loadMore(): void
    {
        $this->displayLimit += 12;
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $seo = SeoSchema::forCategoryPage(
            $this->category,
            $this->subcategories,
            $this->selectedProductSlug,
            $this->selectedProduct,
            $this->activePresetSlug,
            $this->visibleProducts,
        );

        // Build sample_prompts for the parent-category hero search typewriter.
        // Only computed when subcategories exist (parent category view).
        $samplePrompts = $this->subcategories->isNotEmpty()
            ? SamplePrompts::forCategory($this->category, $this->subcategories)
            : [];

        return view('livewire.product-compare', [
            'samplePrompts' => $samplePrompts,
            'activePreset'  => $seo['activePreset'],
        ])
            ->layoutData([
                'metaTitle'       => $seo['title'],
                'metaDescription' => $seo['description'],
                'canonicalUrl'    => $seo['canonical'],
                'ogType'          => $seo['ogType'],
                'ogImage'         => $seo['ogImage'],
                'schemaJson'      => json_encode($seo['schemas'][0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
    }
}

