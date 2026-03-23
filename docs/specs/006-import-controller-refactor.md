# Spec 006: Refactor ProductImportController::import()

**Priority:** HIGH
**Audit refs:** Reviewer #1 (310-line god method), Reviewer #12 (duplicates ProcessPendingProduct), Reviewer #11 (no Form Requests)

---

## Problem

`ProductImportController::import()` is a 310-line god method that duplicates the AI scoring pipeline already handled by `ProcessPendingProduct`. It:

1. Builds its own Gemini prompt (copy-pasted from the job)
2. Calls the Gemini API inline (synchronous, blocking the HTTP request)
3. Downloads and stores images inline
4. Creates brands, products, and feature values inline

This is the **old** single-product import path (used before `BatchImportController` existed). The `BatchImportController` correctly delegates to `ProcessPendingProduct` via the queue.

## Architecture Decision

**Option A:** Delete `ProductImportController::import()` entirely and route single imports through `BatchImportController` (wrap in a single-item array).

**Option B:** Keep the endpoint but rewrite it to queue a `ProcessPendingProduct` job instead of processing inline.

**Recommended: Option B** — The single-import endpoint is still referenced by the extension's `import-product` route and may be used for one-off admin imports. But it should not process inline.

## Changes Required

### 1. Create `ProductImportRequest` Form Request

**File:** `app/Http/Requests/ProductImportRequest.php` (new)

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Token middleware handles auth
    }

    public function rules(): array
    {
        return [
            'category_id'  => 'required|exists:categories,id',
            'external_id'  => 'required|string|max:20',
            'title'        => 'required|string|min:3|max:500',
            'price'        => 'nullable|numeric|min:0',
            'rating'       => 'nullable|numeric|min:0|max:5',
            'reviews_count'=> 'nullable|integer|min:0',
            'image_url'    => 'nullable|url|max:1000',
        ];
    }
}
```

### 2. Rewrite `import()` to queue instead of process inline

**File:** `app/Http/Controllers/Api/ProductImportController.php`

The `import()` method should:
1. Validate via Form Request
2. Create a stub product (like `BatchImportController` does)
3. Dispatch `ProcessPendingProduct`
4. Return immediately

```php
public function import(ProductImportRequest $request)
{
    $validated = $request->validated();
    $category = Category::with('features')->findOrFail($validated['category_id']);

    if ($category->features->isEmpty()) {
        return response()->json([
            'success' => false,
            'error'   => 'No Features',
            'message' => 'The selected category has no features defined.',
        ], 400);
    }

    $product = Product::updateOrCreate(
        ['external_id' => $validated['external_id'], 'category_id' => $category->id],
        [
            'tenant_id'           => $category->tenant_id,
            'name'                => mb_substr($validated['title'], 0, 255),
            'slug'                => Str::slug(Str::limit($validated['title'], 80)) . '-' . strtolower($validated['external_id']),
            'external_image_path' => $validated['image_url'] ?? null,
            'amazon_rating'       => $validated['rating'] ?? null,
            'amazon_reviews_count'=> $validated['reviews_count'] ?? 0,
            'scraped_price'       => $validated['price'] ?? null,
            'price_tier'          => $category->priceTierFor($validated['price'] ?? null),
            'status'              => 'pending_ai',
            'is_ignored'          => false,
        ]
    );

    ProcessPendingProduct::dispatch($product->id, $category->id);

    return response()->json([
        'success' => true,
        'action'  => $product->wasRecentlyCreated ? 'queued_new' : 'queued_rescan',
        'product' => ['id' => $product->id, 'external_id' => $product->external_id],
    ]);
}
```

This reduces `import()` from 310 lines to ~30 lines, eliminates all duplication, and makes the endpoint non-blocking.

### 3. Also create `BatchImportRequest`

While we're at it, extract `BatchImportController`'s inline validation to a Form Request too.

**File:** `app/Http/Requests/BatchImportRequest.php` (new)

## Files Modified/Created

| File | Action |
|------|--------|
| `app/Http/Requests/ProductImportRequest.php` | **Create** |
| `app/Http/Requests/BatchImportRequest.php` | **Create** |
| `app/Http/Controllers/Api/ProductImportController.php` | Rewrite `import()` (~30 lines) |
| `app/Http/Controllers/Api/BatchImportController.php` | Use `BatchImportRequest` |

## Testing

- **Feature:** POST `/api/product-import` with valid data → product created with `status=pending_ai`, job dispatched.
- **Feature:** POST with duplicate ASIN → existing product updated and re-queued.
- **Feature:** POST with missing `category_id` → 422 validation error.
- **Feature:** POST with category that has no features → 400 error.
