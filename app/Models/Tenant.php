<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Database\Concerns\HasDomains;

class Tenant extends BaseTenant
{
    use HasDomains;

    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Real DB columns. Everything else (brand_name, primary_color, etc.)
     * is stored automatically in the `data` JSON column via VirtualColumn.
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'name'];
    }

    // ── Relationships for Filament native tenancy scoping ────────

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }

    public function presets(): HasMany
    {
        return $this->hasMany(Preset::class);
    }

    public function searchLogs(): HasMany
    {
        return $this->hasMany(SearchLog::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * Validate and sanitize a CSS color value to prevent CSS injection.
     *
     * Only allows hex (#fff, #ffffff, #ffffffff), rgb(), and hsl() formats.
     * Returns a safe default color if the value is invalid or missing.
     */
    public static function sanitizeColor(?string $value, string $default = '#6366f1'): string
    {
        if ($value && preg_match('/^(#[0-9a-fA-F]{3,8}|rgb\(\d{1,3},\s?\d{1,3},\s?\d{1,3}\)|hsl\(\d{1,3},\s?\d{1,3}%,\s?\d{1,3}%\))$/', $value)) {
            return $value;
        }

        return $default;
    }
}
