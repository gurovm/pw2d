<?php

namespace App\Filament\Pages;

use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProblemProducts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationLabel = 'Problem Products';
    protected static ?string $navigationGroup = 'Product Management';
    protected static ?int $navigationSort = 4;
    protected static string $view = 'filament.pages.problem-products';

    /** Suspect keywords that indicate accessories/bundles/parts */
    private const SUSPECT_KEYWORDS = [
        'bundle', 'kit', 'set of', 'pack of', 'replacement',
        'filter', 'stand', 'mount', 'cable', 'adapter',
        'mat', 'cleaning', 'descaler', 'charcoal', 'tablets',
        'knock box', 'tamper', 'portafilter basket',
    ];

    /**
     * Build a MySQL 8 REGEXP pattern with word boundary simulation.
     * Uses (^|[^a-z]) and ([^a-z]|$) since MySQL 8 ICU regex doesn't support \b or [[:<:]]
     */
    private static function keywordRegex(): string
    {
        return implode('|', array_map(
            fn (string $kw) => '(^|[^a-z])' . $kw . '([^a-z]|$)',
            self::SUSPECT_KEYWORDS
        ));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::problemQuery()->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Base query: non-ignored, fully processed products that have at least one problem.
     */
    private static function problemQuery(): Builder
    {
        $regex = static::keywordRegex();

        return Product::query()
            ->where('is_ignored', false)
            ->whereNull('status')
            ->where(function (Builder $q) use ($regex) {
                $q->whereNull('scraped_price')
                  ->orWhereRaw('scraped_price < (SELECT COALESCE(budget_max, 50) * 0.5 FROM categories WHERE categories.id = products.category_id)')
                  ->orWhereNull('image_path')
                  ->orWhereNull('ai_summary')
                  ->orWhereRaw('LOWER(name) REGEXP ?', [$regex]);
            });
    }

    /**
     * Determine the problem label(s) for a given product row.
     */
    private static function detectProblems(Product $record): string
    {
        $problems = [];

        if ($record->scraped_price === null) {
            $problems[] = 'No price';
        } elseif ($record->category) {
            $threshold = ($record->category->budget_max ?? 50) * 0.5;
            if ($record->scraped_price < $threshold) {
                $problems[] = 'Low price ($' . number_format($record->scraped_price, 2) . ')';
            }
        }

        if (empty($record->image_path)) {
            $problems[] = 'No image';
        }

        if (empty($record->ai_summary)) {
            $problems[] = 'No AI summary';
        }

        $nameLower = strtolower($record->name ?? '');
        foreach (self::SUSPECT_KEYWORDS as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $nameLower)) {
                $problems[] = 'Suspect: "' . $kw . '"';
                break; // one keyword is enough
            }
        }

        return implode(', ', $problems) ?: 'Unknown';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(static::problemQuery())
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select(['id', 'tenant_id', 'name', 'external_id', 'brand_id', 'category_id', 'image_path', 'scraped_price', 'ai_summary', 'amazon_rating', 'amazon_reviews_count', 'is_ignored', 'created_at'])
                ->with(['category:id,name,tenant_id,budget_max', 'brand:id,name,tenant_id'])
            )
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Image')
                    ->circular()
                    ->disk('public')
                    ->defaultImageUrl(fn () => null),

                TextColumn::make('name')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->limit(55)
                    ->url(fn (Product $record) => $record->external_id
                        ? "https://www.amazon.com/dp/{$record->external_id}"
                        : null)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->weight('bold'),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->sortable(),

                TextColumn::make('scraped_price')
                    ->label('Price')
                    ->money('USD')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('amazon_rating')
                    ->label('Rating')
                    ->numeric(decimalPlaces: 1)
                    ->sortable()
                    ->icon('heroicon-m-star')
                    ->iconColor('warning'),

                TextColumn::make('amazon_reviews_count')
                    ->label('Reviews')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('problem')
                    ->label('Problem')
                    ->badge()
                    ->color(fn (string $state) => match (true) {
                        str_contains($state, 'No price') => 'danger',
                        str_contains($state, 'Low price') => 'warning',
                        str_contains($state, 'Suspect') => 'warning',
                        default => 'gray',
                    })
                    ->getStateUsing(fn (Product $record) => static::detectProblems($record)),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('problem_type')
                    ->label('Problem Type')
                    ->options([
                        'no_price'    => 'No price',
                        'low_price'   => 'Low price',
                        'no_image'    => 'No image',
                        'no_summary'  => 'No AI summary',
                        'suspect'     => 'Suspect title',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['value'])) return;
                        $regex = static::keywordRegex();
                        match ($data['value']) {
                            'no_price'   => $query->whereNull('scraped_price'),
                            'low_price'  => $query->whereNotNull('scraped_price')
                                ->whereRaw('scraped_price < (SELECT COALESCE(budget_max, 50) * 0.5 FROM categories WHERE categories.id = products.category_id)'),
                            'no_image'   => $query->whereNull('image_path'),
                            'no_summary' => $query->whereNull('ai_summary'),
                            'suspect'    => $query->whereRaw('LOWER(name) REGEXP ?', [$regex]),
                            default      => null,
                        };
                    }),

                SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Action::make('ignore')
                    ->label('Ignore')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Mark as ignored?')
                    ->action(function (Product $record) {
                        $record->update(['is_ignored' => true]);
                        Notification::make()
                            ->title('Product ignored: ' . $record->name)
                            ->success()
                            ->send();
                    }),

                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->url(fn (Product $record) => route('filament.admin.resources.products.edit', [
                        'tenant' => tenant('id'),
                        'record' => $record,
                    ])),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('markIgnored')
                        ->label('Mark as Ignored')
                        ->icon('heroicon-o-eye-slash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Mark selected products as ignored?')
                        ->action(function (Collection $records) {
                            $count = $records->count();
                            Product::whereIn('id', $records->pluck('id'))->update(['is_ignored' => true]);
                            Notification::make()
                                ->title("{$count} products marked as ignored")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
