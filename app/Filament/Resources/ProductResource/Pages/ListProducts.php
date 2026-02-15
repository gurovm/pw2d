<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            
            Actions\Action::make('importViaAI')
                ->label('Import via AI')
                ->icon('heroicon-o-sparkles')
                ->color('success')
                ->form([
                    Forms\Components\Select::make('category_id')
                        ->label('Category')
                        ->options(\App\Models\Category::pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->helperText('Select the category for this product'),
                    
                    Forms\Components\Textarea::make('raw_text')
                        ->label('Raw Product Text')
                        ->required()
                        ->rows(15)
                        ->helperText('Paste the entire product page content (description, specs, reviews, etc.)'),
                ])
                ->action(function (array $data) {
                    try {
                        // Fetch features for the selected category
                        $category = \App\Models\Category::with('features')->findOrFail($data['category_id']);
                        
                        if ($category->features->isEmpty()) {
                            \Filament\Notifications\Notification::make()
                                ->title('No Features Found')
                                ->body("The selected category has no features defined. Please add features first.")
                                ->danger()
                                ->send();
                            return;
                        }
                        
                        // Build feature map for the prompt (use names as keys since DB keys are null)
                        $featureMap = $category->features->mapWithKeys(function ($feature) {
                            return [$feature->name => [
                                'unit' => $feature->unit,
                                'is_higher_better' => $feature->is_higher_better,
                            ]];
                        })->toArray();
                        
                        // Build system prompt
                        $systemPrompt = "You are an expert product data extraction agent. I will provide raw, messy text from an e-commerce page (including descriptions, specs, and review summaries). Extract the product's Name, Brand, and values for these specific features:\n\n" 
                            . json_encode($featureMap, JSON_PRETTY_PRINT) 
                            . "\n\nCRITICAL RULES:\n"
                            . "- Feature Names: You MUST use the exact feature NAMES as shown in the JSON above (e.g., 'Build quality', 'Weight', 'DPI'). These are the keys in the JSON object.\n"
                            . "- Semantic Matching: If an exact feature name isn't found in the text, look for related terms (e.g., 'Comfort' → 'Ergonomics', 'Feel' → 'Ergonomics') and synthesize them.\n"
                            . "- Scoring (1-100 scale): For qualitative features (like Ergonomics, Build quality), calculate a score. If you see explicit positive/negative review counts, do the math. If you only see text summaries, estimate a fair score based on sentiment.\n"
                            . "- Missing Data: If a feature is completely unmentioned, return null for that name. Do not invent facts.\n"
                            . "- Units: You MUST always convert weight to grams (e.g., convert ounces/lbs to grams). For other units, match the unit specified in the feature definition.\n\n"
                            . "Return ONLY a valid JSON object in this EXACT format:\n"
                            . '{"name": "Product Name", "brand": "Brand Name", "features": {"Build quality": 85, "Weight": 141, "DPI": null}}'
                            . "\n\nIMPORTANT: In the 'features' object, use the exact feature names from the map above (e.g., 'Build quality', 'Weight').\n"
                            . "Do not use markdown or code blocks. Just raw JSON.\n\n"
                            . "Raw product text:\n" . $data['raw_text'];
                        
                        // Call Gemini API
                        $apiKey = config('services.gemini.api_key');
                        $response = \Illuminate\Support\Facades\Http::timeout(30)->post(
                            "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}",
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
                            \Log::error('Gemini API Error in Import', [
                                'status' => $response->status(),
                                'body' => $response->body(),
                            ]);
                            
                            \Filament\Notifications\Notification::make()
                                ->title('AI Service Error')
                                ->body('Could not connect to AI service. Please try again.')
                                ->danger()
                                ->send();
                            return;
                        }
                        
                        $result = $response->json();
                        
                        // Check for truncation
                        $finishReason = $result['candidates'][0]['finishReason'] ?? 'UNKNOWN';
                        if ($finishReason === 'MAX_TOKENS') {
                            \Log::warning('AI Import - Response Truncated', [
                                'finishReason' => $finishReason,
                                'usageMetadata' => $result['usageMetadata'] ?? [],
                            ]);
                            
                            \Filament\Notifications\Notification::make()
                                ->title('AI Response Truncated')
                                ->body('The product description is too long. Try removing some of the extra text (reviews, related products, etc.) and keep only the main product details.')
                                ->warning()
                                ->send();
                            return;
                        }
                        
                        $content = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
                        
                        // Strip markdown code blocks if present
                        $content = preg_replace('/^```json\s*|\s*```$/m', '', trim($content));
                        $content = trim($content);
                        
                        // Parse JSON
                        $parsed = json_decode($content, true);
                        
                        if (!$parsed || !isset($parsed['name']) || !isset($parsed['brand'])) {
                            \Log::error('Invalid AI Response in Import', [
                                'content' => $content,
                                'parsed' => $parsed,
                            ]);
                            
                            \Filament\Notifications\Notification::make()
                                ->title('Invalid AI Response')
                                ->body('Could not parse AI response. Please try again or enter data manually.')
                                ->danger()
                                ->send();
                            return;
                        }
                        
                        // Find or create brand
                        $brand = \App\Models\Brand::firstOrCreate(
                            ['name' => $parsed['brand']],
                            ['name' => $parsed['brand']]
                        );
                        
                        // Create product
                        $product = \App\Models\Product::create([
                            'name' => $parsed['name'],
                            'brand_id' => $brand->id,
                            'amazon_rating' => 0,
                            'amazon_reviews_count' => 0,
                        ]);
                        
                        // Attach category
                        $product->categories()->attach($data['category_id']);
                        
                        // Log parsed data for debugging
                        \Log::info('AI Import - Parsed Data', [
                            'product_name' => $parsed['name'],
                            'brand' => $parsed['brand'],
                            'features' => $parsed['features'] ?? null,
                            'category_features_count' => $category->features->count(),
                        ]);
                        
                        // Attach feature values (skip nulls)
                        $attachedCount = 0;
                        if (isset($parsed['features']) && is_array($parsed['features'])) {
                            foreach ($parsed['features'] as $featureName => $value) {
                                \Log::info('AI Import - Processing Feature', [
                                    'name' => $featureName,
                                    'value' => $value,
                                    'is_null' => $value === null,
                                ]);
                                
                                if ($value !== null) {
                                    // Match by name since keys are null in DB
                                    $feature = $category->features->firstWhere('name', $featureName);
                                    
                                    \Log::info('AI Import - Feature Lookup', [
                                        'name' => $featureName,
                                        'found' => $feature !== null,
                                        'feature_id' => $feature?->id,
                                    ]);
                                    
                                    if ($feature) {
                                        $product->featureValues()->create([
                                            'feature_id' => $feature->id,
                                            'raw_value' => $value,
                                        ]);
                                        $attachedCount++;
                                    }
                                }
                            }
                        }
                        
                        \Log::info('AI Import - Complete', [
                            'product_id' => $product->id,
                            'features_attached' => $attachedCount,
                        ]);
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Product Imported Successfully')
                            ->body("'{$product->name}' has been created with AI-extracted data.")
                            ->success()
                            ->send();
                        
                    } catch (\Exception $e) {
                        \Log::error('Import via AI Error', [
                            'message' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Import Failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
