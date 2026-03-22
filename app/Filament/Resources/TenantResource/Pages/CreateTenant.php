<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * Override to ensure the Tenant is created with the correct string ID
     * before Filament tries to save the domains relationship.
     * Without this, SQLite returns a numeric rowid which breaks the FK.
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $tenant = static::getModel()::create($data);

        // Force-refresh so getKey() returns the string 'id', not SQLite's rowid
        return $tenant->fresh();
    }
}
