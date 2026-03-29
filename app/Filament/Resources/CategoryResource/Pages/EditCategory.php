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
        $gemini = app(\App\Services\GeminiService::class);
        $result = $gemini->generate($prompt, [
            'timeout'         => 120,
            'maxOutputTokens' => 8000,
        ], config('services.gemini.admin_model'));

        return $result['content'];
    }

    // ─── HELPER: Call Gemini Image API and save file ─────────────

    /**
     * Calls the Gemini image generation API and saves the resulting image to storage.
     * Returns the storage path (e.g. "categories/images/slug-ai.png") on success.
     * Throws on any failure so callers can handle notifications themselves.
     */
    private function callGeminiImage(string $imagePrompt, $record): string
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            throw new \Exception('GEMINI_API_KEY is not set in your .env file.');
        }

        $response = Http::timeout(120)->withHeaders([
            'x-goog-api-key' => $apiKey,
        ])->post('https://generativelanguage.googleapis.com/v1beta/models/' . config('services.gemini.image_model') . ':generateContent', [
            'contents' => [
                ['parts' => [['text' => $imagePrompt]]]
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
                $mimeType  = $part['inlineData']['mimeType'] ?? 'image/png';
                break;
            }
        }

        if (!$imageData) {
            throw new \Exception('No image data returned from AI. The model may not support image generation with this prompt.');
        }

        $extension = match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp'              => 'webp',
            default                   => 'png',
        };

        $filename = 'categories/images/' . $record->slug . '-ai.' . $extension;
        Storage::disk('public')->put($filename, base64_decode($imageData));

        // Optimize: convert to WebP, resize to 800px, delete original
        $absolutePath = Storage::disk('public')->path($filename);
        $webpPath = \App\Services\ImageOptimizer::toWebp($absolutePath);
        $filename = str_replace(Storage::disk('public')->path(''), '', $webpPath);

        $record->update(['image' => $filename]);

        return $filename;
    }

    // ─── HELPER: Persist presets from a decoded AI response ──────

    /**
     * Creates/updates presets and syncs their feature weights.
     * Used by both generatePresetsAction and generateAllAction.
     *
     * @param array $decoded     The decoded AI JSON response containing 'presets'.
     * @param mixed $record      The category record.
     * @param array $mappedFeatures  Map of lowercase feature name → Feature model.
     */
    private function syncPresetsToRecord(array $decoded, $record, array $mappedFeatures): void
    {
        foreach ($decoded['presets'] as $presetData) {
            $preset = Preset::firstOrCreate([
                'category_id' => $record->id,
                'name'        => $presetData['name'],
            ]);

            if (!empty($presetData['seo_description'])) {
                $preset->update(['seo_description' => substr(trim($presetData['seo_description']), 0, 160)]);
            }

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
    }

    // ─── HELPER: Persist sample_prompts from a decoded AI response ─

    /**
     * Saves sample_prompts to the category record if present in the AI response.
     * Uses forceFill to bypass the $fillable guard on sample_prompts.
     */
    private function saveSamplePrompts(array $decoded, $record): void
    {
        if (!empty($decoded['sample_prompts']) && is_array($decoded['sample_prompts'])) {
            $record->forceFill(['sample_prompts' => array_values(array_filter($decoded['sample_prompts']))])->save();
        }
    }

    // ─── HELPER: Persist price tier thresholds from a decoded AI response ──

    /**
     * Saves budget_max and midrange_max to the category record if present.
     * These drive Category::priceTierFor() and the frontend price slider labels.
     */
    private function savePriceTiers(array $decoded, $record): void
    {
        $tiers = $decoded['price_tiers'] ?? null;
        if (
            is_array($tiers)
            && isset($tiers['budget_max'], $tiers['midrange_max'])
            && $tiers['midrange_max'] > $tiers['budget_max']
        ) {
            $record->update([
                'budget_max'   => (int) $tiers['budget_max'],
                'midrange_max' => (int) $tiers['midrange_max'],
            ]);
        }
    }

    private function imagePrompt(string $categoryName): string
    {
        return "Top-down flat lay of premium {$categoryName}, on a pristine clean white background. Minimalist tech aesthetic, soft diffused studio lighting, highly realistic professional product photography, clean composition with elegant negative space, crisp focus, no text.";
    }

    // ─── ACTION 1: Generate Category Image ─────────────────────

    private function generateImageAction(): Action
    {
        return Action::make('generateImage')
            ->label('🖼️ Generate Image')
            ->form(function ($record) {
                $defaultPrompt = $this->imagePrompt($record->name);

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
                    $this->callGeminiImage($data['image_prompt'], $record);

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
                $defaultPrompt = "You are a product database expert for a consumer e-commerce comparison website. The category is '{$record->name}'.\n\nGenerate a list of exactly 5 to 6 essential features to compare. DO NOT generate more than 6.\n\nCRITICAL RULES:\n1. USER-CENTRIC SCORING: Think like Amazon's 'Customer reviews by feature'. All features must make sense when scored objectively on a 0-100 slider.\n2. STRICTLY AVOID BOOLEANS & SPECS: No Yes/No features, and no raw engineering specs (e.g., don't use \"Battery mAh\", use \"Battery Endurance\").\n3. NO GENERIC FILLERS: STRICTLY AVOID vague, lazy terms like \"Versatility\", \"Feature Set\", \"Extras\", \"Style\", \"Design\", or \"Ease of Use\". Features must be highly specific to the actual category.\n4. NO OVERLAPPING CONCEPTS: Each feature must measure a completely distinct aspect. Do not split related concepts (e.g., combine \"Typing Satisfaction\" and \"Quietness\" into \"Typing Acoustics & Feel\").\n5. PROFESSIONAL NAMING: Use industry-standard, polished names (2-4 words max). For example, prefer \"Input Latency & Speed\" over \"Gaming Responsiveness\", or \"Spatial Audio Accuracy\" over \"Game Sound\".\n6. ORDER MATTERS: Sort the JSON array by absolute importance to the buyer. The most critical deal-breaker features must be first.\n\nAlso include a 'price_tiers' object with two integers: 'budget_max' and 'midrange_max'.\nCRITICAL RULE: Define the absolute real-world USD market pricing for this specific category. 'budget_max' is the maximum price for a cheap/entry-level product (\$). 'midrange_max' is the maximum price for a mid-tier product (\$\$). Anything above 'midrange_max' is premium (\$\$\$).\n\nReturn ONLY a valid JSON object:\n{\"price_tiers\": {\"budget_max\": 50, \"midrange_max\": 150}, \"features\": [{\"name\": \"Feature Name\", \"unit\": \"\", \"is_higher_better\": true}]}";

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

                    $this->savePriceTiers($decoded, $record);

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

    // ─── ACTION 4: Generate Presets + Sample Prompts ────────────

    private function generatePresetsAction(): Action
    {
        return Action::make('generatePresets')
            ->label('👤 Generate Presets & Prompts')
            ->form(function ($record) {
                // Pass existing features as context so the AI can assign weights
                // against the features already in the database for this category.
                $existingFeatures = $record->features()->pluck('name')->implode(', ');
                $defaultPrompt = "You are a product database expert. The category is '{$record->name}'.\n\nThe existing features (sliders) for this category are: {$existingFeatures}\n\nGenerate 4 recommended user profiles (presets) with weights for these features, and 4 short sample search prompts.\n\nCRITICAL RULES FOR PRESET NAMES:\n- Names MUST be 1-2 words maximum. Examples: 'Podcaster', 'Gamer', 'Vocalist', 'Casual', 'Pro'.\n- NEVER use phrases like 'The Starting Podcaster' or 'For Home Recording'. One or two words only.\n\nCRITICAL RULES FOR SAMPLE PROMPTS:\n- Generate exactly 4 short, realistic user search queries (under 6 words each).\n- Examples: 'mic for noisy room', 'budget streaming mic', 'podcast mic under \$100'.\n- They should reflect real things users type, not marketing copy.\n\nCRITICAL RULE FOR FEATURE WEIGHTS (0-100): You MUST force trade-offs. Do NOT assign high weights to everything.\nFor each preset, you must strictly follow this distribution:\n- 1 or 2 Primary Features: 85-100 (The absolute dealbreakers for this persona).\n- 1 or 2 Secondary Features: 60-80 (Nice to have).\n- ALL REMAINING Features: 10-50 (This persona does not care about these relative to the primary ones).\nIf you give one feature a 95, you MUST give another feature a 30. Create realistic contrasts.\n\nCRITICAL RULES FOR SEO DESCRIPTIONS:\n- For each preset, generate an \"seo_description\" (140-160 characters, no truncation).\n- It must explain WHY these specific feature weights matter for this persona and naturally include the category name.\n- Write it as a direct, compelling Google snippet. No fluff, no 'Discover' or 'Find'.\n- Example: \"Top-rated podcast microphones ranked for clarity and low self-noise. Compare the best USB and XLR mics for home studio recording.\"\n\nReturn ONLY a valid JSON object:\n{\"presets\": [{\"name\": \"Podcaster\", \"seo_description\": \"140-160 char Google snippet here.\", \"weights\": {\"Feature Name\": 90, \"Another Feature\": 20}}], \"sample_prompts\": [\"query one\", \"query two\", \"query three\", \"query four\"]}\nNote: preset weights must be integers 0-100.";

                return [
                    Textarea::make('ai_prompt')
                        ->label('AI Prompt')
                        ->required()
                        ->rows(10)
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

                    // Build feature name-to-model map from existing DB features
                    $mappedFeatures = $record->features()->get()
                        ->keyBy(fn($f) => strtolower($f->name))
                        ->all();

                    $this->syncPresetsToRecord($decoded, $record, $mappedFeatures);
                    $this->saveSamplePrompts($decoded, $record);

                    $presetCount  = count($decoded['presets']);
                    $promptsCount = count($decoded['sample_prompts'] ?? []);
                    Notification::make()
                        ->title("Presets ({$presetCount}) & Prompts ({$promptsCount}) Generated")
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()->title('Presets Failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    // ─── ACTION 5: Generate All ─────────────────────────────────

    private function generateAllAction(): Action
    {
        return Action::make('generateAll')
            ->label('🚀 Generate All')
            ->form(function ($record) {
                $defaultPrompt = "You are a product database expert for a consumer e-commerce comparison website. The category is '{$record->name}'.\n\nGenerate all of the following in ONE response:\n1. 5 to 6 essential comparison features\n2. 4 user profile presets with weighted feature importance\n3. A buying guide object\n4. 4 short sample search prompts\n5. Category price tier thresholds\n\nCRITICAL RULES FOR FEATURES (generate exactly 5 to 6, no more):\n1. USER-CENTRIC SCORING: Think like Amazon's 'Customer reviews by feature'. All features must make sense when scored objectively on a 0-100 slider.\n2. STRICTLY AVOID BOOLEANS & SPECS: No Yes/No features, and no raw engineering specs (e.g., don't use \"Battery mAh\", use \"Battery Endurance\").\n3. NO GENERIC FILLERS: STRICTLY AVOID vague, lazy terms like \"Versatility\", \"Feature Set\", \"Extras\", \"Style\", \"Design\", or \"Ease of Use\". Features must be highly specific to the actual category.\n4. NO OVERLAPPING CONCEPTS: Each feature must measure a completely distinct aspect. Do not split related concepts (e.g., combine \"Typing Satisfaction\" and \"Quietness\" into \"Typing Acoustics & Feel\").\n5. PROFESSIONAL NAMING: Use industry-standard, polished names (2-4 words max). For example, prefer \"Input Latency & Speed\" over \"Gaming Responsiveness\", or \"Spatial Audio Accuracy\" over \"Game Sound\".\n6. ORDER MATTERS: Sort the JSON array by absolute importance to the buyer. The most critical deal-breaker features must be first.\n\nCRITICAL RULES FOR PRESET NAMES:\n- Names MUST be 1-2 words maximum. Examples: 'Podcaster', 'Gamer', 'Vocalist', 'Casual', 'Pro'.\n- NEVER use phrases like 'The Starting Podcaster' or 'For Home Recording'. One or two words only.\n\nCRITICAL RULES FOR SAMPLE PROMPTS:\n- Generate exactly 4 short, realistic user search queries (under 6 words each).\n- Examples: 'mic for noisy room', 'budget streaming mic', 'podcast mic under \$100'.\n- They reflect real things users type, not marketing copy.\n\nCRITICAL RULES FOR BUYING GUIDE:\nThe 'buying_guide' property MUST be an object with these exact 3 keys, containing rich HTML strings (use <p>, <ul>, <li>, <strong>; DO NOT use header tags like <h3>):\n- 'how_to_decide': How to prioritize features, referencing the sliders.\n- 'the_pitfalls': 3 common marketing traps to avoid.\n- 'key_jargon': 2-3 technical terms explained simply.\n\nCRITICAL RULE FOR FEATURE WEIGHTS (0-100): You MUST force trade-offs. Do NOT assign high weights to everything.\nFor each preset, you must strictly follow this distribution:\n- 1 or 2 Primary Features: 85-100 (The absolute dealbreakers for this persona).\n- 1 or 2 Secondary Features: 60-80 (Nice to have).\n- ALL REMAINING Features: 10-50 (This persona does not care about these relative to the primary ones).\nIf you give one feature a 95, you MUST give another feature a 30. Create realistic contrasts.\n\nCRITICAL RULE FOR PRICE TIERS: Include a 'price_tiers' object with two integers: 'budget_max' and 'midrange_max'.\nDefine the absolute real-world USD market pricing for this specific category. 'budget_max' is the maximum price for a cheap/entry-level product (\$). 'midrange_max' is the maximum price for a mid-tier product (\$\$). Anything above 'midrange_max' is premium (\$\$\$).\n\nCRITICAL RULES FOR SEO DESCRIPTIONS:\n- For each preset, generate an \"seo_description\" (140-160 characters, no truncation).\n- It must explain WHY these specific feature weights matter for this persona and naturally include the category name.\n- Write it as a direct, compelling Google snippet. No fluff, no 'Discover' or 'Find'.\n- Example: \"Top-rated podcast microphones ranked for clarity and low self-noise. Compare the best USB and XLR mics for home studio recording.\"\n\nReturn ONLY a valid JSON object:\n{\"price_tiers\": {\"budget_max\": 50, \"midrange_max\": 150}, \"buying_guide\": {\"how_to_decide\": \"...\", \"the_pitfalls\": \"...\", \"key_jargon\": \"...\"}, \"features\": [{\"name\": \"...\", \"unit\": \"\", \"is_higher_better\": true}], \"presets\": [{\"name\": \"Podcaster\", \"seo_description\": \"140-160 char Google snippet here.\", \"weights\": {\"Feature\": 90}}], \"sample_prompts\": [\"query one\", \"query two\", \"query three\", \"query four\"]}\nPreset weights: integers 0-100.";

                return [
                    Textarea::make('ai_prompt')
                        ->label('AI Prompt')
                        ->required()
                        ->columnSpanFull()
                        ->rows(14)
                        ->default($defaultPrompt),
                    Toggle::make('clear_existing')
                        ->label('Clear existing features and presets before generating?')
                        ->default(false),
                    Toggle::make('generate_image')
                        ->label('Also generate category hero image?')
                        ->helperText('Adds ~30–60s to the total run time.')
                        ->default(true),
                ];
            })
            ->action(function (array $data, $record, EditRecord $livewire) {
                set_time_limit(300);

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

                    // Price Tiers
                    $this->savePriceTiers($decoded, $record);

                    // Buying Guide
                    if (isset($decoded['buying_guide'])) {
                        $record->update(['buying_guide' => $decoded['buying_guide']]);
                        if (isset($livewire->data) && is_array($livewire->data)) {
                            $livewire->data['buying_guide'] = $decoded['buying_guide'];
                        }
                    }

                    $this->saveSamplePrompts($decoded, $record);

                    // Features — build the name map needed for preset syncing
                    $mappedFeatures = [];
                    foreach ($decoded['features'] as $featureData) {
                        $feature = Feature::firstOrCreate([
                            'category_id' => $record->id,
                            'name'        => $featureData['name'],
                        ], [
                            'unit' => $featureData['unit'] ?? null,
                        ]);
                        $mappedFeatures[strtolower($feature->name)] = $feature;
                    }

                    $this->syncPresetsToRecord($decoded, $record, $mappedFeatures);

                    Notification::make()->title('AI Text Generation Complete')->success()->send();

                    // Optionally generate the hero image
                    if ($data['generate_image']) {
                        $this->callGeminiImage($this->imagePrompt($record->name), $record);

                        Notification::make()->title('Category Image Generated')->success()->send();
                    }

                    // Redirect to refresh the form (image + buying_guide fields)
                    $livewire->redirect($livewire->getResource()::getUrl('edit', ['record' => $record]));
                } catch (\Exception $e) {
                    Notification::make()->title('Generation Failed')->body($e->getMessage())->danger()->send();
                }
            });
    }
}
