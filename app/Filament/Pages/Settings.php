<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Settings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Settings';
    protected static ?string $navigationGroup = 'Administration';
    protected static ?int $navigationSort = 100;
    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'image_source'             => Setting::get('image_source', 'local'),
            'ga_measurement_id'        => Setting::get('ga_measurement_id'),
            'google_site_verification' => Setting::get('google_site_verification'),
            'posthog_key'              => Setting::get('posthog_key'),
            'posthog_host'             => Setting::get('posthog_host', 'https://eu.posthog.com'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Product Image Settings')
                    ->description('Control where product images are loaded from.')
                    ->icon('heroicon-o-photo')
                    ->schema([
                        Select::make('image_source')
                            ->label('Image Source')
                            ->helperText('Choose whether product images load from the locally downloaded files or the original external URL (e.g. Amazon CDN).')
                            ->options([
                                'local' => 'Local (Downloaded)',
                                'external' => 'External (Original URL)',
                            ])
                            ->default('local')
                            ->required(),
                    ]),

                Section::make('Integrations')
                    ->description('Analytics and search console connections. Leave blank to disable.')
                    ->icon('heroicon-o-chart-bar')
                    ->columns(2)
                    ->schema([
                        TextInput::make('ga_measurement_id')
                            ->label('Google Analytics ID')
                            ->placeholder('G-XXXXXXXXXX')
                            ->helperText('Your GA4 Measurement ID.')
                            ->maxLength(20),

                        TextInput::make('google_site_verification')
                            ->label('Google Search Console')
                            ->placeholder('verification-code')
                            ->helperText('The content value from the meta verification tag.')
                            ->maxLength(100),

                        TextInput::make('posthog_key')
                            ->label('PostHog API Key')
                            ->placeholder('phc_xxxxxxxxxxxx')
                            ->helperText('Your PostHog project API key.')
                            ->maxLength(100),

                        TextInput::make('posthog_host')
                            ->label('PostHog Host')
                            ->placeholder('https://eu.posthog.com')
                            ->helperText('PostHog instance URL (defaults to EU if blank).')
                            ->maxLength(100),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::set('image_source', $data['image_source']);
        Setting::set('ga_measurement_id', $data['ga_measurement_id']);
        Setting::set('google_site_verification', $data['google_site_verification']);
        Setting::set('posthog_key', $data['posthog_key']);
        Setting::set('posthog_host', $data['posthog_host']);

        Notification::make()
            ->title('Settings saved successfully!')
            ->success()
            ->send();
    }
}
