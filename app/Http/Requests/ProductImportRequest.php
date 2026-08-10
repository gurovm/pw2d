<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\ListingHealth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Token middleware handles auth
    }

    public function rules(): array
    {
        return [
            'category_id'     => ['required', Rule::exists('categories', 'id')->where('tenant_id', tenant('id'))],
            'external_id'     => 'required|string|max:20',
            'title'           => 'required|string|min:3|max:500',
            'price'           => 'nullable|numeric|min:0',
            'rating'          => 'nullable|numeric|min:0|max:5',
            'reviews_count'   => 'nullable|integer|min:0',
            'image_url'       => 'nullable|url|max:1000',
            'stock_status'    => 'nullable|string|max:50',
            'condition'       => ['nullable', Rule::in(ListingHealth::CONDITIONS)],
            // Security L2: bound the array so a repeated-element payload can't bloat
            // the listing_flags JSON column / later in_array() scans.
            'listing_flags'   => 'nullable|array|max:5',
            'listing_flags.*' => ['distinct', 'string', Rule::in(ListingHealth::RECOGNIZED_FLAGS)],
        ];
    }
}
