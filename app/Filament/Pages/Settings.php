<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
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
            'image_source' => Setting::get('image_source', 'local'),
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
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::set('image_source', $data['image_source']);

        Notification::make()
            ->title('Settings saved successfully!')
            ->success()
            ->send();
    }
}
