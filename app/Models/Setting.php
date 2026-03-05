<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key — cached to avoid per-request DB hits.
     */
    public static function get(string $key, $default = null)
    {
        return Cache::rememberForever("setting:{$key}", function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    /**
     * Set a setting value by key — also busts the cache.
     */
    public static function set(string $key, $value)
    {
        Cache::forget("setting:{$key}");

        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
