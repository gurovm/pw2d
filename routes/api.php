<?php

use App\Http\Controllers\Api\BatchImportController;
use App\Http\Controllers\Api\ProductImportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Chrome Extension Routes — protected by shared secret token, tenant-scoped, rate limited
Route::middleware([
    'App\Http\Middleware\VerifyExtensionToken',
    'App\Http\Middleware\InitializeTenancyFromPayload',
    'throttle:60,1',
])->group(function () {
    Route::get('/categories', [ProductImportController::class, 'categories']);
    Route::get('/existing-asins', [ProductImportController::class, 'existingAsins']);
});

// Import is rate-limited more aggressively (triggers AI + DB write)
Route::middleware([
    'App\Http\Middleware\VerifyExtensionToken',
    'App\Http\Middleware\InitializeTenancyFromPayload',
    'throttle:30,1',
])->group(function () {
    Route::post('/product-import', [ProductImportController::class, 'import']);
    Route::post('/import-product', [ProductImportController::class, 'import']);
    // Bulk SERP import — saves stubs immediately, AI scoring runs via queue
    Route::post('/products/batch-import', [BatchImportController::class, 'import']);
});
