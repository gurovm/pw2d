<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatchImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Token middleware handles auth
    }

    public function rules(): array
    {
        return [
            'category_id'                => 'required|exists:categories,id',
            'products'                   => 'required|array|min:1|max:100',
            'products.*.asin'            => 'required|string|max:20',
            'products.*.title'           => 'required|string|min:3|max:500',
            'products.*.price'           => 'nullable|numeric|min:0',
            'products.*.rating'          => 'nullable|numeric|min:0|max:5',
            'products.*.reviews_count'   => 'nullable|integer|min:0',
            'products.*.image_url'       => 'nullable|url|max:1000',
        ];
    }
}
