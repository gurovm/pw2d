<?php

namespace App\Observers;

use App\Models\Feature;

class FeatureObserver
{
    /**
     * Handle the Feature "created" event.
     */
    public function created(Feature $feature): void
    {
        if ($feature->category_id) {
            $this->propagateToDescendants($feature);
        }
    }

    /**
     * Propagate feature to all descendant categories.
     */
    private function propagateToDescendants(Feature $feature): void
    {
        $category = $feature->category;
        
        if (!$category) {
            return;
        }
        
        $descendants = $category->getAllDescendants();
        
        foreach ($descendants as $descendant) {
            // Check if feature already exists in this category
            $exists = Feature::where('category_id', $descendant->id)
                ->where('name', $feature->name)
                ->exists();
            
            if (!$exists) {
                Feature::create([
                    'category_id' => $descendant->id,
                    'name' => $feature->name,
                    'slug' => $feature->slug . '-cat-' . $descendant->id,
                    'data_type' => $feature->data_type,
                    'unit' => $feature->unit,
                    'weight' => $feature->weight,
                ]);
            }
        }
    }
}
