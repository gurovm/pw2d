<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Services\ProductScoringService;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Layout;
use Livewire\Component;

class ProductCompare extends Component
{
    public $category;
    public $features;
    public $products;
    
    // Feature weights (feature_id => weight 0-100)
    public $weights = [];
    
    // Amazon rating weight (virtual feature)
    public $amazonRatingWeight = 50;
    
    // Computed match scores (product_id => score)
    public $matchScores = [];
    
    // AI Concierge properties
    public $aiMessage = '';
    public $userInput = '';
    public $isAiProcessing = false;
    public $showAiChat = false;

    public function mount($slug)
    {
        // Load category by slug
        $this->category = Category::where('slug', $slug)->firstOrFail();

        // Load features for this category
        $this->features = Feature::where('category_id', $this->category->id)
            ->orderBy('name')
            ->get();

        // Load products in this category with their feature values
        $this->products = Product::whereHas('categories', function ($query) {
                $query->where('categories.id', $this->category->id);
            })
            ->with(['brand', 'featureValues.feature'])
            ->get();

        // Initialize weights to 50 (default)
        foreach ($this->features as $feature) {
            $this->weights[$feature->id] = 50;
        }

        // Calculate initial match scores
        $this->calculateMatchScores();
        
        // Check if user came from AI search
        if (session()->has('ai_initial_prompt')) {
            $this->userInput = session('ai_initial_prompt');
            $this->showAiChat = true;
            $this->analyzeUserNeeds();
        }
    }

    /**
     * AI Concierge: Analyze user needs and set sliders intelligently.
     */
    public function analyzeUserNeeds()
    {
        if (empty(trim($this->userInput))) {
            return;
        }

        $this->isAiProcessing = true;
        $this->showAiChat = true;

        try {
            // Prepare feature list for AI
            $featureKeys = $this->features->mapWithKeys(function ($feature) {
                return [$feature->id => [
                    'name' => $feature->name,
                    'unit' => $feature->unit,
                    'is_higher_better' => $feature->is_higher_better,
                ]];
            })->toArray();

            // Call Gemini API
            $apiKey = config('services.gemini.api_key');
            $response = Http::timeout(15)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        [
                            'parts' => [
                                [
                                    'text' => "You are an expert shopping assistant. The user wants to buy a product in the \"{$this->category->name}\" category. Here are the available feature sliders and their details:\n\n" . json_encode($featureKeys, JSON_PRETTY_PRINT) . "\n\nAnalyze the user's prompt: \"{$this->userInput}\"\n\nDecide if you have enough information to set the slider weights. You MUST return ONLY a JSON object with this exact structure:\n{\n  \"status\": \"complete\" OR \"needs_clarification\",\n  \"message\": \"A short, friendly message explaining what you did, OR a short clarifying question asking about a specific missing feature (e.g., budget, ergonomics).\",\n  \"weights\": {\n    \"feature_id\": 0-100\n  }\n}\n\nIMPORTANT:\n- Use feature IDs as keys in the weights object\n- Only include weights you are confident about, or all of them if status is complete\n- Higher weight (80-100) = very important to user\n- Medium weight (50-70) = somewhat important\n- Lower weight (0-30) = not important or opposite of what user wants\n- Do not use markdown, just raw JSON\n- Be concise and friendly in your message"
                                ]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.4,
                        'maxOutputTokens' => 1200,
                    ],
                ]
            );

            if (!$response->successful()) {
                throw new \Exception('AI service unavailable. Please try manually adjusting the sliders.');
            }

            $result = $response->json();
            
            // Log finish reason to debug truncation
            \Log::info('AI Concierge Finish Reason', [
                'finishReason' => $result['candidates'][0]['finishReason'] ?? 'unknown',
                'usageMetadata' => $result['usageMetadata'] ?? []
            ]);
            
            $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

            // Strip markdown code blocks if present
            $content = preg_replace('/^```json\s*|\s*```$/m', '', trim($content));
            $content = trim($content);

            // Log the cleaned content for debugging
            \Log::info('AI Concierge Response', [
                'raw_content' => $result['candidates'][0]['content']['parts'][0]['text'] ?? '',
                'cleaned_content' => $content
            ]);

            // Parse JSON response
            $parsed = json_decode($content, true);

            // Log parsed result
            \Log::info('AI Concierge Parsed', ['parsed' => $parsed]);

            if (!isset($parsed['status']) || !isset($parsed['message'])) {
                \Log::error('AI Concierge Invalid Format', [
                    'content' => $content,
                    'parsed' => $parsed,
                    'json_error' => json_last_error_msg()
                ]);
                throw new \Exception('Could not understand the AI response. Please try adjusting sliders manually.');
            }

            // Update AI message
            $this->aiMessage = $parsed['message'];

            // Update weights if provided
            if (isset($parsed['weights']) && is_array($parsed['weights'])) {
                foreach ($parsed['weights'] as $featureId => $weight) {
                    if (isset($this->weights[$featureId])) {
                        $this->weights[$featureId] = max(0, min(100, (int)$weight));
                    }
                }
                
                // Recalculate match scores with new weights
                $this->calculateMatchScores();
            }

            // Clear user input if complete
            if ($parsed['status'] === 'complete') {
                $this->userInput = '';
            }

        } catch (\Exception $e) {
            $this->aiMessage = $e->getMessage();
        } finally {
            $this->isAiProcessing = false;
        }
    }

    /**
     * Send user response to AI.
     */
    public function sendMessage()
    {
        $this->analyzeUserNeeds();
    }

    /**
     * Calculate match scores for all products.
     */
    public function calculateMatchScores()
    {
        $scoringService = new ProductScoringService();

        foreach ($this->products as $product) {
            $this->matchScores[$product->id] = $scoringService->calculateMatchScore(
                $product,
                $this->features,
                $this->products, // Pass all products for dynamic normalization
                $this->weights,
                $this->amazonRatingWeight
            );
        }

        // Sort products by match score (descending)
        $this->products = $this->products->sortByDesc(function ($product) {
            return $this->matchScores[$product->id] ?? 0;
        })->values();
    }

    /**
     * Called when any weight changes (real-time reactivity).
     */
    public function updatedWeights()
    {
        $this->calculateMatchScores();
    }

    /**
     * Called when Amazon rating weight changes.
     */
    public function updatedAmazonRatingWeight()
    {
        $this->calculateMatchScores();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.product-compare');
    }
}
