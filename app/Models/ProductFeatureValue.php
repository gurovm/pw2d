<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFeatureValue extends Model
{
    use HasFactory;
    protected $fillable = [
        'product_id',
        'feature_id',
        'raw_value',
        'explanation',
    ];

    protected $casts = [
        'raw_value' => 'float',
    ];

    /**
     * Get the product that owns the feature value.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the feature that owns this value.
     */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
