<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OfferIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfferIngestionController extends Controller
{
    public function ingest(Request $request, OfferIngestionService $service): JsonResponse
    {
        $validated = $request->validate([
            'url'           => 'required|url|max:2000',
            'store_slug'    => 'required|string|max:100',
            'raw_title'     => 'required|string|min:3|max:500',
            'brand'         => 'nullable|string|max:100',
            'scraped_price' => 'nullable|numeric|min:0',
            'image_url'     => 'nullable|url|max:2000',
            'category_id'   => 'required|exists:categories,id',
            'rating'        => 'nullable|numeric|min:0|max:5',
            'reviews_count' => 'nullable|integer|min:0',
        ]);

        $result = $service->processIncomingOffer($validated);

        return response()->json([
            'success' => true,
            ...$result,
        ]);
    }
}
