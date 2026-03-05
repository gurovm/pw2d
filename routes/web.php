<?php

use App\Livewire\Home;
use App\Livewire\ProductCompare;
use Illuminate\Support\Facades\Route;

// Landing Page
Route::get('/', Home::class)->name('home');

// Category Comparison Page
Route::get('/compare/{slug}', ProductCompare::class)->name('category.show');

// Product Detail URL (Fallback for Modal)
Route::get('/product/{product:slug}', ProductCompare::class)->name('product.show');
