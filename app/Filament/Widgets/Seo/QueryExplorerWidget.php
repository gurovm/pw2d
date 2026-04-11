<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Seo;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Line chart of GSC impressions + clicks per day for the last 28 days,
 * optionally filtered to URLs matching a given prefix.
 *
 * Filament ChartWidget $filters support is used to provide the URL prefix
 * selector. The user enters a URL prefix (e.g. "/compare/espresso-machines")
 * and the chart narrows to only rows whose URL starts with that prefix.
 */
class QueryExplorerWidget extends ChartWidget
{
    protected static bool $isLazy = true;

    // SEO data only changes once per day at 03:00 when pw2d:seo:pull runs.
    protected static ?string $pollingInterval = null;

    protected static ?string $heading = 'Query Explorer (last 28 days)';

    protected int | string | array $columnSpan = 'full';

    /**
     * Filament ChartWidget built-in filter: a select dropdown of pre-defined
     * filter options. For an open text filter the widget falls back to a
     * public Livewire property — use Alpine on the blade side if needed.
     *
     * F7: replace with a proper Filament Form + URL prefix text input.
     * For now, ship a filter dropdown with a few common page types.
     *
     * @var string|null
     */
    public ?string $filter = null;

    protected function getFilters(): ?array
    {
        return [
            ''         => 'All URLs',
            '/'        => 'Homepage only',
            '/compare' => '/compare/* (categories + presets)',
            '/product' => '/product/* (product pages)',
            '/about'   => 'Static pages',
        ];
    }

    protected function getData(): array
    {
        $tenantId = filament()->getTenant()?->getTenantKey();

        if ($tenantId === null) {
            return ['labels' => [], 'datasets' => []];
        }

        $from = now()->subDays(27)->toDateString();
        $to   = now()->toDateString();

        $query = DB::table('seo_metrics')
            ->where('tenant_id', $tenantId)
            ->where('source', 'gsc')
            ->whereBetween('metric_date', [$from, $to])
            ->selectRaw('metric_date, SUM(gsc_impressions) as impressions, SUM(gsc_clicks) as clicks')
            ->groupBy('metric_date')
            ->orderBy('metric_date');

        if (filled($this->filter)) {
            $query->where('url', 'like', $this->filter . '%');
        }

        $rows = $query->get()->keyBy('metric_date');

        // Build a complete 28-day date series (zeros for days without data).
        $labels      = [];
        $impressions = [];
        $clicks      = [];

        for ($i = 27; $i >= 0; $i--) {
            $date    = now()->subDays($i)->toDateString();
            $row     = $rows->get($date);
            $labels[]      = now()->subDays($i)->format('M j');
            $impressions[] = $row ? (int) $row->impressions : 0;
            $clicks[]      = $row ? (int) $row->clicks : 0;
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                [
                    'label'           => 'Impressions',
                    'data'            => $impressions,
                    'borderColor'     => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'tension'         => 0.4,
                    'fill'            => true,
                ],
                [
                    'label'           => 'Clicks',
                    'data'            => $clicks,
                    'borderColor'     => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'tension'         => 0.4,
                    'fill'            => true,
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
