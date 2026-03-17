<?php

namespace App\Livewire;

use App\Models\Category;
use App\Traits\NormalizesPrompts;
use Livewire\Component;

class Home extends Component
{
    use NormalizesPrompts;

    /**
     * Hint-chip buttons call this; it forwards the query to GlobalSearch
     * via a Livewire event so GlobalSearch can run DB + AI search inline.
     */
    public function setQueryAndSearch(string $query): void
    {
        $this->dispatch('set-search-query', query: $query);
    }

    public function render()
    {
        $popularCategories = Category::whereHas('products')
            ->withCount('products')
            ->orderByDesc('products_count')
            ->limit(8)
            ->get(['id', 'name', 'slug', 'description', 'image']);

        $samplePrompts = Category::whereNotNull('sample_prompts')
            ->get(['id', 'sample_prompts'])
            ->pluck('sample_prompts')
            ->map(fn ($v) => self::normalizePrompts($v))
            ->flatten()
            ->filter()
            ->shuffle()
            ->take(8)
            ->values()
            ->toArray();

        if (empty($samplePrompts)) {
            $samplePrompts = Category::inRandomOrder()
                ->limit(6)
                ->pluck('name')
                ->map(fn ($name) => 'best ' . strtolower($name) . ' for my needs')
                ->values()
                ->toArray();
        }

        if (empty($samplePrompts)) {
            $samplePrompts = ['Tell me what you need...', 'What are you shopping for?'];
        }

        return view('livewire.home', [
            'popularCategories' => $popularCategories,
            'samplePrompts'     => $samplePrompts,
        ]);
    }
}
