<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Multi-Tenancy';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Niche Sites';

    // This resource manages tenants themselves — skip Filament's tenant scoping
    protected static bool $isScopedToTenant = false;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identity')
                    ->schema([
                        Forms\Components\TextInput::make('id')
                            ->label('Tenant ID')
                            ->required()
                            ->maxLength(64)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('URL-safe slug, e.g. "best-mics". Cannot be changed after creation.')
                            ->disabled(fn (?Tenant $record) => $record !== null),

                        Forms\Components\TextInput::make('name')
                            ->label('Display Name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('e.g. "Best Microphones"'),
                    ])->columns(2),

                Forms\Components\Section::make('Domains')
                    ->schema([
                        Forms\Components\Repeater::make('domains')
                            ->relationship('domains')
                            ->schema([
                                Forms\Components\TextInput::make('domain')
                                    ->label('Domain')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('best-mics.com'),
                            ])
                            ->addActionLabel('Add Domain')
                            ->defaultItems(1)
                            ->columns(1),
                    ]),

                Forms\Components\Section::make('Branding')
                    ->description('Custom look & feel for this niche site. Stored in tenant data.')
                    ->schema([
                        Forms\Components\TextInput::make('brand_name')
                            ->label('Brand Name')
                            ->maxLength(255)
                            ->placeholder('coffee2decide')
                            ->helperText('Lowercase style, e.g. "coffee2decide"'),

                        Forms\Components\FileUpload::make('logo')
                            ->label('Logo')
                            ->image()
                            ->directory('tenants/logos')
                            ->disk('public')
                            ->maxSize(2048),

                        Forms\Components\ColorPicker::make('primary_color')
                            ->label('Primary Color')
                            ->helperText('Buttons, active states, UI accents'),

                        Forms\Components\ColorPicker::make('secondary_background_color')
                            ->label('Secondary Background')
                            ->helperText('Soft backgrounds, cards, footers'),

                        Forms\Components\ColorPicker::make('text_color')
                            ->label('Text Color')
                            ->helperText('Main headings and body typography'),
                    ])->columns(2),

                Forms\Components\Section::make('SEO')
                    ->description('Overrides for search-engine and social-media metadata. Leave blank to use brand_name-based defaults.')
                    ->schema([
                        Forms\Components\TextInput::make('seo_title_suffix')
                            ->label('Title suffix')
                            ->helperText('Appended to category page titles, e.g. " | Coffee2Decide". Defaults to brand name.')
                            ->maxLength(60),
                        Forms\Components\TextInput::make('seo_default_title')
                            ->label('Homepage title')
                            ->helperText('Full <title> tag for the homepage.')
                            ->maxLength(70),
                        Forms\Components\Textarea::make('seo_default_description')
                            ->label('Default description')
                            ->helperText('Used on homepage + as fallback. Keep under 160 characters.')
                            ->rows(3)
                            ->maxLength(200),
                        Forms\Components\TextInput::make('seo_default_image')
                            ->label('Default social image URL')
                            ->helperText('Fallback og:image for pages with no product image. 1200×630 recommended.')
                            ->url(),

                        Forms\Components\TextInput::make('gsc_site_url')
                            ->label('GSC Site URL')
                            ->helperText('e.g. `sc-domain:pw2d.com` (domain property) or `https://pw2d.com/` (URL prefix property, trailing slash required)'),

                        Forms\Components\TextInput::make('ga4_property_id')
                            ->label('GA4 Property ID')
                            ->helperText('Format: `properties/123456789`. Find in GA4 Admin → Property Settings.'),

                        Forms\Components\Toggle::make('seo_enabled')
                            ->label('Enable nightly SEO pull')
                            ->helperText('When on, the nightly `pw2d:seo:pull` command includes this tenant.')
                            ->default(false),
                    ])
                    ->columns(1),

                Forms\Components\Section::make('Hero Content')
                    ->description('Custom headline and subheadline for the landing page hero section.')
                    ->schema([
                        Forms\Components\TextInput::make('hero_headline')
                            ->label('Headline')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('Find Your Perfect Espresso Machine')
                            ->helperText('The main H1 on the landing page.'),

                        Forms\Components\Textarea::make('hero_subheadline')
                            ->label('Subheadline')
                            ->required()
                            ->rows(3)
                            ->maxLength(500)
                            ->placeholder('Stop digging through reviews. Tell our AI how you drink your coffee...')
                            ->helperText('The paragraph below the headline.'),
                    ])->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Tenant ID')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('domains.domain')
                    ->label('Domains')
                    ->badge()
                    ->separator(','),

                Tables\Columns\TextColumn::make('brand_name')
                    ->label('Brand')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('hero_headline')
                    ->label('Headline')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\ColorColumn::make('primary_color')
                    ->label('Primary'),

                Tables\Columns\ColorColumn::make('secondary_background_color')
                    ->label('Background'),

                Tables\Columns\ColorColumn::make('text_color')
                    ->label('Text'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
