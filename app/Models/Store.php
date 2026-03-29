<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Store extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'affiliate_params',
        'commission_rate',
        'priority',
        'logo_url',
        'is_active',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'priority'        => 'integer',
        'is_active'       => 'boolean',
    ];

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class);
    }
}
