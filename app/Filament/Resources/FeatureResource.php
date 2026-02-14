<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FeatureResource\Pages;
use App\Filament\Resources\FeatureResource\RelationManagers;
use App\Models\Feature;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FeatureResource extends Resource
{
    protected static ?string $model = Feature::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Product Management';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Feature Definition')
                    ->schema([
                        Forms\Components\Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Features are specific to a category'),
                        
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('e.g., "Battery Life", "Weight", "Screen Size"'),
                        
                        Forms\Components\TextInput::make('unit')
                            ->maxLength(50)
                            ->helperText('e.g., "hours", "grams", "inches" (optional)'),
                    ])
                    ->columns(2),
                
                Forms\Components\Section::make('Normalization Settings')
                    ->description('These settings control how values are normalized for scoring')
                    ->schema([
                        Forms\Components\Toggle::make('is_higher_better')
                            ->label('Higher is Better')
                            ->default(true)
                            ->helperText('ON = higher values score better (e.g., battery life). OFF = lower values score better (e.g., weight)')
                            ->inline(false),
                        
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('min_value')
                                    ->label('Minimum Value')
                                    ->numeric()
                                    ->helperText('Lowest expected value for normalization'),
                                
                                Forms\Components\TextInput::make('max_value')
                                    ->label('Maximum Value')
                                    ->numeric()
                                    ->helperText('Highest expected value for normalization'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable()
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('unit')
                    ->searchable()
                    ->placeholder('—'),
                
                Tables\Columns\IconColumn::make('is_higher_better')
                    ->label('Higher is Better')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-trending-up')
                    ->falseIcon('heroicon-o-arrow-trending-down')
                    ->trueColor('success')
                    ->falseColor('warning'),
                
                Tables\Columns\TextColumn::make('min_value')
                    ->label('Min')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—'),
                
                Tables\Columns\TextColumn::make('max_value')
                    ->label('Max')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—'),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeatures::route('/'),
            'create' => Pages\CreateFeature::route('/create'),
            'edit' => Pages\EditFeature::route('/{record}/edit'),
        ];
    }
}
