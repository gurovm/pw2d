<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Seo;

use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stats overview that shows per-page-type GSC performance for the last 28 days.
 *
 * Page-type patterns (applied in order — first match wins):
 *   Home     : path is "/" (root)
 *   Preset   : url contains "?preset="
 *   Category : url contains "/compare/" (and no preset)
 *   Product  : url contains "/product/"
 *   Other    : everything else
 *
 * Implemented as a StatsOverviewWidget rather than a table widget so it stays
 * within Filament's standard widget set without requiring Livewire pagination.
 * Each "stat" is one bucket. The description carries impression/click/position.
 */
class PageTypeBreakdownWidget extends BaseWidget
{
    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $tenantId = filament()->getTenant()?->getTenantKey();

        if ($tenantId === null) {
            return [];
        }

        $from = now()->subDays(27)->toDateString();
        $to   = now()->toDateString();

        $rows = DB::table('seo_metrics')
            ->where('tenant_id', $tenantId)
            ->where('source', 'gsc')
            ->whereBetween('metric_date', [$from, $to])
            ->select(['url', 'gsc_impressions', 'gsc_clicks', 'gsc_position'])
            ->get();

        $buckets = $this->aggregateBuckets($rows);
        $stats   = [];

        foreach ($buckets as $bucket) {
            if ($bucket['url_count'] === 0) {
                continue; // skip empty buckets
            }

            $avgPos = $bucket['avg_position'] !== null
                ? 'Avg pos: ' . $bucket['avg_position']
                : 'No position data';

            $stats[] = Stat::make(
                $bucket['bucket'],
                number_format((int) $bucket['url_count']) . ' URLs'
            )
                ->description(
                    number_format((int) $bucket['total_impressions']) . ' impressions · '
                    . number_format((int) $bucket['total_clicks']) . ' clicks · '
                    . $avgPos
                )
                ->descriptionIcon('heroicon-m-document-text')
                ->color('info');
        }

        return $stats;
    }

    /**
     * Classify each URL row into a bucket and aggregate per-bucket stats.
     *
     * @param  \Illuminate\Support\Collection<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    private function aggregateBuckets(\Illuminate\Support\Collection $rows): array
    {
        $classify = static function (string $url): string {
            if (str_contains($url, '?preset=')) {
                return 'Preset';
            }

            $path = parse_url($url, PHP_URL_PATH) ?? '';

            if (preg_match('#^/?$#', $path)) {
                return 'Home';
            }

            if (str_contains($path, '/compare/')) {
                return 'Category';
            }

            if (str_contains($path, '/product/')) {
                return 'Product';
            }

            return 'Other';
        };

        $grouped = $rows->groupBy(fn ($row) => $classify($row->url));

        $bucketOrder = ['Home', 'Category', 'Preset', 'Product', 'Other'];
        $result      = [];

        foreach ($bucketOrder as $bucket) {
            $group = $grouped->get($bucket, collect());

            if ($group->isEmpty()) {
                continue;
            }

            $positions  = $group->pluck('gsc_position')->filter()->values();
            $avgPosition = $positions->isNotEmpty() ? round($positions->avg(), 1) : null;

            $result[] = [
                'bucket'            => $bucket,
                'url_count'         => $group->pluck('url')->unique()->count(),
                'total_impressions' => (int) $group->sum('gsc_impressions'),
                'total_clicks'      => (int) $group->sum('gsc_clicks'),
                'avg_position'      => $avgPosition,
            ];
        }

        return $result;
    }
}
