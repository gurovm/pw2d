<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant, per-URL, per-day SEO metrics row.
 *
 * Stores data from Google Search Console (source='gsc') and Google Analytics 4
 * (source='ga4'). Each source populates its own column group; the other group
 * remains null.
 *
 * NOTE: This model intentionally does NOT use BelongsToTenant. Pull jobs run
 * from the central scheduler, so tenant_id must be set explicitly on every
 * insert, and every query must include ->where('tenant_id', $tenantId).
 *
 * @property int         $id
 * @property string      $tenant_id
 * @property string      $source          'gsc' or 'ga4'
 * @property string      $url
 * @property string      $url_hash        sha256($url) — used by the unique index
 * @property string      $metric_date     Y-m-d
 * @property int|null    $gsc_impressions
 * @property int|null    $gsc_clicks
 * @property float|null  $gsc_ctr         0.0000 – 1.0000
 * @property float|null  $gsc_position    average SERP position (lower = better)
 * @property string|null $gsc_top_query
 * @property int|null    $ga4_sessions
 * @property int|null    $ga4_users
 * @property int|null    $ga4_engaged_sess
 * @property int|null    $ga4_conversions
 * @property float|null  $ga4_bounce_rate  0.0000 – 1.0000
 */
class SeoMetric extends Model
{
    use HasFactory;

    /**
     * All columns are explicitly controlled — open-fill is intentional here
     * because inserts come from trusted internal pull actions, not user input.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        // date:Y-m-d (not bare 'date') so the cast serializes as '2026-04-11'
        // without the '00:00:00' time suffix. On sqlite (in-memory tests) the
        // DATE column is stored as TEXT and whereBetween does lexicographic
        // string comparison — the suffix would push boundary rows out of range.
        'metric_date'      => 'date:Y-m-d',
        'gsc_ctr'          => 'float',
        'gsc_position'     => 'float',
        'ga4_bounce_rate'  => 'float',
    ];
}
