<?php

namespace App\Filament\Resources\SearchLogResource\Pages;

use App\Filament\Resources\SearchLogResource;
use App\Models\SearchLog;
use App\Services\AiService;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\ListRecords;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use League\CommonMark\CommonMarkConverter;

class ListSearchLogs extends ListRecords
{
    protected static string $resource = SearchLogResource::class;

    /**
     * Holds the AI-generated markdown report between the action call and the
     * re-rendered modal. Set by the action handler; read by the form closure.
     */
    public ?string $trendReport = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('analyze_trends')
                ->label('Analyze Trends')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->modalHeading('AI Trends Report')
                ->modalWidth('7xl')
                ->modalSubmitActionLabel('Generate Report')
                ->modalCancelActionLabel('Close')
                ->form(function () {
                    if ($this->trendReport !== null) {
                        // Report is ready — render it as HTML inside the modal.
                        $converter = new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
                        $html = $converter->convert($this->trendReport)->getContent();

                        return [
                            Placeholder::make('report_output')
                                ->hiddenLabel()
                                ->content(new HtmlString(
                                    '<div class="prose max-w-none dark:prose-invert overflow-y-auto"'
                                    . ' style="max-height:65vh;padding-right:1rem;">'
                                    . $html
                                    . '</div>'
                                )),
                        ];
                    }

                    // Initial state — show a description before the user hits Generate.
                    $logCount = SearchLog::count();

                    return [
                        Placeholder::make('description')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<p class="text-sm text-gray-600 dark:text-gray-400">'
                                . "Click <strong>Generate Report</strong> to analyze the latest "
                                . number_format($logCount)
                                . ' search log entries. The AI call runs server-side and takes up to 60 seconds.</p>'
                            )),
                    ];
                })
                ->action(function (Actions\Action $action) {
                    $logs = SearchLog::latest()->take(200)->get();

                    if ($logs->isEmpty()) {
                        Notification::make()
                            ->title('No search logs found.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $aiService = app(AiService::class);
                        $this->trendReport = $aiService->analyzeSearchTrends($logs);
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('AI analysis failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    // Keep the modal open so the form re-renders with the report.
                    $action->halt();
                }),
        ];
    }
}
