<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class ProductStatsWidget extends BaseWidget
{
    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $total      = Product::count();
        $live       = Product::where('is_ignored', false)->whereNull('status')->count();
        $pendingAi  = Product::where('status', 'pending_ai')->count();
        $failed     = Product::where('status', 'failed')->count();
        $ignored    = Product::where('is_ignored', true)->count();
        $noQueue    = DB::table('jobs')->count();

        return [
            Stat::make('Live Products', $live)
                ->description('Visible on site (AI processed)')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Pending AI', $pendingAi)
                ->description(
                    $pendingAi > 0
                        ? ($noQueue > 0 ? "⚙ {$noQueue} job(s) queued — worker processing" : '⚠ Dispatched, waiting for worker')
                        : 'Queue is clear'
                )
                ->descriptionIcon($pendingAi > 0 ? 'heroicon-m-clock' : 'heroicon-m-check')
                ->color($pendingAi > 0 ? 'warning' : 'success'),

            Stat::make('Failed', $failed)
                ->description($failed > 0 ? 'Check logs — AI could not process these' : 'No failures')
                ->descriptionIcon($failed > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check')
                ->color($failed > 0 ? 'danger' : 'success'),

            Stat::make('Ignored / Generic', $ignored)
                ->description('Filtered out by AI (accessories, white-label)')
                ->descriptionIcon('heroicon-m-no-symbol')
                ->color('gray'),

            Stat::make('Total in DB', $total)
                ->description("Live + Pending + Failed + Ignored")
                ->descriptionIcon('heroicon-m-circle-stack')
                ->color('info'),
        ];
    }
}
