<?php

namespace App\Filament\Resources\FeatureResource\Pages;

use App\Filament\Resources\FeatureResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateFeature extends CreateRecord
{
    protected static string $resource = FeatureResource::class;

    /**
     * After creating a feature, save the category_id to session
     * and reset the form with the same category pre-selected.
     */
    protected function afterCreate(): void
    {
        // Save the category to session
        if ($this->record->category_id) {
            session(['last_feature_category_id' => $this->record->category_id]);
        }
    }

    /**
     * Modify the form data after filling it.
     * This ensures the category field is pre-filled when the form loads.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $lastCategoryId = session('last_feature_category_id');
        
        if ($lastCategoryId && !isset($data['category_id'])) {
            $data['category_id'] = $lastCategoryId;
        }
        
        return $data;
    }
}
