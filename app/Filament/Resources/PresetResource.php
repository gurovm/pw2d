<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PresetResource\Pages;
use App\Filament\Resources\PresetResource\RelationManagers;
use App\Models\Preset;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PresetResource extends Resource
{
    protected static ?string $model = Preset::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('category_id')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Repeater::make('featurePresets')
                    ->relationship('presetFeatures')
                    ->schema([
                        Forms\Components\Select::make('feature_id')
                            ->label('Feature')
                            ->options(function (Forms\Get $get) {
                                $categoryId = $get('../../category_id');
                                if ($categoryId) {
                                    return \App\Models\Feature::where('category_id', $categoryId)->pluck('name', 'id');
                                }
                                return \App\Models\Feature::pluck('name', 'id');
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('features_count')
                    ->counts('features')
                    ->label('Features'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListPresets::route('/'),
            'create' => Pages\CreatePreset::route('/create'),
            'edit' => Pages\EditPreset::route('/{record}/edit'),
        ];
    }
}
