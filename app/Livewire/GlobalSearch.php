<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Product;
use App\Models\SearchLog;
use Livewire\Component;

class GlobalSearch extends Component
{
    public $search = '';

    public function updatedSearch($value)
    {
        if (strlen($value) >= 2) {
            $this->dispatch('global_search_used', query: $value);
        }
        
        if (strlen($value) >= 3) {
            $results = $this->getResultsProperty();
            $count = count($results['categories']) + count($results['products']);

            // Check for a recent log from the same user within the last 10 seconds
            $lastLog = SearchLog::where('type', 'global_search')
                ->where('user_id', auth()->id())
                ->where('created_at', '>=', now()->subSeconds(10))
                ->latest('id')
                ->first();

            // If the new value starts with the previous query, user is still typing the same word — update in-place
            if ($lastLog && str_starts_with($value, $lastLog->query)) {
                $lastLog->update([
                    'query'         => $value,
                    'results_count' => $count,
                    'updated_at'    => now(),
                ]);
            } else {
                // Brand new search intent — create a fresh log
                SearchLog::create([
                    'type'          => 'global_search',
                    'query'         => $value,
                    'category_name' => null,
                    'user_id'       => auth()->id(),
                    'results_count' => $count,
                ]);
            }
        }
    }

    public function getResultsProperty()
    {
        if (strlen($this->search) < 2) {
            return [
                'categories' => [],
                'products' => [],
            ];
        }

        $categories = Category::where('name', 'like', "%{$this->search}%")
            ->limit(5)
            ->get();

        $products = Product::with('category') // Use correct relationship name
            ->where('name', 'like', "%{$this->search}%")
            ->orWhereHas('brand', function ($query) {
                $query->where('name', 'like', "%{$this->search}%");
            })
            ->limit(5)
            ->get();

        return [
            'categories' => $categories,
            'products' => $products,
        ];
    }

    public function render()
    {
        return view('livewire.global-search');
    }
}
