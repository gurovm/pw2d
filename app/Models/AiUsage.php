<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single Gemini API call, recorded for cost/token observability.
 *
 * Written by AiUsageService — never construct/save these directly outside that
 * service, so that the "never let accounting break an AI call" guarantee (T1)
 * stays in one place.
 *
 * Intentionally does NOT use BelongsToTenant. This table is a cross-tenant
 * accounting log, not tenant-owned data:
 *   - The dominant writer (evaluateProduct(), via ProcessPendingProduct) runs on
 *     a queued worker where tenancy is never initialized (no QueueTenancyBootstrapper
 *     is configured — see config/tenancy.php) even though the Product being scored
 *     belongs to a tenant. Relying on BelongsToTenant's auto-populate-on-create
 *     hook would silently record every Bouncer call as tenant_id=null, which is
 *     wrong for a table whose entire purpose is per-tenant cost attribution.
 *   - A future cross-tenant cost dashboard (summing spend across all tenants from
 *     the central console) would be broken by the automatic global scope the
 *     moment it runs inside any tenant context.
 * tenant_id is instead resolved explicitly in AiUsageService::record() from
 * tenant('id') (null when no tenant is active), matching the explicit-assignment
 * pattern already used for API controllers and queued jobs elsewhere in this
 * codebase (see project_context.md §11). This mirrors SeoMetric, the other
 * cross-tenant observability model.
 *
 * @property int         $id
 * @property string|null $tenant_id
 * @property string      $purpose
 * @property string      $model
 * @property int|null    $input_tokens
 * @property int|null    $output_tokens
 * @property int|null    $thinking_tokens
 * @property string|null $estimated_cost_usd
 * @property \Illuminate\Support\Carbon $created_at
 */
class AiUsage extends Model
{
    use HasFactory;

    /** Only created_at is tracked — this is an append-only log, rows are never updated. */
    const UPDATED_AT = null;

    protected $table = 'ai_usage';

    protected $fillable = [
        'tenant_id',
        'purpose',
        'model',
        'input_tokens',
        'output_tokens',
        'thinking_tokens',
        'estimated_cost_usd',
    ];

    protected $casts = [
        'input_tokens'       => 'integer',
        'output_tokens'      => 'integer',
        'thinking_tokens'    => 'integer',
        'estimated_cost_usd' => 'decimal:6',
        'created_at'         => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
