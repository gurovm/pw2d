<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Product $product) {
            if ($product->image_path && Storage::disk('public')->exists($product->image_path)) {
                Storage::disk('public')->delete($product->image_path);
            }
        });
    }

    protected $fillable = [
        'external_id',
        'category_id',
        'brand_id',
        'name',
        'slug',
        'ai_summary',
        'image_path',
        'external_image_path',
        'affiliate_url',
        'price_tier',
        'amazon_rating',
        'amazon_reviews_count',
        'is_ignored',
    ];

    protected $casts = [
        'price_tier' => 'integer',
        'amazon_rating' => 'float',
        'amazon_reviews_count' => 'integer',
        'is_ignored' => 'boolean',
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
     * Get the dynamically generated Amazon Affiliate URL.
     */
    protected function affiliateUrl(): Attribute
    {
        return Attribute::make(
            get: function (string|null $value) {
                if (!$value) {
                    return null;
                }

                $tag = config('services.amazon.affiliate_tag');

                if (empty($tag)) {
                    return $value;
                }

                $separator = str_contains($value, '?') ? '&' : '?';
                return $value . $separator . 'tag=' . $tag;
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
                $source = Setting::get('image_source', 'local');

                if ($source === 'external' && !empty($this->external_image_path)) {
                    return $this->external_image_path;
                }

                if (!empty($this->image_path)) {
                    return \Illuminate\Support\Facades\Storage::url($this->image_path);
                }

                // Fallback if neither exists
                return null;
            }
        );
    }
}
