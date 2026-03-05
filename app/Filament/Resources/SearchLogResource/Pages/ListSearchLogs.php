<?php

namespace App\Filament\Resources\SearchLogResource\Pages;

use App\Filament\Resources\SearchLogResource;
use App\Models\SearchLog;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Http;
use Filament\Notifications\Notification;

class ListSearchLogs extends ListRecords
{
    protected static string $resource = SearchLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('analyze_trends')
                ->label('Analyze Trends')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->modalHeading('AI Trends Report')
                ->modalWidth('7xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->form(function () {
                    $logs = SearchLog::latest()->take(200)->get();
                    $formattedLogs = $logs->map(function ($log) {
                        return sprintf("- [%s] query: '%s', results: %s, category: %s, summary: %s",
                            $log->type, $log->query, $log->results_count ?? 'N/A', $log->category_name ?? 'N/A',
                            mb_substr($log->response_summary ?? 'N/A', 0, 100));
                    })->implode("\n");
                    
                    $aiPromptString = "You are an expert E-commerce Product Manager. \nAnalyze the following user search logs from our affiliate website. \nProvide a concise, actionable report in Markdown format with the following sections:\n\n1. 📈 Trending Intents (What are users trying to achieve?)\n2. ⚠️ Missing Inventory / Zero Results (What products or categories do we need to scrape/add?)\n3. 🤖 AI Concierge Insights (Are users engaging well with the AI? Are there friction points?)\n4. 🎯 Actionable Recommendations (What exact 2-3 actions should I take tomorrow?)\n\n**CRITICAL SYSTEM RULES FOR ANALYSIS:**\n- For 'homepage_ai', the 'results' count represents the number of matching CATEGORIES found for redirection (usually 1).\n- For 'category_ai' and 'global_search', the 'results' count represents the number of actual PRODUCTS found.\n- DO NOT compare homepage_ai counts to category_ai counts directly, as they measure different things.\n- If 'results' is N/A or NULL, it simply means the count was not logged. It DOES NOT mean there were zero results. Only flag 'Zero Results' if the results count is explicitly 0.\n\nHere is the raw search log data:\n\n" . $formattedLogs;                    
                    $apiKey = config('services.gemini.api_key');
                    if (empty($apiKey)) {
                        return [Placeholder::make('error')->hiddenLabel()->content('Gemini API key missing in config.')];
                    }

                    $promptJson = json_encode($aiPromptString, JSON_HEX_APOS | JSON_HEX_QUOT);
                    $apiKeyJson = json_encode($apiKey, JSON_HEX_APOS | JSON_HEX_QUOT);

                    return [
                        Placeholder::make('ai_client_interface')
                            ->hiddenLabel()
                            ->content(view('filament.pages.ai-report-modal', [
                                'aiPromptString' => $aiPromptString,
                                'apiKey' => $apiKey
                            ]))
                    ];
                }),
        ];
    }
}
