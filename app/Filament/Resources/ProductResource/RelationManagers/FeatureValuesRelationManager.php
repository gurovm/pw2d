<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Feature;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FeatureValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'featureValues';

    protected static ?string $title = 'Feature Values';

    protected static ?string $recordTitleAttribute = 'feature.name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('feature_id')
                    ->label('Feature')
                    ->options(function () {
                        // Get all categories this product belongs to
                        $categoryIds = $this->getOwnerRecord()->categories()->pluck('categories.id');
                        
                        // Get all features for those categories
                        return Feature::whereIn('category_id', $categoryIds)
                            ->with('category')
                            ->get()
                            ->mapWithKeys(function ($feature) {
                                return [
                                    $feature->id => $feature->category->name . ' → ' . $feature->name . 
                                        ($feature->unit ? " ({$feature->unit})" : '')
                                ];
                            });
                    })
                    ->required()
                    ->searchable()
                    ->helperText('Only features from the product\'s categories are shown'),
                
                Forms\Components\TextInput::make('raw_value')
                    ->label('Value')
                    ->required()
                    ->numeric()
                    ->step(0.01)
                    ->helperText('Enter the numeric value for this feature'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('feature.name')
            ->columns([
                Tables\Columns\TextColumn::make('feature.category.name')
                    ->label('Category')
                    ->sortable()
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('feature.name')
                    ->label('Feature')
                    ->sortable()
                    ->searchable()
                    ->weight('bold'),
                
                Tables\Columns\TextColumn::make('raw_value')
                    ->label('Value')
                    ->sortable()
                    ->formatStateUsing(function ($state, $record) {
                        $unit = $record->feature->unit ?? '';
                        return $state . ($unit ? " {$unit}" : '');
                    }),
                
                Tables\Columns\IconColumn::make('feature.is_higher_better')
                    ->label('Direction')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-trending-up')
                    ->falseIcon('heroicon-o-arrow-trending-down')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->tooltip(fn ($record) => $record->feature->is_higher_better ? 'Higher is better' : 'Lower is better'),
                
                Tables\Columns\TextColumn::make('feature.min_value')
                    ->label('Min')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('feature.max_value')
                    ->label('Max')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Category')
                    ->relationship('feature.category', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Feature Value')
                    ->icon('heroicon-o-plus'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No feature values yet')
            ->emptyStateDescription('Add feature values to define this product\'s specifications')
            ->emptyStateIcon('heroicon-o-adjustments-horizontal');
    }
}
