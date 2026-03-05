<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SearchLogResource\Pages;
use App\Filament\Resources\SearchLogResource\RelationManagers;
use App\Models\SearchLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SearchLogResource extends Resource
{
    protected static ?string $model = SearchLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';
    protected static ?string $navigationGroup = 'Analytics';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record = null): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'global_search' => 'gray',
                        'homepage_ai' => 'info',
                        'category_ai' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('query')
                    ->searchable(),
                TextColumn::make('category_name')
                    ->searchable(),
                TextColumn::make('results_count')
                    ->sortable(),
                TextColumn::make('response_summary')
                    ->limit(50),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'global_search' => 'Global Search',
                        'homepage_ai' => 'Homepage AI',
                        'category_ai' => 'Category AI',
                    ]),
                TernaryFilter::make('zero_results')
                    ->label('Zero Results')
                    ->queries(
                        true: fn (Builder $query) => $query->where('results_count', 0),
                        false: fn (Builder $query) => $query->where('results_count', '>', 0),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSearchLogs::route('/'),
            'view' => Pages\ViewSearchLog::route('/{record}'),
        ];
    }
}
