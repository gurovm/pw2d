<?php

declare(strict_types=1);

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
            'category_id'   => 'required|exists:categories,id',
            'external_id'   => 'required|string|max:20',
            'title'         => 'required|string|min:3|max:500',
            'price'         => 'nullable|numeric|min:0',
            'rating'        => 'nullable|numeric|min:0|max:5',
            'reviews_count' => 'nullable|integer|min:0',
            'image_url'     => 'nullable|url|max:1000',
        ];
    }
}
