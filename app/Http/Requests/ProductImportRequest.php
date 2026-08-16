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

    /**
     * Sec M1 (2026-08-16 audit): normalize `listing_flags` to a list BEFORE
     * validating — see BatchImportRequest's docblock for the full rationale.
     */
    protected function prepareForValidation(): void
    {
        if (is_array($flags = $this->input('listing_flags'))) {
            $this->merge(['listing_flags' => array_values($flags)]);
        }
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
            // Security L2 / Sec M1: bound the array so a repeated-element payload
            // can't bloat the listing_flags JSON column / later in_array() scans,
            // and require a genuine list (belt-and-braces on top of the
            // prepareForValidation() normalization above).
            'listing_flags'   => ['nullable', 'array', 'max:5', function ($attribute, $value, $fail) {
                if (!array_is_list($value)) {
                    $fail('The :attribute must be a list of flag strings.');
                }
            }],
            'listing_flags.*' => ['distinct', 'string', Rule::in(ListingHealth::RECOGNIZED_FLAGS)],
        ];
    }
}
