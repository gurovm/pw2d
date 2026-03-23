<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Setting extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'key', 'value'];

    /**
     * Get a setting value by key — cached to avoid per-request DB hits.
     */
    public static function get(string $key, $default = null)
    {
        return Cache::rememberForever(tenant_cache_key("setting:{$key}"), function () use ($key, $default) {
            $setting = static::where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    /**
     * Set a setting value by key — also busts the cache.
     */
    public static function set(string $key, $value)
    {
        Cache::forget(tenant_cache_key("setting:{$key}"));

        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
