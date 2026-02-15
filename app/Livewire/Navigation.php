<?php

namespace App\Livewire;

use App\Models\Category;
use Livewire\Component;

class Navigation extends Component
{
    public $categoriesOpen = false;

    public function render()
    {
        // Get only root categories (no parent)
        $rootCategories = Category::whereNull('parent_id')
            ->orderBy('name')
            ->get();

        return view('livewire.navigation', [
            'rootCategories' => $rootCategories,
        ]);
    }
}
