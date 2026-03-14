<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Preset;
use App\Traits\NormalizesPrompts;
use Livewire\Component;
use Livewire\Attributes\On;

class ComparisonHeader extends Component
{
    use NormalizesPrompts;
    public $features;
    public $weights = [];
    public $priceWeight = 50;
    public $amazonRatingWeight = 50;
    public $presets;
    public $selectedPreset = null;
    public $categoryId;
    public $autoOpen = true;
    public array $samplePrompts = [];

    #[On('ai-weights-updated')]
    public function syncAiWeights($weights, $priceWeight, $amazonRatingWeight)
    {
        $this->weights = $weights;
        $this->priceWeight = $priceWeight;
        $this->amazonRatingWeight = $amazonRatingWeight;

        $this->dispatch('weights-updated',
            weights: $this->weights,
            priceWeight: $this->priceWeight,
            amazonRatingWeight: $this->amazonRatingWeight,
            isFromAi: true
        );

        // Sync Alpine.js slider positions (they own visual state independently of Livewire)
        $this->dispatch('alpine-weights-sync',
            weights: $this->weights,
            priceWeight: $this->priceWeight,
            amazonRatingWeight: $this->amazonRatingWeight
        );

        $this->dispatch('alpine-sliders-dirty');
    }
    
    public function mount($features, $weights, $priceWeight, $amazonRatingWeight, $categoryId, $autoOpen = true)
    {
        $this->features = $features;
        $this->weights = $weights;
        $this->priceWeight = $priceWeight;
        $this->amazonRatingWeight = $amazonRatingWeight;
        $this->categoryId = $categoryId;
        $this->autoOpen = $autoOpen;
        
        $this->presets = Preset::where('category_id', $this->categoryId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $category = Category::with('children')->find($this->categoryId);

        // Priority 1: the category's own prompts
        $prompts = self::normalizePrompts($category?->sample_prompts);

        // Priority 2: aggregate from child categories (parent hub pages have no own prompts)
        if (empty($prompts) && $category?->children->isNotEmpty()) {
            $prompts = $category->children
                ->pluck('sample_prompts')
                ->map(fn($v) => self::normalizePrompts($v))
                ->flatten()
                ->filter()
                ->shuffle()
                ->take(6)
                ->values()
                ->toArray();
        }

        // Priority 3: generate sensible category-aware fallbacks
        if (empty($prompts)) {
            $name = strtolower($category?->name ?? 'product');
            $prompts = [
                "best {$name} for beginners",
                "top budget {$name}",
                "professional {$name} under \$200",
                "{$name} for everyday use",
            ];
        }

        $this->samplePrompts = $prompts;
    }
    
    public function applyPreset($presetId)
    {
        $this->selectedPreset = $presetId;

        if ($presetId === 'balanced') {
            // Reset everything to neutral (50)
            foreach ($this->features as $feature) {
                $this->weights[$feature->id] = 50;
            }
            $this->priceWeight = 50;
            $this->amazonRatingWeight = 50;
        } else {
            // Fetch the preset with its pivot features
            $preset = Preset::with('features')->find($presetId);
            
            if ($preset) {
                // Map feature IDs to their preset weights
                $presetWeights = $preset->features->pluck('pivot.weight', 'id')->toArray();
                
                // Loop through ALL category features
                foreach ($this->features as $feature) {
                    // If the feature is in the preset, use its weight. Otherwise, default to 50.
                    $this->weights[$feature->id] = $presetWeights[$feature->id] ?? 50;
                }
                
                // Keep virtual features neutral for DB presets
                $this->priceWeight = 50;
                $this->amazonRatingWeight = 50;
            }
        }
        
        // Dispatch event to recalculate Match Scores and animate the grid
        $this->dispatch('weights-updated',
            weights: $this->weights,
            priceWeight: $this->priceWeight,
            amazonRatingWeight: $this->amazonRatingWeight
        );

        // Sync Alpine.js slider positions to the preset values
        $this->dispatch('alpine-weights-sync',
            weights: $this->weights,
            priceWeight: $this->priceWeight,
            amazonRatingWeight: $this->amazonRatingWeight
        );

        $this->dispatch('alpine-sliders-dirty');
    }

    public function resetWeights(): void
    {
        $this->selectedPreset = null;

        foreach ($this->features as $feature) {
            $this->weights[$feature->id] = 50;
        }
        $this->priceWeight = 50;
        $this->amazonRatingWeight = 50;

        $this->dispatch('weights-updated',
            weights: $this->weights,
            priceWeight: $this->priceWeight,
            amazonRatingWeight: $this->amazonRatingWeight
        );

        $this->dispatch('alpine-weights-sync',
            weights: $this->weights,
            priceWeight: $this->priceWeight,
            amazonRatingWeight: $this->amazonRatingWeight
        );

        $this->dispatch('alpine-sliders-reset');
    }

    public function updatedWeights()
    {
        $this->dispatch('weights-updated', 
            weights: $this->weights, 
            priceWeight: $this->priceWeight, 
            amazonRatingWeight: $this->amazonRatingWeight
        );
    }

    public function updatedPriceWeight()
    {
        $this->dispatch('weights-updated', 
            weights: $this->weights, 
            priceWeight: $this->priceWeight, 
            amazonRatingWeight: $this->amazonRatingWeight
        );
    }
    
    public function updatedAmazonRatingWeight()
    {
        $this->dispatch('weights-updated', 
            weights: $this->weights, 
            priceWeight: $this->priceWeight, 
            amazonRatingWeight: $this->amazonRatingWeight
        );
    }

    public $aiPrompt = '';
    public $aiMessage = '';
    public $isThinking = false;

    public function submitAiPrompt()
    {
        if (empty(trim($this->aiPrompt))) {
            return;
        }

        $this->isThinking = true;
        $this->dispatch('trigger-ai-concierge', prompt: $this->aiPrompt);
        $this->aiPrompt = ''; 
    }

    #[On('ai-message-received')]
    public function receiveAiMessage($message)
    {
        $this->aiMessage = $message;
        $this->isThinking = false;
    }

    public function toggleAiChat()
    {
        $this->dispatch('toggle-ai-chat'); 
    }

    public function render()
    {
        return view('livewire.comparison-header');
    }
}

