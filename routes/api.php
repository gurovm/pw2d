<?php

use App\Http\Controllers\Api\BatchImportController;
use App\Http\Controllers\Api\OfferIngestionController;
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
    // Bulk SERP import — single request with array payload, no per-product throttle needed
    Route::post('/products/batch-import', [BatchImportController::class, 'import']);
});

// Offer ingestion — higher limit since batch scans send one request per product
Route::middleware([
    'App\Http\Middleware\VerifyExtensionToken',
    'App\Http\Middleware\InitializeTenancyFromPayload',
    'throttle:120,1',
])->group(function () {
    Route::post('/extension/ingest-offer', [OfferIngestionController::class, 'ingest']);
});
