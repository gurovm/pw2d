<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Preset extends Model
{
    use HasFactory, BelongsToTenant;
    protected $fillable = ['tenant_id', 'category_id', 'name', 'sort_order', 'seo_description'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class)->withPivot('weight')->withTimestamps();
    }

    public function presetFeatures(): HasMany
    {
        return $this->hasMany(FeaturePreset::class);
    }
}
