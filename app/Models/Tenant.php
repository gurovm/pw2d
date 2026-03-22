<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

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
}
