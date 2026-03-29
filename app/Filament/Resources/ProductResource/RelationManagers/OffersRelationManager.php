<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    protected static ?string $title = 'Store Offers';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('store_id')
                ->label('Store')
                ->options(Store::pluck('name', 'id'))
                ->required()
                ->searchable(),
            Forms\Components\TextInput::make('url')
                ->label('Product URL')
                ->required()
                ->url()
                ->columnSpanFull(),
            Forms\Components\TextInput::make('scraped_price')
                ->label('Price')
                ->numeric()
                ->prefix('$'),
            Forms\Components\TextInput::make('stock_status')
                ->maxLength(50),
            Forms\Components\TextInput::make('raw_title')
                ->label('Raw Scraped Title')
                ->maxLength(500)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('image_url')
                ->label('Image URL')
                ->url()
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('store.name')
                    ->label('Store')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('scraped_price')
                    ->label('Price')
                    ->money('USD')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('stock_status')
                    ->label('Stock')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'in_stock' => 'success',
                        'out_of_stock' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('Unknown'),
                Tables\Columns\ImageColumn::make('image_url')
                    ->label('Image')
                    ->circular()
                    ->defaultImageUrl(fn () => null),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->limit(40)
                    ->url(fn ($record) => $record->url)
                    ->openUrlInNewTab()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Synced')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('scraped_price', 'asc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['tenant_id'] = tenant('id');
                        return $data;
                    }),
            ]);
    }
}
