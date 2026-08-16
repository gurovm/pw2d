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

    /**
     * Sec M1 (2026-08-16 audit): normalize every product's `listing_flags` to a
     * list BEFORE validating — the prior L2 fix bounded array ELEMENT count, but
     * `listing_flags.*` validates VALUES, not KEYS, so an associative payload
     * `{"<huge string>":"high_price"}` still passed every rule and persisted the
     * attacker-chosen key verbatim via the `array` cast round-trip.
     */
    protected function prepareForValidation(): void
    {
        $products = $this->input('products');

        if (!is_array($products)) {
            return;
        }

        foreach ($products as $i => $product) {
            if (is_array($product) && is_array($product['listing_flags'] ?? null)) {
                $products[$i]['listing_flags'] = array_values($product['listing_flags']);
            }
        }

        $this->merge(['products' => $products]);
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
            // Security L2 / Sec M1: bound the array so a repeated-element payload
            // can't bloat the listing_flags JSON column / later in_array() scans,
            // and require a genuine list (belt-and-braces on top of the
            // prepareForValidation() normalization above).
            'products.*.listing_flags'        => ['nullable', 'array', 'max:5', function ($attribute, $value, $fail) {
                if (!array_is_list($value)) {
                    $fail('The :attribute must be a list of flag strings.');
                }
            }],
            'products.*.listing_flags.*'      => ['distinct', 'string', Rule::in(ListingHealth::RECOGNIZED_FLAGS)],
        ];
    }
}
