<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class FeaturePreset extends Pivot
{
    protected $table = 'feature_preset';

    public $incrementing = true;

    protected $fillable = ['preset_id', 'feature_id', 'weight'];

    public function feature()
    {
        return $this->belongsTo(Feature::class);
    }

    public function preset()
    {
        return $this->belongsTo(Preset::class);
    }
}
