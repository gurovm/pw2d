<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feature extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'unit',
        'is_higher_better',
        'min_value',
        'max_value',
    ];

    protected $casts = [
        'is_higher_better' => 'boolean',
        'min_value' => 'float',
        'max_value' => 'float',
    ];

    /**
     * Get the category that owns the feature.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get all product feature values for this feature.
     */
    public function productValues(): HasMany
    {
        return $this->hasMany(ProductFeatureValue::class);
    }

    /**
     * Get all products with their values for this feature.
     */
    public function productsWithValues(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_feature_values')
            ->withPivot('raw_value')
            ->withTimestamps();
    }
}
