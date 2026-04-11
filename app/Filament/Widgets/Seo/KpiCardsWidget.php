<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Seo;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Top-line SEO KPI cards: last 28 days vs previous 28 days.
 *
 * All queries are scoped to the current Filament tenant via explicit
 * WHERE tenant_id = ? — seo_metrics does not use BelongsToTenant.
 */
class KpiCardsWidget extends BaseWidget
{
    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $tenantId = filament()->getTenant()?->getTenantKey();

        if ($tenantId === null) {
            return [];
        }

        $now       = now()->toDateString();
        $current28 = now()->subDays(27)->toDateString(); // last 28 days inclusive
        $prior28   = now()->subDays(55)->toDateString(); // prior 28-day window start
        $prior28End = now()->subDays(28)->toDateString();

        // --- GSC aggregates ---
        $currentGsc = DB::table('seo_metrics')
            ->where('tenant_id', $tenantId)
            ->where('source', 'gsc')
            ->whereBetween('metric_date', [$current28, $now])
            ->selectRaw('
                SUM(gsc_clicks) as clicks,
                SUM(gsc_impressions) as impressions,
                AVG(gsc_position) as avg_position
            ')
            ->first();

        $priorGsc = DB::table('seo_metrics')
            ->where('tenant_id', $tenantId)
            ->where('source', 'gsc')
            ->whereBetween('metric_date', [$prior28, $prior28End])
            ->selectRaw('
                SUM(gsc_clicks) as clicks,
                SUM(gsc_impressions) as impressions,
                AVG(gsc_position) as avg_position
            ')
            ->first();

        // --- GA4 aggregates ---
        $currentGa4 = DB::table('seo_metrics')
            ->where('tenant_id', $tenantId)
            ->where('source', 'ga4')
            ->whereBetween('metric_date', [$current28, $now])
            ->selectRaw('
                SUM(ga4_sessions) as sessions,
                SUM(ga4_conversions) as conversions
            ')
            ->first();

        $priorGa4 = DB::table('seo_metrics')
            ->where('tenant_id', $tenantId)
            ->where('source', 'ga4')
            ->whereBetween('metric_date', [$prior28, $prior28End])
            ->selectRaw('
                SUM(ga4_sessions) as sessions,
                SUM(ga4_conversions) as conversions
            ')
            ->first();

        // --- Compute deltas ---
        $currentClicks      = (int) ($currentGsc->clicks ?? 0);
        $priorClicks        = (int) ($priorGsc->clicks ?? 0);
        $clicksDelta        = $priorClicks > 0 ? round(($currentClicks - $priorClicks) / $priorClicks * 100, 1) : null;

        $currentImpressions = (int) ($currentGsc->impressions ?? 0);
        $priorImpressions   = (int) ($priorGsc->impressions ?? 0);
        $impressionsDelta   = $priorImpressions > 0 ? round(($currentImpressions - $priorImpressions) / $priorImpressions * 100, 1) : null;

        $currentPos         = $currentGsc->avg_position ? round((float) $currentGsc->avg_position, 1) : null;
        $priorPos           = $priorGsc->avg_position ? round((float) $priorGsc->avg_position, 1) : null;
        // Position: lower is better, so a decrease is an improvement.
        $posDelta = ($currentPos !== null && $priorPos !== null) ? round($currentPos - $priorPos, 1) : null;

        $currentSessions   = (int) ($currentGa4->sessions ?? 0);
        $priorSessions     = (int) ($priorGa4->sessions ?? 0);
        $sessionsDelta     = $priorSessions > 0 ? round(($currentSessions - $priorSessions) / $priorSessions * 100, 1) : null;

        $currentConversions = (int) ($currentGa4->conversions ?? 0);
        $priorConversions   = (int) ($priorGa4->conversions ?? 0);
        $conversionsDelta   = $priorConversions > 0 ? round(($currentConversions - $priorConversions) / $priorConversions * 100, 1) : null;

        return [
            Stat::make('GSC Clicks (28d)', number_format($currentClicks))
                ->description($clicksDelta !== null
                    ? ($clicksDelta >= 0 ? "+{$clicksDelta}% vs prior 28d" : "{$clicksDelta}% vs prior 28d")
                    : 'No comparison data')
                ->descriptionIcon($clicksDelta !== null && $clicksDelta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($clicksDelta !== null && $clicksDelta >= 0 ? 'success' : 'danger'),

            Stat::make('GSC Impressions (28d)', number_format($currentImpressions))
                ->description($impressionsDelta !== null
                    ? ($impressionsDelta >= 0 ? "+{$impressionsDelta}% vs prior 28d" : "{$impressionsDelta}% vs prior 28d")
                    : 'No comparison data')
                ->descriptionIcon($impressionsDelta !== null && $impressionsDelta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($impressionsDelta !== null && $impressionsDelta >= 0 ? 'success' : 'danger'),

            Stat::make('GSC Avg Position (28d)', $currentPos ?? '—')
                ->description($posDelta !== null
                    ? ($posDelta <= 0 ? "Improved by " . abs($posDelta) . " vs prior 28d" : "Dropped by {$posDelta} vs prior 28d")
                    : 'No comparison data')
                ->descriptionIcon($posDelta !== null && $posDelta <= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($posDelta !== null && $posDelta <= 0 ? 'success' : 'warning'),

            Stat::make('GA4 Sessions (28d)', number_format($currentSessions))
                ->description($sessionsDelta !== null
                    ? ($sessionsDelta >= 0 ? "+{$sessionsDelta}% vs prior 28d" : "{$sessionsDelta}% vs prior 28d")
                    : 'No comparison data')
                ->descriptionIcon($sessionsDelta !== null && $sessionsDelta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($sessionsDelta !== null && $sessionsDelta >= 0 ? 'success' : 'danger'),

            Stat::make('GA4 Conversions (28d)', number_format($currentConversions))
                ->description($conversionsDelta !== null
                    ? ($conversionsDelta >= 0 ? "+{$conversionsDelta}% vs prior 28d" : "{$conversionsDelta}% vs prior 28d")
                    : 'No comparison data')
                ->descriptionIcon($conversionsDelta !== null && $conversionsDelta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($conversionsDelta !== null && $conversionsDelta >= 0 ? 'success' : 'danger'),
        ];
    }
}
