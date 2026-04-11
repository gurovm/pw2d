<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\Seo\KpiCardsWidget;
use App\Filament\Widgets\Seo\PageTypeBreakdownWidget;
use App\Filament\Widgets\Seo\QueryExplorerWidget;
use App\Filament\Widgets\Seo\TopMoversWidget;
use App\Filament\Widgets\Seo\UrlCoverageWidget;
use Filament\Pages\Page;

/**
 * Filament admin page: SEO Dashboard.
 *
 * Aggregates 5 tenant-scoped SEO widgets. All widget queries filter explicitly
 * by tenant_id — never by global tenancy scope — because seo_metrics is not
 * covered by BelongsToTenant.
 *
 * Route: /admin/{tenant}/seo
 */
class SeoDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'SEO Dashboard';

    protected static ?string $navigationGroup = 'SEO';

    protected static string $view = 'filament.pages.seo-dashboard';

    protected static ?string $slug = 'seo';

    protected static ?int $navigationSort = 10;

    /**
     * Widgets rendered in the header area of this page (above any content).
     *
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            KpiCardsWidget::class,
            UrlCoverageWidget::class,
            TopMoversWidget::class,
            QueryExplorerWidget::class,
            PageTypeBreakdownWidget::class,
        ];
    }

    /**
     * Number of columns the header widget grid uses.
     */
    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 2;
    }
}
