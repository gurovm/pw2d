<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Front-end routes for all domains. On tenant domains, the
| InitializeTenancyByDomain middleware (from TenancyServiceProvider)
| initializes tenancy so BelongsToTenant scoping kicks in.
| On central domains, tenancy is not initialized — queries run unscoped.
|
*/

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

// Dynamic robots.txt — tenant-aware Sitemap URL so each domain gets its own sitemap pointer.
// NOTE: production nginx may have a static `location = /robots.txt` block that bypasses Laravel.
// Verify post-deploy with: curl -s https://coffee2decide.com/robots.txt | grep Sitemap
Route::get('/robots.txt', function () {
    $host    = request()->getSchemeAndHttpHost();
    $sitemap = "{$host}/sitemap.xml";

    $body = <<<TXT
User-agent: *
Disallow: /admin/
Disallow: /livewire/

Sitemap: {$sitemap}
TXT;

    return response($body, 200, [
        'Content-Type'  => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('robots');
