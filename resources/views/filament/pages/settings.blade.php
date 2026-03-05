<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="[
                \Filament\Actions\Action::make('save')
                    ->label('Save Settings')
                    ->submit('save')
                    ->icon('heroicon-o-check')
            ]"
        />
    </x-filament-panels::form>
</x-filament-panels::page>
