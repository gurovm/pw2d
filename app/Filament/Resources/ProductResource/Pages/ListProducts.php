<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Widgets\ProductStatsWidget;
use App\Jobs\ProcessPendingProduct;
use App\Models\Product;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ProductStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        $failedCount = Product::withoutGlobalScopes()->where('status', 'failed')->count();

        return [
            Actions\CreateAction::make(),

            Actions\Action::make('retryFailed')
                ->label("Retry Failed ({$failedCount})")
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible($failedCount > 0)
                ->requiresConfirmation()
                ->modalHeading('Retry Failed Products')
                ->modalDescription("This will requeue {$failedCount} failed product(s) for AI processing. Each product costs ~\$0.03 in Gemini API usage.")
                ->action(function () {
                    $count = 0;
                    Product::withoutGlobalScopes()
                        ->where('status', 'failed')
                        ->whereNotNull('category_id')
                        ->each(function (Product $product) use (&$count) {
                            $product->update(['status' => 'pending_ai']);
                            ProcessPendingProduct::dispatch($product->id, $product->category_id);
                            $count++;
                        });

                    Notification::make()
                        ->title("Requeued {$count} products")
                        ->body('Failed products have been sent back to the AI processing queue.')
                        ->success()
                        ->send();
                }),

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
                        
                        // Call AiService
                        $aiService = app(\App\Services\AiService::class);
                        $result = $aiService->extractProductFromText($data['raw_text'], $featureMap);
                        $parsed = $result['parsed'];

                        if (!$parsed || !isset($parsed['name']) || !isset($parsed['brand'])) {
                            \Filament\Notifications\Notification::make()
                                ->title('Invalid AI Response')
                                ->body('Could not parse AI response. Please try again or enter data manually.')
                                ->danger()
                                ->send();
                            return;
                        }
                        
                        // Find or create brand (scoped to tenant)
                        $brand = \App\Models\Brand::firstOrCreate(
                            ['name' => $parsed['brand'], 'tenant_id' => tenant('id')],
                            ['name' => $parsed['brand'], 'tenant_id' => tenant('id')]
                        );
                        
                        // Create product with direct category_id FK
                        $product = \App\Models\Product::create([
                            'tenant_id' => tenant('id'),
                            'category_id' => $data['category_id'],
                            'name' => $parsed['name'],
                            'brand_id' => $brand->id,
                            'slug' => Str::slug($parsed['name'] . '-' . Str::random(5)),
                            'amazon_rating' => 0,
                            'amazon_reviews_count' => 0,
                            'status' => null,
                        ]);
                        
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
