<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Product extends Model
{
    use HasFactory, BelongsToTenant;

    protected static function booted(): void
    {
        static::deleting(function (Product $product) {
            if ($product->image_path && Storage::disk('public')->exists($product->image_path)) {
                Storage::disk('public')->delete($product->image_path);
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'category_id',
        'brand_id',
        'name',
        'slug',
        'ai_summary',
        'image_path',
        'affiliate_url',
        'price_tier',
        'amazon_rating',
        'amazon_reviews_count',
        'is_ignored',
        'status',
    ];

    protected $casts = [
        'price_tier'    => 'integer',
        'amazon_rating' => 'float',
        'amazon_reviews_count' => 'integer',
        'is_ignored'    => 'boolean',
    ];

    /**
     * Get the brand that owns the product.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the category that owns the product.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class);
    }

    public function categoryRejections(): HasMany
    {
        return $this->hasMany(AiCategoryRejection::class);
    }

    /**
     * Get all feature values for this product.
     */
    public function featureValues(): HasMany
    {
        return $this->hasMany(ProductFeatureValue::class);
    }

    /**
     * Get feature values with their related features eager loaded.
     */
    public function featuresWithValues(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'product_feature_values')
            ->withPivot('raw_value')
            ->withTimestamps();
    }

    /**
     * Lowest scraped price across all active offers.
     */
    protected function bestPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->offers->min('scraped_price')
        );
    }

    /**
     * The best offer: lowest price, with commission_rate/priority tiebreaker.
     * Requires offers to be eager-loaded with their store relationship.
     */
    protected function bestOffer(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->offers
                    ->sortBy([
                        ['scraped_price', 'asc'],
                        [fn ($a, $b) => ($b->store?->commission_rate ?? 0) <=> ($a->store?->commission_rate ?? 0)],
                        [fn ($a, $b) => ($b->store?->priority ?? 0) <=> ($a->store?->priority ?? 0)],
                    ])
                    ->first();
            }
        );
    }

    /**
     * Get the affiliate URL from the best offer (delegates to ProductOffer::affiliateUrl).
     */
    protected function affiliateUrl(): Attribute
    {
        return Attribute::make(
            get: fn (string|null $value) => $value ?: $this->best_offer?->affiliate_url
        );
    }

    /**
     * Return an obfuscated price string suitable for public display.
     * Reads from the best offer price instead of legacy scraped_price.
     */
    protected function estimatedPrice(): Attribute
    {
        return Attribute::make(
            get: function () {
                $price = $this->best_price;

                if ($price === null) {
                    return null;
                }

                $price = (float) $price;

                return $price < 100
                    ? (int) round($price / 5) * 5
                    : (int) round($price / 10) * 10;
            }
        );
    }

    /**
     * Get the resolved image URL based on the global setting.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Priority 1: local stored image
                if (!empty($this->image_path)) {
                    return Storage::url($this->image_path);
                }

                // Priority 2: external image from best offer
                $offer = $this->best_offer;
                if ($offer?->image_url) {
                    return $offer->image_url;
                }

                // Priority 3: any offer with an image
                $offerWithImage = $this->offers->first(fn ($o) => !empty($o->image_url));
                if ($offerWithImage) {
                    return $offerWithImage->image_url;
                }

                return null;
            }
        );
    }
}
