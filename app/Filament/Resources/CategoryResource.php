<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Resources\CategoryResource\RelationManagers;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationGroup = 'Product Management';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Category Information')
                    ->schema([
                        Forms\Components\Select::make('parent_id')
                            ->label('Parent Category')
                            ->relationship('parent', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Leave empty for top-level category'),
                        
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) => 
                                $operation === 'create' ? $set('slug', \Illuminate\Support\Str::slug($state)) : null
                            ),
                        
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Auto-generated from name, but you can customize it'),
                        
                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                            
                        Forms\Components\TagsInput::make('sample_prompts')
                            ->label('Sample Search Prompts')
                            ->placeholder('Type a prompt and press Enter...')
                            ->helperText('Add ~4 short example queries users might type (e.g. "mic for noisy room", "budget streaming mic"). Keep each under 6 words.')
                            ->columnSpanFull(),

                        Forms\Components\Section::make('Buying Guide')
                            ->schema([
                                Forms\Components\RichEditor::make('buying_guide.how_to_decide')
                                    ->label('How to Decide')
                                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'link', 'redo', 'undo']),
                                Forms\Components\RichEditor::make('buying_guide.the_pitfalls')
                                    ->label('The Pitfalls')
                                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'link', 'redo', 'undo']),
                                Forms\Components\RichEditor::make('buying_guide.key_jargon')
                                    ->label('Key Jargon')
                                    ->toolbarButtons(['bold', 'italic', 'bulletList', 'link', 'redo', 'undo']),
                            ])
                            ->collapsible()
                            ->columnSpanFull(),
                        
                        Forms\Components\FileUpload::make('image')
                            ->label('Category Hero Image')
                            ->image()
                            ->disk('public')
                            ->directory('categories/images')
                            ->visibility('public')
                            ->imageEditor()
                            ->imageResizeMode('cover')
                            ->imageCropAspectRatio('16:9')
                            ->imageResizeTargetWidth('800')
                            ->imageResizeTargetHeight('450')
                            ->maxSize(10240)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/jpg'])
                            ->helperText('Upload a high-quality image (will be auto-resized to 800x450px). Recommended: 1920x1080px or higher.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Parent Category')
                    ->sortable()
                    ->searchable()
                    ->url(fn ($record) => $record->parent ? CategoryResource::getUrl('index', ['tableFilters[parent][value]' => $record->parent_id]) : null)
                    ->color(fn ($record) => $record->parent ? 'primary' : null),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Products')
                    ->sortable()
                    ->url(fn ($record) => ProductResource::getUrl('index', [
                        'tableFilters' => [
                            'categories' => [
                                'values' => [$record->id],
                            ],
                        ],
                    ]))
                    ->color('primary'),
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
                Tables\Filters\SelectFilter::make('parent')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Parent Category'),
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
            RelationManagers\FeaturesRelationManager::class,
            RelationManagers\PresetsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
