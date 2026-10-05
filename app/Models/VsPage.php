<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A head-to-head "A vs B" page (Spec 043). `product_a` is the alphabetically first
 * product name. `sections` = {a_wins, b_wins, who_should_buy} (HTML strings),
 * `faqs` = [{q, a}].
 */
class VsPage extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'category_id',
        'product_a_id',
        'product_b_id',
        'slug',
        'title',
        'intro',
        'verdict',
        'sections',
        'faqs',
        'price_snapshot_a',
        'price_snapshot_b',
        'status',
        'generated_at',
        'freshness_checked_at',
        'stale_reasons',
    ];

    protected $casts = [
        'sections'             => 'array',
        'faqs'                 => 'array',
        'price_snapshot_a'     => 'integer',
        'price_snapshot_b'     => 'integer',
        'generated_at'         => 'datetime',
        'freshness_checked_at' => 'datetime',
        // null = never audited; [] = fresh; non-empty = stale (same contract as LandingPage).
        'stale_reasons'        => 'array',
    ];

    protected static function booted(): void
    {
        $forgetCaches = function (VsPage $page) {
            Cache::forget($page->cacheKey());
            Cache::forget('t' . ($page->tenant_id ?? 'central') . ':sitemap:xml');

            // The category's /best/ page lists published VS pages; bust its cache too.
            $landing = LandingPage::where('tenant_id', $page->tenant_id)->where('category_id', $page->category_id)->first();
            if ($landing !== null) {
                Cache::forget($landing->cacheKey());
            }
        };

        static::saved($forgetCaches);
        static::deleted($forgetCaches);
    }

    public function productA(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_a_id');
    }

    public function productB(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_b_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Controller view-model cache key, derived from the row's own tenant (like LandingPage). */
    public function cacheKey(): string
    {
        return 't' . ($this->tenant_id ?? 'central') . ":vs:{$this->slug}";
    }
}
