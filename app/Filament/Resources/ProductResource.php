<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Jobs\RescanProductFeatures;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Product Management';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Product Information')
                    ->schema([
                        Forms\Components\Select::make('brand_id')
                            ->label('Brand')
                            ->relationship('brand', 'name')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                            ]),
                        
                        Forms\Components\TextInput::make('name')
                            ->label('Product Name')
                            ->required()
                            ->maxLength(255),
                            
                        Forms\Components\Textarea::make('ai_summary')
                            ->label('AI Summary')
                            ->columnSpanFull()
                            ->placeholder('Will be generated later...'),
                        
                        Forms\Components\Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Select the category this product belongs to'),
                    ])
                    ->columns(2),
                
                Forms\Components\Section::make('Media & Links')
                    ->schema([
                        Forms\Components\FileUpload::make('image_path')
                            ->label('Product Image')
                            ->image()
                            ->disk('public')
                            ->directory('products/images')
                            ->visibility('public')
                            ->imageEditor()
                            ->maxSize(5120)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/jpg'])
                            ->helperText('Upload product image (max 5MB)'),
                        
                        Forms\Components\TextInput::make('affiliate_url')
                            ->label('Affiliate URL')
                            ->url()
                            ->maxLength(1000)
                            ->helperText('Link to product page or affiliate link'),
                    ])
                    ->columns(1),
                
                Forms\Components\Section::make('Visibility')
                    ->schema([
                        Forms\Components\Toggle::make('is_ignored')
                            ->label('Ignored (Accessory / Not a main device)')
                            ->helperText('When enabled, this product is hidden from the site, search, and sitemap. The ASIN is still kept to prevent re-scanning.')
                            ->default(false)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Amazon Rating (Virtual Feature)')
                    ->description('This will appear as the "Wisdom of the Crowds" slider on the frontend')
                    ->schema([
                        Forms\Components\TextInput::make('amazon_rating')
                            ->label('Amazon Rating')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->step(0.1)
                            ->suffix('/ 5.0')
                            ->helperText('Rating from 0.0 to 5.0'),
                        
                        Forms\Components\TextInput::make('amazon_reviews_count')
                            ->label('Number of Reviews')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Total number of Amazon reviews'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('Image')
                    ->circular()
                    ->disk('public')
                    ->defaultImageUrl(fn () => null),
                
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                
                Tables\Columns\TextColumn::make('brand.name')
                    ->label('Brand')
                    ->sortable()
                    ->searchable(),
                
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    ->sortable()
                    ->searchable(),
                
                Tables\Columns\IconColumn::make('is_ignored')
                    ->label('Ignored')
                    ->boolean()
                    ->trueIcon('heroicon-o-eye-slash')
                    ->falseIcon('heroicon-o-eye')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amazon_rating')
                    ->label('Rating')
                    ->numeric(decimalPlaces: 1)
                    ->sortable()
                    ->icon('heroicon-m-star')
                    ->iconColor('warning'),
                
                Tables\Columns\TextColumn::make('amazon_reviews_count')
                    ->label('Reviews')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state)),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('brand')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                
                Tables\Filters\SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('is_ignored')
                    ->label('Ignored')
                    ->placeholder('All products')
                    ->trueLabel('Ignored only')
                    ->falseLabel('Visible only'),
            ])
            ->actions([
                Tables\Actions\Action::make('aiRescan')
                    ->label('AI Rescan')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Rescan this product?')
                    ->modalDescription('Re-scores all feature grades against the current category features and recalculates the price tier. Name, brand, summary, and image are NOT changed.')
                    ->action(function (Product $record) {
                        RescanProductFeatures::dispatch($record->id, $record->category_id);
                        Notification::make()
                            ->title('Rescan queued for "' . $record->name . '"')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('aiRescanBulk')
                        ->label('AI Rescan Selected')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Rescan selected products?')
                        ->modalDescription('Re-scores feature grades and recalculates price tiers for all selected products. Name, brand, summary, and images are NOT changed.')
                        ->action(function (Collection $records) {
                            foreach ($records as $record) {
                                RescanProductFeatures::dispatch($record->id, $record->category_id);
                            }
                            Notification::make()
                                ->title($records->count() . ' products queued for AI rescan')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('markIgnored')
                        ->label('Mark as Ignored')
                        ->icon('heroicon-o-eye-slash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Mark selected products as ignored?')
                        ->modalDescription('These products will be hidden from the site, search, and sitemap. Their ASINs are kept to prevent re-scanning.')
                        ->action(function (Collection $records) {
                            $count = $records->count();
                            foreach ($records as $record) {
                                $record->update(['is_ignored' => true, 'status' => null]);
                            }
                            Notification::make()
                                ->title("{$count} products marked as ignored")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\FeatureValuesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
