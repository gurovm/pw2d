<?php

namespace App\Livewire;

use App\Models\Category;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class Home extends Component
{
    public $searchQuery = '';
    public $isSearching = false;
    public $searchError = '';

    /**
     * AI-powered category search.
     */
    public function searchCategory()
    {
        $this->searchError = '';
        
        if (empty(trim($this->searchQuery))) {
            $this->searchError = 'Please enter what you\'re looking for.';
            return;
        }

        $this->isSearching = true;

        try {
            // Get all available categories
            $categories = Category::whereHas('products')
                ->select('name', 'slug', 'description')
                ->get();

            if ($categories->isEmpty()) {
                $this->searchError = 'No categories available yet. Please check back later.';
                $this->isSearching = false;
                return;
            }

            // Prepare category list for LLM
            $categoryList = $categories->map(function ($category) {
                return [
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                ];
            })->toArray();

            // Call Gemini API
            $apiKey = config('services.gemini.api_key');
            $response = Http::timeout(10)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        [
                            'parts' => [
                                [
                                    'text' => "You are a smart routing assistant for a product comparison website. The user will describe what they want in natural language. Your job is to identify the MAIN product category they're interested in and return its slug.\n\nAvailable categories:\n" . json_encode($categoryList, JSON_PRETTY_PRINT) . "\n\nUser request: \"" . $this->searchQuery . "\"\n\nAnalyze the request and identify the primary product category. For example:\n- \"light mouse for studies\" → mouses\n- \"wireless headphones for gym\" → headphones\n- \"gaming laptop under $1000\" → laptops\n\nReturn ONLY a JSON object: {\"slug\": \"category-slug\"}\n\nDo not include markdown formatting, just raw JSON."
                                ]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.5,
                        'maxOutputTokens' => 200,
                    ],
                ]
            );


            if (!$response->successful()) {
                // Log the actual error for debugging
                \Log::error('Gemini API Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'headers' => $response->headers(),
                ]);
                
                // Check if it's a rate limit error
                if ($response->status() === 429) {
                    throw new \Exception('AI is taking a short break (rate limit). Please wait 10 seconds and try again.');
                }
                
                throw new \Exception('AI service unavailable. Please try again.');
            }

            $result = $response->json();
            
            // Log successful response for debugging
            \Log::info('Gemini API Response', ['result' => $result]);
            
            $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

            // Strip markdown code blocks if present (Gemini often wraps JSON in ```json ... ```)
            $content = preg_replace('/^```json\s*|\s*```$/m', '', trim($content));
            $content = trim($content);

            // Parse JSON response
            $parsed = json_decode($content, true);

            if (!isset($parsed['slug'])) {
                \Log::error('Invalid Gemini response format', [
                    'content' => $content,
                    'parsed' => $parsed,
                ]);
                throw new \Exception('Could not determine the best category. Please try being more specific.');
            }

            $slug = $parsed['slug'];

            // Verify slug exists
            if (!$categories->where('slug', $slug)->first()) {
                throw new \Exception('Invalid category match. Please try again.');
            }

            // Flash the user's original query to session for AI Concierge
            session()->flash('ai_initial_prompt', $this->searchQuery);

            // Redirect to category page
            return redirect()->route('category.show', $slug);

        } catch (\Exception $e) {
            \Log::error('Search Category Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->searchError = $e->getMessage();
            $this->isSearching = false;
        }
    }

    public function render()
    {
        // Get popular categories (those with products, ordered by product count)
        $popularCategories = Category::whereHas('products')
            ->withCount('products')
            ->orderByDesc('products_count')
            ->limit(8)
            ->get();

        return view('livewire.home', [
            'popularCategories' => $popularCategories,
        ]);
    }
}
