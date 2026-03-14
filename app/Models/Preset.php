<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Preset extends Model
{
    protected $fillable = ['category_id', 'name', 'sort_order'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function features()
    {
        return $this->belongsToMany(Feature::class)->withPivot('weight')->withTimestamps();
    }

    public function presetFeatures()
    {
        return $this->hasMany(FeaturePreset::class);
    }
}
