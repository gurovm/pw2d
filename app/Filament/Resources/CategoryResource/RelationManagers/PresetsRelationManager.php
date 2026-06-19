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
                Forms\Components\Textarea::make('seo_description')
                    ->label('SEO Description (override)')
                    ->rows(2)
                    ->helperText('Hand-written meta description for the ?preset= URL. Leave blank to auto-generate from AI intro.')
                    ->columnSpanFull(),

                Forms\Components\Section::make('SEO Content (AI-generated, hand-tunable)')
                    ->schema([
                        Forms\Components\Textarea::make('seo_content.intro')
                            ->label('Intro (1-2 <p> elements, 180-280 words)')
                            ->rows(6)
                            ->helperText('AI-generated HTML intro. Generate via: php artisan pw2d:generate-preset-content {tenant}')
                            ->columnSpanFull(),

                        Forms\Components\Repeater::make('seo_content.faqs')
                            ->label('FAQs (use-case phrased Q&A)')
                            ->helperText('3-4 entries. Preset FAQs render first in the FAQPage schema.')
                            ->schema([
                                Forms\Components\TextInput::make('question')
                                    ->label('Question')
                                    ->required()
                                    ->columnSpanFull(),
                                Forms\Components\Textarea::make('answer')
                                    ->label('Answer')
                                    ->required()
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            ->collapsible()
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

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
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
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
