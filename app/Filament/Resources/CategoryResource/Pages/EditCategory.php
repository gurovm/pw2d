<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use App\Models\Feature;
use App\Models\Preset;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->generateImageAction(),
                $this->generateBuyingGuideAction(),
                $this->generateFeaturesAction(),
                $this->generatePresetsAction(),
                $this->generateAllAction(),
            ])
            ->label('✨ AI Generator')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->button(),

            Actions\DeleteAction::make(),
        ];
    }

    // ─── HELPER: Call Gemini Text API ───────────────────────────

    private function callGeminiText(string $prompt): string
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            throw new \Exception('GEMINI_API_KEY is not set in your .env file.');
        }

        $response = Http::timeout(120)->withHeaders([
            'Content-Type' => 'application/json',
        ])->post('https://generativelanguage.googleapis.com/v1beta/models/' . config('services.gemini.admin_model') . ':generateContent?key=' . $apiKey, [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ]
        ]);

        if ($response->failed()) {
            throw new \Exception('API request failed: ' . $response->body());
        }

        $responseData = $response->json();
        $text = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Clean markdown wrappers
        $text = preg_replace('/```json\s*/', '', $text);
        $text = preg_replace('/```\s*/', '', $text);

        return trim($text);
    }

    // ─── ACTION 1: Generate Category Image ─────────────────────

    private function generateImageAction(): Action
    {
        return Action::make('generateImage')
            ->label('🖼️ Generate Image')
            ->form(function ($record) {
                $defaultPrompt = "Top-down flat lay of premium {$record->name}, on a pristine clean white background. Minimalist tech aesthetic, soft diffused studio lighting, highly realistic professional product photography, clean composition with elegant negative space, 4k resolution, no text.";

                return [
                    Textarea::make('image_prompt')
                        ->label('Image Prompt')
                        ->required()
                        ->rows(4)
                        ->default($defaultPrompt),
                ];
            })
            ->action(function (array $data, $record, EditRecord $livewire) {
                set_time_limit(120);

                try {
                    $apiKey = config('services.gemini.api_key');
                    if (!$apiKey) {
                        throw new \Exception('GEMINI_API_KEY is not set in your .env file.');
                    }

                    $imageModel = env('AGENT_IMAGE_MODEL', 'gemini-2.5-flash-preview-image-generation');
                    $response = Http::timeout(120)->withHeaders([
                        'Content-Type' => 'application/json',
                    ])->post('https://generativelanguage.googleapis.com/v1beta/models/' . $imageModel . ':generateContent?key=' . $apiKey, [
                        'contents' => [
                            ['parts' => [['text' => $data['image_prompt']]]]
                        ],
                        'generationConfig' => [
                            'responseModalities' => ['TEXT', 'IMAGE'],
                        ],
                    ]);

                    if ($response->failed()) {
                        throw new \Exception('Image API failed: ' . $response->body());
                    }

                    $responseData = $response->json();
                    $parts = $responseData['candidates'][0]['content']['parts'] ?? [];

                    $imageData = null;
                    $mimeType = 'image/png';
                    foreach ($parts as $part) {
                        if (isset($part['inlineData'])) {
                            $imageData = $part['inlineData']['data'];
                            $mimeType = $part['inlineData']['mimeType'] ?? 'image/png';
                            break;
                        }
                    }

                    if (!$imageData) {
                        throw new \Exception('No image data returned from AI. The model may not support image generation with this prompt.');
                    }

                    // Save the image
                    $extension = match ($mimeType) {
                        'image/jpeg', 'image/jpg' => 'jpg',
                        'image/webp' => 'webp',
                        default => 'png',
                    };
                    $filename = 'categories/images/' . $record->slug . '-ai.' . $extension;
                    Storage::disk('public')->put($filename, base64_decode($imageData));

                    // Update record
                    $record->update(['image' => $filename]);

                    Notification::make()
                        ->title('Category Image Generated')
                        ->success()
                        ->send();

                    // Redirect to refresh the FileUpload component
                    $livewire->redirect($livewire->getResource()::getUrl('edit', ['record' => $record]));

                } catch (\Exception $e) {
                    Notification::make()
                        ->title('Image Generation Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    // ─── ACTION 2: Generate Buying Guide ───────────────────────

    private function generateBuyingGuideAction(): Action
    {
        return Action::make('generateBuyingGuide')
            ->label('📖 Generate Buying Guide')
            ->form(function ($record) {
                $defaultPrompt = "You are a product database expert for a consumer e-commerce comparison website. The category is '{$record->name}'.\n\nGenerate a 'buying_guide' JSON object containing rich HTML strings (use <p>, <ul>, <li>, <strong>; DO NOT use header tags like <h3>). The object MUST have these exact 3 keys:\n- 'how_to_decide': A short paragraph explaining how to prioritize features.\n- 'the_pitfalls': A bulleted list of 3 common marketing traps or defects to avoid in this category.\n- 'key_jargon': Explain 2-3 technical terms relevant to this category in simple terms.\n\nReturn ONLY a valid JSON object: {\"buying_guide\": {\"how_to_decide\": \"...\", \"the_pitfalls\": \"...\", \"key_jargon\": \"...\"}}";

                return [
                    Textarea::make('ai_prompt')
                        ->label('AI Prompt')
                        ->required()
                        ->rows(8)
                        ->default($defaultPrompt),
                ];
            })
            ->action(function (array $data, $record, EditRecord $livewire) {
                set_time_limit(120);

                try {
                    $responseText = $this->callGeminiText($data['ai_prompt']);
                    $decoded = json_decode($responseText, true);

                    if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['buying_guide'])) {
                        throw new \Exception('Invalid JSON: expected {"buying_guide": "..."}. Got: ' . substr($responseText, 0, 200));
                    }

                    $record->update(['buying_guide' => $decoded['buying_guide']]);
                    if (isset($livewire->data) && is_array($livewire->data)) {
                        $livewire->data['buying_guide'] = $decoded['buying_guide'];
                    }

                    Notification::make()->title('Buying Guide Generated')->success()->send();

                } catch (\Exception $e) {
                    Notification::make()->title('Buying Guide Failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    // ─── ACTION 3: Generate Features ───────────────────────────

    private function generateFeaturesAction(): Action
    {
        return Action::make('generateFeatures')
            ->label('🎚️ Generate Features')
            ->form(function ($record) {
                $defaultPrompt = "You are a product database expert for a consumer e-commerce comparison website. The category is '{$record->name}'.\n\nGenerate a list of 8 essential features to compare.\n\nCRITICAL RULES:\n1. Think like Amazon's 'Customer reviews by feature'. Use subjective, user-friendly scoring categories.\n2. STRICTLY AVOID overly technical engineering specs.\n3. STRICTLY AVOID Boolean (Yes/No) features. All features must make sense when scored on a 0-100 slider.\n4. ORDER MATTERS: Sort by importance. Most critical features first.\n\nReturn ONLY a valid JSON object:\n{\"features\": [{\"name\": \"Feature Name\", \"unit\": \"\", \"is_higher_better\": true}]}";

                return [
                    Textarea::make('ai_prompt')
                        ->label('AI Prompt')
                        ->required()
                        ->rows(8)
                        ->default($defaultPrompt),
                    Toggle::make('clear_existing')
                        ->label('Clear existing features before generating?')
                        ->default(false),
                ];
            })
            ->action(function (array $data, $record) {
                set_time_limit(120);

                try {
                    if ($data['clear_existing']) {
                        $record->features()->delete();
                    }

                    $responseText = $this->callGeminiText($data['ai_prompt']);
                    $decoded = json_decode($responseText, true);

                    if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['features'])) {
                        throw new \Exception('Invalid JSON: expected {"features": [...]}. Got: ' . substr($responseText, 0, 200));
                    }

                    foreach ($decoded['features'] as $featureData) {
                        Feature::firstOrCreate([
                            'category_id' => $record->id,
                            'name' => $featureData['name'],
                        ], [
                            'unit' => $featureData['unit'] ?? null,
                        ]);
                    }

                    Notification::make()->title('Features Generated (' . count($decoded['features']) . ')')->success()->send();

                } catch (\Exception $e) {
                    Notification::make()->title('Features Failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    // ─── ACTION 4: Generate Presets ────────────────────────────

    private function generatePresetsAction(): Action
    {
        return Action::make('generatePresets')
            ->label('👤 Generate Presets')
            ->form(function ($record) {
                $existingFeatures = $record->features()->pluck('name')->implode(', ');
                $defaultPrompt = "You are a product database expert. The category is '{$record->name}'.\n\nThe existing features (sliders) for this category are: {$existingFeatures}\n\nGenerate 4 recommended user profiles (presets) that set different slider weights for these exact features.\n\nReturn ONLY a valid JSON object:\n{\"presets\": [{\"name\": \"Preset Name\", \"weights\": {\"Feature Name\": 90, \"Another Feature\": 20}}]}\nNote: weights must be integers between 0-100.";

                return [
                    Textarea::make('ai_prompt')
                        ->label('AI Prompt')
                        ->required()
                        ->rows(8)
                        ->default($defaultPrompt),
                    Toggle::make('clear_existing')
                        ->label('Clear existing presets before generating?')
                        ->default(false),
                ];
            })
            ->action(function (array $data, $record) {
                set_time_limit(120);

                try {
                    if ($data['clear_existing']) {
                        $record->presets()->delete();
                    }

                    $responseText = $this->callGeminiText($data['ai_prompt']);
                    $decoded = json_decode($responseText, true);

                    if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['presets'])) {
                        throw new \Exception('Invalid JSON: expected {"presets": [...]}. Got: ' . substr($responseText, 0, 200));
                    }

                    // Build feature name-to-model map
                    $features = $record->features()->get();
                    $mappedFeatures = [];
                    foreach ($features as $f) {
                        $mappedFeatures[strtolower($f->name)] = $f;
                    }

                    foreach ($decoded['presets'] as $presetData) {
                        $preset = Preset::firstOrCreate([
                            'category_id' => $record->id,
                            'name' => $presetData['name'],
                        ]);

                        $syncData = [];
                        if (isset($presetData['weights']) && is_array($presetData['weights'])) {
                            foreach ($presetData['weights'] as $featureName => $weightValue) {
                                $lowerName = strtolower($featureName);
                                if (isset($mappedFeatures[$lowerName])) {
                                    $syncData[$mappedFeatures[$lowerName]->id] = ['weight' => (int) $weightValue];
                                }
                            }
                        }

                        $preset->features()->syncWithoutDetaching($syncData);
                    }

                    Notification::make()->title('Presets Generated (' . count($decoded['presets']) . ')')->success()->send();

                } catch (\Exception $e) {
                    Notification::make()->title('Presets Failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    // ─── ACTION 5: Generate All (Legacy combined) ──────────────

    private function generateAllAction(): Action
    {
        return Action::make('generateAll')
            ->label('🚀 Generate All')
            ->form(function ($record) {
                $defaultPrompt = "You are a product database expert for a consumer e-commerce comparison website. The category is '{$record->name}'.\n\nGenerate a list of 8 essential features to compare, 4 recommended user profiles (presets), and a 'buying_guide' object.\n\nCRITICAL RULES FOR FEATURES:\n1. Think like Amazon's 'Customer reviews by feature'. Use subjective, user-friendly scoring categories.\n2. STRICTLY AVOID overly technical engineering specs.\n3. AVOID Boolean (Yes/No) features. All features must make sense on a 0-100 slider.\n4. ORDER MATTERS: Sort by importance.\n\nCRITICAL RULES FOR BUYING GUIDE:\nThe 'buying_guide' property MUST be an object with these exact 3 keys, containing rich HTML strings (use <p>, <ul>, <li>, <strong>; DO NOT use header tags like <h3>):\n- 'how_to_decide': How to prioritize features, referencing the sliders.\n- 'the_pitfalls': 3 common marketing traps to avoid.\n- 'key_jargon': 2-3 technical terms explained simply.\n\nReturn ONLY a valid JSON object:\n{\"buying_guide\": {\"how_to_decide\": \"...\", \"the_pitfalls\": \"...\", \"key_jargon\": \"...\"}, \"features\": [{\"name\": \"...\", \"unit\": \"\", \"is_higher_better\": true}], \"presets\": [{\"name\": \"...\", \"weights\": {\"Feature\": 90}}]}\nPreset weights: integers 0-100.";

                return [
                    Textarea::make('ai_prompt')
                        ->label('AI Prompt')
                        ->required()
                        ->columnSpanFull()
                        ->rows(12)
                        ->default($defaultPrompt),
                    Toggle::make('clear_existing')
                        ->label('Clear existing features and presets before generating?')
                        ->default(false),
                ];
            })
            ->action(function (array $data, $record, EditRecord $livewire) {
                set_time_limit(120);

                try {
                    if ($data['clear_existing']) {
                        $record->features()->delete();
                        $record->presets()->delete();
                    }

                    $responseText = $this->callGeminiText($data['ai_prompt']);
                    $decoded = json_decode($responseText, true);

                    if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['features']) || !isset($decoded['presets'])) {
                        throw new \Exception('Invalid JSON format returned from AI.');
                    }

                    // Buying Guide
                    if (isset($decoded['buying_guide'])) {
                        $record->update(['buying_guide' => $decoded['buying_guide']]);
                        if (isset($livewire->data) && is_array($livewire->data)) {
                            $livewire->data['buying_guide'] = $decoded['buying_guide'];
                        }
                    }

                    // Features
                    $mappedFeatures = [];
                    foreach ($decoded['features'] as $featureData) {
                        $feature = Feature::firstOrCreate([
                            'category_id' => $record->id,
                            'name' => $featureData['name'],
                        ], [
                            'unit' => $featureData['unit'] ?? null,
                        ]);
                        $mappedFeatures[strtolower($feature->name)] = $feature;
                    }

                    // Presets
                    foreach ($decoded['presets'] as $presetData) {
                        $preset = Preset::firstOrCreate([
                            'category_id' => $record->id,
                            'name' => $presetData['name'],
                        ]);

                        $syncData = [];
                        if (isset($presetData['weights']) && is_array($presetData['weights'])) {
                            foreach ($presetData['weights'] as $featureName => $weightValue) {
                                $lowerName = strtolower($featureName);
                                if (isset($mappedFeatures[$lowerName])) {
                                    $syncData[$mappedFeatures[$lowerName]->id] = ['weight' => (int) $weightValue];
                                }
                            }
                        }
                        $preset->features()->syncWithoutDetaching($syncData);
                    }

                    Notification::make()->title('AI Generation Complete')->success()->send();

                } catch (\Exception $e) {
                    Notification::make()->title('Generation Failed')->body($e->getMessage())->danger()->send();
                }
            });
    }
}
