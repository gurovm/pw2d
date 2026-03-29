<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class AiMatchingDecision extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'scraped_raw_name',
        'existing_product_id',
        'is_match',
    ];

    protected $casts = [
        'is_match' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'existing_product_id');
    }
}
