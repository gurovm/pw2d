<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\ListingHealth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Token middleware handles auth
    }

    public function rules(): array
    {
        return [
            'category_id'                    => ['required', Rule::exists('categories', 'id')->where('tenant_id', tenant('id'))],
            'products'                        => 'required|array|min:1|max:100',
            'products.*.asin'                 => 'required|string|max:20',
            'products.*.title'                => 'required|string|min:3|max:500',
            'products.*.price'                => 'nullable|numeric|min:0',
            'products.*.rating'               => 'nullable|numeric|min:0|max:5',
            'products.*.reviews_count'        => 'nullable|integer|min:0',
            'products.*.image_url'            => 'nullable|url|max:1000',
            'products.*.stock_status'         => 'nullable|string|max:50',
            'products.*.condition'            => ['nullable', Rule::in(ListingHealth::CONDITIONS)],
            // Security L2: bound the array so a repeated-element payload can't bloat
            // the listing_flags JSON column / later in_array() scans.
            'products.*.listing_flags'        => 'nullable|array|max:5',
            'products.*.listing_flags.*'      => ['distinct', 'string', Rule::in(ListingHealth::RECOGNIZED_FLAGS)],
        ];
    }
}
