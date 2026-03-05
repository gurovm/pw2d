<?php

namespace App\Filament\Resources\SearchLogResource\Pages;

use App\Filament\Resources\SearchLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSearchLog extends EditRecord
{
    protected static string $resource = SearchLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
