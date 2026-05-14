<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the `seo_metrics` table.
 *
 * Represents a single per-URL, per-date, per-source (GSC or GA4) SEO metric row.
 * Each source ('gsc' or 'ga4') populates its own column group; the other group
 * remains null.
 *
 * In production, writes go exclusively through DB::table('seo_metrics')->upsert()
 * inside PullGscMetrics / PullGa4Metrics so that idempotent upserts use the
 * composite unique key (tenant_id, source, url_hash, metric_date) efficiently.
 * This model exists primarily to support factory-backed tests and ad-hoc reads.
 *
 * Intentionally does NOT use BelongsToTenant: the status command and dashboard
 * widgets query across all tenants from the central (console) context; automatic
 * tenant scoping would break those cross-tenant reads.
 *
 * @property int         $id
 * @property string      $tenant_id
 * @property string      $source           'gsc' or 'ga4'
 * @property string      $url
 * @property string      $url_hash         sha256($url) — used by the unique index
 * @property string      $metric_date      Y-m-d
 * @property int|null    $gsc_impressions
 * @property int|null    $gsc_clicks
 * @property string|null $gsc_ctr          decimal:4 (0.0000 – 1.0000)
 * @property string|null $gsc_position     decimal:2 (average SERP position)
 * @property string|null $gsc_top_query
 * @property int|null    $ga4_sessions
 * @property int|null    $ga4_users
 * @property int|null    $ga4_engaged_sess
 * @property int|null    $ga4_conversions
 * @property string|null $ga4_bounce_rate  decimal:4 (0.0000 – 1.0000)
 */
class SeoMetric extends Model
{
    use HasFactory;

    protected $table = 'seo_metrics';

    /** @var array<int, string> */
    protected $fillable = [
        'tenant_id',
        'source',
        'url',
        'url_hash',
        'metric_date',
        'gsc_impressions',
        'gsc_clicks',
        'gsc_ctr',
        'gsc_position',
        'gsc_top_query',
        'ga4_sessions',
        'ga4_users',
        'ga4_engaged_sess',
        'ga4_conversions',
        'ga4_bounce_rate',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'metric_date'     => 'date:Y-m-d',
        'gsc_ctr'         => 'decimal:4',
        'gsc_position'    => 'decimal:2',
        'ga4_bounce_rate' => 'decimal:4',
    ];
}
