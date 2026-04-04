<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Preset;
use App\Support\SamplePrompts;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class ComparisonHeader extends Component
{
    public $features;
    public $weights = [];
    public $priceWeight = 50;
    public $amazonRatingWeight = 50;
    public $presets;
    public $selectedPreset = null;

    #[Url(as: 'preset', history: true)]
    public ?string $presetSlug = null;

    public $categoryId;
    public $autoOpen = true;
    public array $samplePrompts = [];

    #[On('ai-weights-updated')]
    public function syncAiWeights($weights, $priceWeight, $amazonRatingWeight): void
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

        $this->samplePrompts = SamplePrompts::forCategory($category, $category?->children);

        // Auto-apply preset if URL contains ?preset=
        if ($this->presetSlug) {
            if ($this->presetSlug === 'balanced') {
                $this->applyPreset('balanced');
            } else {
                $matching = $this->presets->first(
                    fn(Preset $p) => Str::slug($p->name) === $this->presetSlug
                );
                if ($matching) {
                    $this->applyPreset($matching->id);
                } else {
                    $this->presetSlug = null; // invalid slug — discard silently
                }
            }
        }
    }
    
    public function applyPreset($presetId): void
    {
        $this->selectedPreset = $presetId;

        if ($presetId === 'balanced') {
            foreach ($this->features as $feature) {
                $this->weights[$feature->id] = 50;
            }
            $this->priceWeight = 50;
            $this->amazonRatingWeight = 50;
            $this->presetSlug = 'balanced';
        } else {
            $preset = Preset::with('features')->find($presetId);

            if ($preset) {
                $presetWeights = $preset->features->pluck('pivot.weight', 'id')->toArray();

                foreach ($this->features as $feature) {
                    $this->weights[$feature->id] = $presetWeights[$feature->id] ?? 50;
                }

                $this->priceWeight = 50;
                $this->amazonRatingWeight = 50;
                $this->presetSlug = Str::slug($preset->name);
            }
        }

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

        $this->dispatch('alpine-sliders-dirty');

        // Resets Alpine's one-shot guard so the next manual drag clears the preset again
        $this->dispatch('alpine-preset-applied');

        // Notify sibling components (e.g. ProductCompare hero) of the new preset slug
        $this->dispatch('preset-slug-changed', slug: $this->presetSlug);
    }

    /**
     * Called from Alpine's fire() exactly once per drag session.
     * Clears the active preset without affecting slider values.
     */
    public function clearPreset(): void
    {
        $this->selectedPreset = null;
        $this->presetSlug = null;
        $this->dispatch('preset-slug-changed', slug: null);
    }

    public function resetWeights(): void
    {
        $this->selectedPreset = null;
        $this->presetSlug = null;

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
        $this->dispatch('preset-slug-changed', slug: null);
    }

    public function updatedWeights(): void
    {
        $this->dispatch('weights-updated', 
            weights: $this->weights, 
            priceWeight: $this->priceWeight, 
            amazonRatingWeight: $this->amazonRatingWeight
        );
    }

    public function updatedPriceWeight(): void
    {
        $this->dispatch('weights-updated', 
            weights: $this->weights, 
            priceWeight: $this->priceWeight, 
            amazonRatingWeight: $this->amazonRatingWeight
        );
    }
    
    public function updatedAmazonRatingWeight(): void
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

    public function submitAiPrompt(): void
    {
        if (empty(trim($this->aiPrompt))) {
            return;
        }

        $this->isThinking = true;
        $this->dispatch('trigger-ai-concierge', prompt: $this->aiPrompt);
        $this->aiPrompt = ''; 
    }

    #[On('ai-message-received')]
    public function receiveAiMessage($message): void
    {
        $this->aiMessage = $message;
        $this->isThinking = false;
    }

    public function toggleAiChat(): void
    {
        $this->dispatch('toggle-ai-chat');
    }

    public function render()
    {
        return view('livewire.comparison-header');
    }
}

