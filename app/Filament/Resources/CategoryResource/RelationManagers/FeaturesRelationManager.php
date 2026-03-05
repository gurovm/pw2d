<?php

namespace App\Filament\Resources\CategoryResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FeaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'features';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('e.g., "Battery Life", "Weight", "Screen Size"'),
                
                Forms\Components\TextInput::make('unit')
                    ->maxLength(50)
                    ->helperText('e.g., "hours", "grams", "inches" (optional)'),
                
                Forms\Components\Toggle::make('is_higher_better')
                    ->label('Higher is Better')
                    ->default(true)
                    ->helperText('ON = higher values score better (e.g., battery life). OFF = lower values score better (e.g., weight)')
                    ->inline(false),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
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
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        // Category is automatically set by the relation manager
                        return $data;
                    }),
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
