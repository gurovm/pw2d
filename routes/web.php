<?php

use App\Http\Controllers\SitemapController;
use App\Livewire\Home;
use App\Livewire\ProductCompare;
use Illuminate\Support\Facades\Route;

// Landing Page
Route::get('/', Home::class)->name('home');

// Category Comparison Page
Route::get('/compare/{slug}', ProductCompare::class)->name('category.show');

// Product Detail URL (Fallback for Modal)
Route::get('/product/{product:slug}', ProductCompare::class)->name('product.show');

// Sitemap
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// Static Pages
Route::view('/about', 'pages.about')->name('about');
Route::view('/contact', 'pages.contact')->name('contact');
Route::view('/privacy-policy', 'pages.privacy-policy')->name('privacy-policy');
Route::view('/terms-of-service', 'pages.terms-of-service')->name('terms-of-service');
