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
                Forms\Components\Textarea::make('seo_description')
                    ->label('SEO Description (override)')
                    ->rows(2)
                    ->helperText('Hand-written meta description for the ?preset= URL. Leave blank to auto-generate from AI intro.')
                    ->columnSpanFull(),

                Forms\Components\Section::make('SEO Content (AI-generated, hand-tunable)')
                    ->description('Use-case-specific intro and FAQs rendered on the compare page when this preset is active. Generate via: php artisan pw2d:generate-preset-content {tenant} --preset=' . '...  --dry-run')
                    ->schema([
                        Forms\Components\Textarea::make('seo_content.intro')
                            ->label('Intro (1-2 <p> elements, 180-280 words)')
                            ->rows(6)
                            ->helperText('AI-generated HTML. Use {!! !!} in Blade. Renders above the product grid when this preset is active.')
                            ->columnSpanFull(),

                        Forms\Components\Repeater::make('seo_content.faqs')
                            ->label('FAQs (use-case phrased Q&A)')
                            ->helperText('3-4 entries. Preset FAQs render first, then category FAQs. Emitted in FAQPage JSON-LD schema.')
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
