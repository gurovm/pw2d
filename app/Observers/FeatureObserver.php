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
                    'tenant_id'        => $feature->tenant_id,
                    'category_id'      => $descendant->id,
                    'name'             => $feature->name,
                    'unit'             => $feature->unit,
                    'is_higher_better' => $feature->is_higher_better,
                    'min_value'        => $feature->min_value,
                    'max_value'        => $feature->max_value,
                    'sort_order'       => $feature->sort_order,
                ]);
            }
        }
    }
}
