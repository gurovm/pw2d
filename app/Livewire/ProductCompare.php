<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SearchLog;
use App\Services\AiService;
use App\Services\ProductScoringService;
use App\Support\SamplePrompts;
use App\Support\SeoSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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

    /**
     * Initial server-render window (Spec 024 / F31 — CWV weight cut).
     *
     * Caps how many scored products are emitted in the FIRST server response.
     * Always <= displayLimit. Raised in 6-card increments by revealMore() as the
     * user scrolls — up to displayLimit, after which the existing "Load more" button
     * takes over by bumping displayLimit (and renderLimit resets to the new ceiling
     * on the next revealMore() call).
     *
     * NOT reflected in the URL — this is a transient render window, not pagination state.
     *
     * H2H Arena mode and pinned-staging bypass this cap entirely (see visibleProducts()).
     */
    public int $renderLimit = 6;

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
            ? Product::with(['brand', 'featureValues.feature', 'offers.store'])->where('slug', $this->selectedProductSlug)->first()
            : null;
    }

    /**
     * Resolve the active Preset model from the URL slug.
     *
     * Matches Str::slug($preset->name) === $activePresetSlug within the category's presets.
     * Eager-loads presets with presetFeatures to avoid N+1 in the view.
     *
     * IMPORTANT: uses the same Str::slug($preset->name) derivation as SeoSchema::forLeafCategory
     * and pw2d:generate-preset-content — this consistency is load-bearing (Spec 023 §10).
     *
     * Exposes the Preset model (including seo_content) to the Blade view so the
     * frontend agent can render preset-specific intro and FAQs.
     */
    #[Computed]
    public function activePreset(): ?Preset
    {
        if (empty($this->activePresetSlug) || !$this->category) {
            return null;
        }

        // Eager-load presets once; avoid N+1 when the view accesses seo_content or features.
        return $this->category
            ->presets()
            ->with('presetFeatures.feature')
            ->get()
            ->first(fn (Preset $p) => Str::slug($p->name) === $this->activePresetSlug);
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
     *
     * Spec 024 (F31 — CWV weight cut): the normal scored-list path is capped at
     * min($renderLimit, $displayLimit) for the INITIAL server response. The frontend
     * calls revealMore() on scroll (x-intersect sentinel) to raise renderLimit in
     * 6-card increments up to displayLimit; thereafter the existing "Load more" button
     * bumps displayLimit and the sentinel can continue.
     *
     * H2H Arena mode (isComparing && compareList) and pinned-staging bypass the
     * renderLimit cap — they always render the full pinned set.
     */
    #[Computed]
    public function visibleProducts()
    {
        // H2H Arena mode: show only the pinned products (still dynamically scored).
        // EXEMPT from renderLimit — render the full pinned set.
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

        // Staging mode: bump pinned products to the top while keeping score order for the rest.
        // EXEMPT from renderLimit — pinned products must not be hidden by the initial cap.
        if (!empty($this->compareList)) {
            $pinned = $this->compareList;
            $scored = $scored->sortByDesc(fn($p) => in_array($p->id, $pinned) ? 1 : 0)->values();

            // With pinned products present, render up to displayLimit (no render cap).
            $topScored = $scored->take($this->displayLimit);
            $topIds = $topScored->pluck('id');
            $scoreMap = $topScored->keyBy('id');

            $fullProducts = Product::whereIn('id', $topIds)
                ->with(['brand', 'featureValues.feature', 'offers.store'])
                ->get()
                ->keyBy('id');

            return $topIds->map(function ($id) use ($fullProducts, $scoreMap) {
                $product = $fullProducts[$id];
                $product->match_score = $scoreMap[$id]->match_score;
                $product->feature_scores = $scoreMap[$id]->feature_scores;
                return $product;
            });
        }

        // Normal scored-list path: cap at renderLimit for the initial server response (Spec 024).
        // renderLimit <= displayLimit always (enforced by revealMore() and mount()).
        $renderCount = min($this->renderLimit, $this->displayLimit);
        $topScored = $scored->take($renderCount);
        $topIds = $topScored->pluck('id');
        $scoreMap = $topScored->keyBy('id');

        // Full data query for only the rendered products — DB/scoring work shrinks too.
        $fullProducts = Product::whereIn('id', $topIds)
            ->with(['brand', 'featureValues.feature', 'offers.store'])
            ->get()
            ->keyBy('id');

        // Restore the sorted order and attach scores.
        return $topIds->map(function ($id) use ($fullProducts, $scoreMap) {
            $product = $fullProducts[$id];
            $product->match_score = $scoreMap[$id]->match_score;
            $product->feature_scores = $scoreMap[$id]->feature_scores;
            return $product;
        });
    }

    /**
     * Products for the JSON-LD ItemList schema and SEO meta description (Spec 024 blocker fix).
     *
     * MUST reflect the full displayLimit set (top 12 on initial load), NOT the render-capped
     * visibleProducts (top 6). Googlebot only sees the initial server response — if we pass
     * visibleProducts to SeoSchema, the ItemList has only 6 entries and the meta description
     * reads "Compare 6 top…" on every initial page load.
     *
     * Fields read by SeoSchema::buildItemListSchema() and forLeafCategory():
     *   - name, slug, ai_summary, amazon_rating, amazon_reviews_count (on Product itself)
     *   - brand.name   → eager-load 'brand'
     *   - offers[0].image_url → eager-load 'offers' (first offer image for schema + og:image)
     *
     * We do NOT need featureValues.feature here (no schema field reads feature data),
     * so this query is cheaper than visibleProducts' full `with(['brand','featureValues.feature','offers.store'])`.
     *
     * Applies the same pinned-then-scored order as visibleProducts so the ItemList position
     * numbers reflect the page's actual display order.
     *
     * Not marked #[Computed] — it reads $displayLimit (mutable public property) and must
     * re-evaluate on every render() call after loadMore() bumps displayLimit.
     */
    public function schemaProducts(): \Illuminate\Support\Collection
    {
        // H2H Arena: schema matches exactly what's displayed.
        if ($this->isComparing && !empty($this->compareList)) {
            $compareIds = collect($this->compareList);
            $scored = $this->scoredProducts->whereIn('id', $compareIds);

            $full = Product::whereIn('id', $compareIds)
                ->with(['brand', 'offers'])
                ->get()
                ->keyBy('id');

            return $scored->pluck('id')->map(fn ($id) => $full[$id]);
        }

        $scored = $this->scoredProducts;

        // Staging mode: match the pinned-bump order used by visibleProducts.
        if (!empty($this->compareList)) {
            $pinned = $this->compareList;
            $scored = $scored->sortByDesc(fn ($p) => in_array($p->id, $pinned) ? 1 : 0)->values();
        }

        // Always take the full displayLimit — independent of renderLimit.
        $topIds = $scored->take($this->displayLimit)->pluck('id');

        return Product::whereIn('id', $topIds)
            ->with(['brand', 'offers'])
            ->get()
            ->keyBy('id')
            // Restore the sorted order so ItemList positions match the page.
            ->sortBy(fn ($p) => $topIds->search($p->id))
            ->values();
    }

    /**
     * Reveal the next batch of products (Spec 024 / F31 — CWV lazy-hydration).
     *
     * Called by the frontend's x-intersect sentinel just below the last rendered card.
     * Raises renderLimit by 6 up to displayLimit, triggering a Livewire round-trip
     * that re-renders the grid with the next 6 cards.
     *
     * Composition with "Load more": once renderLimit reaches displayLimit, the sentinel
     * hides itself (hasMoreToReveal returns false). The existing "Load more" button then
     * raises displayLimit by 12 — after which hasMoreToReveal becomes true again (because
     * renderLimit < displayLimit), so the sentinel re-appears and can continue revealing.
     */
    public function revealMore(): void
    {
        $this->renderLimit = min($this->renderLimit + 6, $this->displayLimit);
    }

    public function openProduct($slug): void
    {
        $this->selectedProductSlug = $slug;

        // Dispatch social/OG meta for client-side <head> update.
        // The computed property is freshly resolved here since $selectedProductSlug was just set.
        // Reuse SeoSchema::forSelectedProduct() so the dispatched payload stays in
        // sync with the SSR path and never bleeds the wrong tenant brand.
        if ($this->selectedProduct) {
            $seo = SeoSchema::forSelectedProduct($this->selectedProduct);

            $this->dispatch('meta:product-opened',
                title:       $seo['title'],
                description: $seo['description'],
                image:       $seo['ogImage'] ?? '',
                url:         $seo['canonical'],
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

    /**
     * Whether there are more scored products to reveal via x-intersect sentinel (Spec 024).
     *
     * True when renderLimit has not yet caught up with displayLimit AND the scored list
     * has products beyond the current renderLimit.
     *
     * The Blade checks this to decide whether to render the x-intersect sentinel and
     * skeleton placeholders. When false, the sentinel is removed; the "Load more" button
     * (guarded by scoredProducts->count() > displayLimit) is the next UX lever.
     *
     * Note: not marked #[Computed] because it reads $renderLimit/$displayLimit which are
     * mutable public properties — Livewire recomputes it on every render automatically.
     */
    public function hasMoreToReveal(): bool
    {
        // H2H Arena and pinned-staging bypass renderLimit in visibleProducts() and
        // already render the full set — no reveal sentinel should appear for them.
        if (!empty($this->compareList)) {
            return false;
        }

        return $this->renderLimit < $this->displayLimit
            && $this->scoredProducts->count() > $this->renderLimit;
    }

    public function loadMore(): void
    {
        $this->displayLimit += 12;
        // renderLimit stays where it is — it is now < displayLimit, so the sentinel
        // re-arms automatically (hasMoreToReveal() returns true again) and revealMore()
        // will stream in the next 6-card batches up to the new displayLimit ceiling.
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        // Spec 024 blocker fix: pass the full displayLimit set to SeoSchema, NOT the render-capped
        // visibleProducts (6 cards). Googlebot sees only the initial response; ItemList and meta
        // description must reflect the intended 12-product set, not the lazy-load window.
        $seo = SeoSchema::forCategoryPage(
            $this->category,
            $this->subcategories,
            $this->selectedProductSlug,
            $this->selectedProduct,
            $this->activePresetSlug,
            $this->schemaProducts(),
            $this->activePreset, // already-resolved model — skips the internal DB lookup (S1 fix)
        );

        // Build sample_prompts for the parent-category hero search typewriter.
        // Only computed when subcategories exist (parent category view).
        $samplePrompts = $this->subcategories->isNotEmpty()
            ? SamplePrompts::forCategory($this->category, $this->subcategories)
            : [];

        return view('livewire.product-compare', [
            'samplePrompts'    => $samplePrompts,
            'activePreset'     => $this->activePreset,
            // Spec 024 (F31): expose reveal-state to the Blade so the frontend agent can
            // wire the x-intersect sentinel and decide whether to show skeleton slots.
            'hasMoreToReveal'  => $this->hasMoreToReveal(),
        ])
            ->layoutData([
                'metaTitle'       => $seo['title'],
                'metaDescription' => $seo['description'],
                'canonicalUrl'    => $seo['canonical'],
                'ogType'          => $seo['ogType'],
                'ogImage'         => $seo['ogImage'],
                'schemasJson'     => array_map(
                    fn (array $s) => json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $seo['schemas'],
                ),
            ]);
    }
}

