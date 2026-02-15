<?php

use App\Livewire\Home;
use App\Livewire\ProductCompare;
use Illuminate\Support\Facades\Route;

// Landing Page
Route::get('/', Home::class)->name('home');

// Category Comparison Page
Route::get('/category/{slug}', ProductCompare::class)->name('category.show');
