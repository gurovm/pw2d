<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Seo;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shows the top 20 URLs with the biggest absolute GSC position change
 * over the last 7 days compared to the previous 7 days.
 *
 * Lower position = better rank (position 1 is the top result). A negative
 * delta means the URL climbed (improved); a positive delta means it dropped.
 * Results are sorted by ABS(delta) DESC so biggest movers appear first.
 *
 * Implemented as a StatsOverviewWidget listing the top 5 movers as stat cards
 * for simplicity — avoids the complexity of a custom table widget against a
 * raw-query result set. F7 follow-up: convert to a proper table widget.
 */
class TopMoversWidget extends BaseWidget
{
    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $tenantId = filament()->getTenant()?->getTenantKey();

        if ($tenantId === null) {
            return [];
        }

        $today     = now()->toDateString();
        $current7  = now()->subDays(6)->toDateString();
        $prior7End = now()->subDays(7)->toDateString();
        $prior7    = now()->subDays(13)->toDateString();

        // Current 7-day average position per URL.
        $current = DB::table('seo_metrics')
            ->where('tenant_id', $tenantId)
            ->where('source', 'gsc')
            ->whereBetween('metric_date', [$current7, $today])
            ->whereNotNull('gsc_position')
            ->select('url', DB::raw('AVG(gsc_position) as pos_now'))
            ->groupBy('url')
            ->get()
            ->keyBy('url');

        // Prior 7-day average position per URL.
        $prior = DB::table('seo_metrics')
            ->where('tenant_id', $tenantId)
            ->where('source', 'gsc')
            ->whereBetween('metric_date', [$prior7, $prior7End])
            ->whereNotNull('gsc_position')
            ->select('url', DB::raw('AVG(gsc_position) as pos_before'))
            ->groupBy('url')
            ->get()
            ->keyBy('url');

        // Join in PHP — only URLs present in both windows.
        $movers = collect();

        foreach ($current as $url => $row) {
            if (! $prior->has($url)) {
                continue;
            }

            $posNow    = round((float) $row->pos_now, 1);
            $posBefore = round((float) $prior[$url]->pos_before, 1);
            $delta     = round($posNow - $posBefore, 1);

            $movers->push([
                'url'        => $url,
                'pos_before' => $posBefore,
                'pos_now'    => $posNow,
                'delta'      => $delta,
                'abs_delta'  => abs($delta),
            ]);
        }

        $top20 = $movers->sortByDesc('abs_delta')->take(20)->values();

        if ($top20->isEmpty()) {
            return [
                Stat::make('Top Movers', 'No data')
                    ->description('Run the SEO pull command to collect position data.')
                    ->color('gray'),
            ];
        }

        $stats = [];

        foreach ($top20 as $mover) {
            $deltaLabel = $mover['delta'] > 0
                ? "+{$mover['delta']} (dropped)"
                : "{$mover['delta']} (improved)";

            $color = $mover['delta'] <= 0 ? 'success' : 'danger';

            $stats[] = Stat::make(
                substr((string) $mover['url'], -60),  // truncate long URLs
                "Pos: {$mover['pos_now']} (was {$mover['pos_before']})"
            )
                ->description($deltaLabel)
                ->descriptionIcon($mover['delta'] <= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($color);
        }

        return $stats;
    }
}
