<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StoreResource\Pages;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StoreResource extends Resource
{
    protected static ?string $model = Store::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Stores / Vendors';
    protected static ?string $navigationGroup = 'Product Management';
    protected static ?int $navigationSort = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Store Identity')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(100)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('slug', \Illuminate\Support\Str::slug($state))),
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('logo_url')
                        ->label('Logo URL')
                        ->url()
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Affiliate & Yield')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('affiliate_params')
                        ->label('Affiliate Parameters')
                        ->placeholder('tag=my-store-20')
                        ->helperText('Appended to product URLs as query params (e.g., tag=my-store-20 or sscid=123)')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('commission_rate')
                        ->label('Commission Rate (%)')
                        ->numeric()
                        ->step(0.01)
                        ->default(0)
                        ->suffix('%')
                        ->helperText('Used for tiebreaking when prices are equal'),
                    Forms\Components\TextInput::make('priority')
                        ->numeric()
                        ->default(0)
                        ->helperText('Higher number wins tiebreakers (after commission rate)'),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true)
                        ->helperText('Inactive stores are hidden from price comparisons'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('slug')
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('affiliate_params')
                    ->label('Affiliate')
                    ->limit(30)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('Commission')
                    ->suffix('%')
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('offers_count')
                    ->label('Offers')
                    ->counts('offers')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListStores::route('/'),
            'create' => Pages\CreateStore::route('/create'),
            'edit'   => Pages\EditStore::route('/{record}/edit'),
        ];
    }
}
