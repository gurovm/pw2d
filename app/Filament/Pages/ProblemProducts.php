<?php

namespace App\Filament\Pages;

use App\Models\Product;
use App\Models\ProductOffer;
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
use Illuminate\Support\Facades\Cache;

class ProblemProducts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationLabel = 'Problem Products';
    protected static ?string $navigationGroup = 'Product Management';
    protected static ?int $navigationSort = 4;
    protected static string $view = 'filament.pages.problem-products';

    private const SUSPECT_KEYWORDS = [
        'bundle', 'kit', 'set of', 'pack of', 'replacement',
        'filter', 'stand', 'mount', 'cable', 'adapter',
        'mat', 'cleaning', 'descaler', 'charcoal', 'tablets',
        'knock box', 'tamper', 'portafilter basket',
    ];

    private static function keywordRegex(): string
    {
        return implode('|', array_map(
            fn (string $kw) => '(^|[^a-z])' . $kw . '([^a-z]|$)',
            self::SUSPECT_KEYWORDS
        ));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember('problem-products-badge:' . tenant('id'), 120, function () {
            return static::problemQuery()->count();
        });
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    private static function problemQuery(): Builder
    {
        $regex = static::keywordRegex();

        return Product::query()
            ->where('is_ignored', false)
            ->whereNull('status')
            ->where(function (Builder $q) use ($regex) {
                // No price: product has no offers with a price
                $q->whereDoesntHave('offers', fn ($oq) => $oq->whereNotNull('scraped_price'))
                  // Low price: best offer price < 50% of category budget_max
                  ->orWhereHas('offers', fn ($oq) => $oq
                      ->whereNotNull('scraped_price')
                      ->whereRaw('scraped_price < (SELECT COALESCE(budget_max, 50) * 0.5 FROM categories WHERE categories.id = products.category_id)'))
                  ->orWhere(fn (Builder $imgQ) => $imgQ
                      ->whereNull('image_path')
                      ->whereDoesntHave('offers', fn ($oq) => $oq->whereNotNull('image_url')))
                  ->orWhereNull('ai_summary')
                  ->orWhereRaw('LOWER(name) REGEXP ?', [$regex]);
            });
    }

    private static function detectProblems(Product $record): string
    {
        $problems = [];

        $bestPrice = $record->offers->min('scraped_price');

        if ($bestPrice === null) {
            $problems[] = 'No price';
        } elseif ($record->category) {
            $threshold = ($record->category->budget_max ?? 50) * 0.5;
            if ($bestPrice < $threshold) {
                $problems[] = 'Low price ($' . number_format($bestPrice, 2) . ')';
            }
        }

        if (empty($record->image_path) && !$record->offers->contains(fn ($o) => !empty($o->image_url))) {
            $problems[] = 'No image';
        }

        if (empty($record->ai_summary)) {
            $problems[] = 'No AI summary';
        }

        $nameLower = strtolower($record->name ?? '');
        foreach (self::SUSPECT_KEYWORDS as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/', $nameLower)) {
                $problems[] = 'Suspect: "' . $kw . '"';
                break;
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
                ->select(['id', 'tenant_id', 'name', 'brand_id', 'category_id', 'image_path', 'ai_summary', 'amazon_rating', 'amazon_reviews_count', 'is_ignored', 'created_at'])
                ->with(['category:id,name,tenant_id,budget_max', 'brand:id,name,tenant_id', 'offers:id,product_id,store_id,url,scraped_price', 'offers.store:id,name'])
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
                    ->url(fn (Product $record) => $record->offers->first()?->url)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->weight('bold'),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->sortable(),

                TextColumn::make('best_offer_price')
                    ->label('Price')
                    ->money('USD')
                    ->sortable()
                    ->placeholder('—')
                    ->getStateUsing(fn (Product $record) => $record->offers->min('scraped_price')),

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
                            'no_price'   => $query->whereDoesntHave('offers', fn ($oq) => $oq->whereNotNull('scraped_price')),
                            'low_price'  => $query->whereHas('offers', fn ($oq) => $oq
                                ->whereNotNull('scraped_price')
                                ->whereRaw('scraped_price < (SELECT COALESCE(budget_max, 50) * 0.5 FROM categories WHERE categories.id = products.category_id)')),
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
                        Notification::make()->title('Product ignored: ' . $record->name)->success()->send();
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
                            Notification::make()->title("{$count} products marked as ignored")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
