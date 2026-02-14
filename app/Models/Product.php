<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'brand_id',
        'name',
        'image_path',
        'affiliate_url',
        'amazon_rating',
        'amazon_reviews_count',
    ];

    protected $casts = [
        'amazon_rating' => 'float',
        'amazon_reviews_count' => 'integer',
    ];

    /**
     * Get the brand that owns the product.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get all categories this product belongs to.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product')
            ->withTimestamps();
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
}
