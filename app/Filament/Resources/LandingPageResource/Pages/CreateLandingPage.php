<?php

declare(strict_types=1);

namespace App\Filament\Resources\LandingPageResource\Pages;

use App\Filament\Resources\LandingPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLandingPage extends CreateRecord
{
    protected static string $resource = LandingPageResource::class;
}
