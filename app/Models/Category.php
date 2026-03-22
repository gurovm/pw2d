<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Category extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'budget_max',
        'midrange_max',
        'buying_guide',
        'image',
        'sample_prompts',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'buying_guide'   => 'array',
        'sample_prompts' => 'array',
    ];

    /**
     * Get the parent category.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Get all child categories.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Get all products in this category.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get all features for this category.
     */
    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }

    /**
     * Get all presets for this category.
     */
    public function presets(): HasMany
    {
        return $this->hasMany(Preset::class);
    }

    /**
     * Infer the price tier (1=Budget, 2=Mid, 3=Premium) for a given price using
     * this category's thresholds. Falls back to global defaults ($50/$150) if
     * budget_max / midrange_max have not been set by the AI generator yet.
     */
    public function priceTierFor(?float $price): ?int
    {
        if ($price === null) return null;

        $budgetMax   = $this->budget_max   ?? 50;
        $midrangeMax = $this->midrange_max ?? 150;

        return match (true) {
            $price <= $budgetMax   => 1,
            $price <= $midrangeMax => 2,
            default                => 3,
        };
    }

    /**
     * Get all descendant categories recursively.
     */
    public function getAllDescendants(): \Illuminate\Support\Collection
    {
        $descendants = collect();
        
        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->getAllDescendants());
        }
        
        return $descendants;
    }
}
