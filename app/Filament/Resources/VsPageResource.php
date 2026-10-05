<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\VsPageResource\Pages;
use App\Models\VsPage;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-mostly admin for head-to-head pages (Spec 043). No create/edit form: pages
 * come in through `pw2d:vs-pages:save` (guards: selection, price, style). The only
 * write here is the publish/draft toggle. Tenant-scoped via BelongsToTenant.
 */
class VsPageResource extends Resource
{
    protected static ?string $model = VsPage::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';
    protected static ?string $navigationLabel = 'VS Pages';
    protected static ?string $navigationGroup = 'Product Management';
    protected static ?int $navigationSort = 8;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['productA:id,name', 'productB:id,name', 'category:id,name']))
            ->columns([
                Tables\Columns\TextColumn::make('pair')
                    ->label('Pair')
                    ->getStateUsing(fn (VsPage $record) => ($record->productA?->name ?? '?') . ' vs ' . ($record->productB?->name ?? '?'))
                    ->description(fn (VsPage $record) => '/vs/' . $record->slug),
                Tables\Columns\TextColumn::make('category.name')->label('Category')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'published' ? 'success' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('stale_reasons')
                    ->label('Stale reasons')
                    ->badge()
                    ->color('danger')
                    ->getStateUsing(fn (VsPage $record) => $record->stale_reasons ?: null)
                    ->placeholder('Fresh'),
                Tables\Columns\TextColumn::make('generated_at')->label('Generated')->dateTime()->sortable()->placeholder('Never'),
            ])
            ->defaultSort('generated_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
            ])
            ->actions([
                Tables\Actions\Action::make('toggleStatus')
                    ->label(fn (VsPage $record) => $record->status === 'published' ? 'Unpublish' : 'Publish')
                    ->icon('heroicon-o-arrows-right-left')
                    ->requiresConfirmation()
                    ->action(fn (VsPage $record) => $record->update([
                        'status' => $record->status === 'published' ? 'draft' : 'published',
                    ])),
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Page')->columns(2)->schema([
                Infolists\Components\TextEntry::make('title')->columnSpanFull(),
                Infolists\Components\TextEntry::make('slug'),
                Infolists\Components\TextEntry::make('status')->badge(),
                Infolists\Components\TextEntry::make('price_snapshot_a')->label('Snapshot A ($)'),
                Infolists\Components\TextEntry::make('price_snapshot_b')->label('Snapshot B ($)'),
                Infolists\Components\TextEntry::make('stale_reasons')->badge()->placeholder('Fresh'),
                Infolists\Components\TextEntry::make('generated_at')->dateTime(),
            ]),
            Infolists\Components\Section::make('Prose')->schema([
                Infolists\Components\TextEntry::make('intro')->html(),
                Infolists\Components\TextEntry::make('sections.a_wins')->label('Where A wins')->html(),
                Infolists\Components\TextEntry::make('sections.b_wins')->label('Where B wins')->html(),
                Infolists\Components\TextEntry::make('sections.who_should_buy')->label('Who should buy which')->html(),
                Infolists\Components\TextEntry::make('verdict')->html(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVsPages::route('/'),
            'view'  => Pages\ViewVsPage::route('/{record}'),
        ];
    }
}
