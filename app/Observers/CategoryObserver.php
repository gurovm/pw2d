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
            Feature::create([
                'category_id' => $category->id,
                'name' => $parentFeature->name,
                'slug' => $parentFeature->slug . '-cat-' . $category->id,
                'data_type' => $parentFeature->data_type,
                'unit' => $parentFeature->unit,
                'weight' => $parentFeature->weight,
            ]);
        }
    }
}
