<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\Feature;

class CategoryObserver
{
    /**
     * Handle the Category "created" event.
     */
    public function created(Category $category): void
    {
        if ($category->parent_id) {
            $this->copyParentFeatures($category);
        }
    }

    /**
     * Copy all features from parent category to new child category.
     */
    private function copyParentFeatures(Category $category): void
    {
        $parent = $category->parent;
        
        if (!$parent) {
            return;
        }
        
        $parentFeatures = $parent->features;
        
        foreach ($parentFeatures as $parentFeature) {
            // Guard against duplicates in case the observer fires more than once
            $exists = Feature::where('category_id', $category->id)
                ->where('name', $parentFeature->name)
                ->exists();

            if (!$exists) {
                Feature::create([
                    'tenant_id'        => $parentFeature->tenant_id,
                    'category_id'      => $category->id,
                    'name'             => $parentFeature->name,
                    'unit'             => $parentFeature->unit,
                    'is_higher_better' => $parentFeature->is_higher_better,
                    'min_value'        => $parentFeature->min_value,
                    'max_value'        => $parentFeature->max_value,
                    'sort_order'       => $parentFeature->sort_order,
                ]);
            }
        }
    }
}
