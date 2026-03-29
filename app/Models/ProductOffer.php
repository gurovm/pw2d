<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class ProductOffer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'store_id',
        'url',
        'scraped_price',
        'raw_title',
        'image_url',
        'stock_status',
    ];

    protected $casts = [
        'scraped_price' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Build the affiliate URL by appending the store's affiliate params to the base URL.
     */
    protected function affiliateUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                $baseUrl = $this->url;
                if (!$baseUrl) {
                    return null;
                }

                $params = $this->store?->affiliate_params;
                if (empty($params)) {
                    return $baseUrl;
                }

                $separator = str_contains($baseUrl, '?') ? '&' : '?';
                return $baseUrl . $separator . $params;
            }
        );
    }
}
