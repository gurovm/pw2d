<?php

namespace App\Filament\Resources\CategoryResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PresetsRelationManager extends RelationManager
{
    protected static string $relationship = 'presets';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Repeater::make('featurePresets')
                    ->relationship('presetFeatures')
                    ->schema([
                        Forms\Components\Select::make('feature_id')
                            ->label('Feature')
                            ->options(function (RelationManager $livewire) {
                                return \App\Models\Feature::where('category_id', $livewire->getOwnerRecord()->id)
                                    ->pluck('name', 'id');
                            })
                            ->required()
                            ->searchable()
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        Forms\Components\TextInput::make('weight')
                            ->numeric()
                            ->required()
                            ->default(50)
                            ->minValue(0)
                            ->maxValue(100),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('features_count')
                    ->counts('features')
                    ->label('Features Count'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
