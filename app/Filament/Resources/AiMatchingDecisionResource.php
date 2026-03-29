<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiMatchingDecisionResource\Pages;
use App\Models\AiMatchingDecision;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AiMatchingDecisionResource extends Resource
{
    protected static ?string $model = AiMatchingDecision::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationLabel = 'AI Matching QA';
    protected static ?string $navigationGroup = 'Analytics';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'AI Decision';
    protected static ?string $pluralModelLabel = 'AI Matching Decisions';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('scraped_raw_name')
                    ->label('Scraped Title')
                    ->searchable()
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->scraped_raw_name)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Matched Product')
                    ->placeholder('— (new product)')
                    ->limit(50)
                    ->url(fn ($record) => $record->existing_product_id
                        ? route('filament.admin.resources.products.edit', [
                            'tenant' => tenant('id'),
                            'record' => $record->existing_product_id,
                        ])
                        : null)
                    ->color('primary'),

                Tables\Columns\IconColumn::make('is_match')
                    ->label('Match?')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Decided At')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_match')
                    ->label('Decision')
                    ->placeholder('All')
                    ->trueLabel('Matches only')
                    ->falseLabel('New products only'),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Clear')
                    ->tooltip('Remove this cached decision so AI re-evaluates next time'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Clear Selected'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiMatchingDecisions::route('/'),
        ];
    }
}
