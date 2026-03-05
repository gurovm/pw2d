<?php

namespace App\Livewire;

use App\Models\Category;
use Livewire\Component;

class Navigation extends Component
{
    public $categoriesOpen = false;

    public function render()
    {
        return view('livewire.navigation');
    }
}
