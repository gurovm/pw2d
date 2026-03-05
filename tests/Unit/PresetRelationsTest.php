<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Preset;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PresetRelationsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_attach_features_to_preset_with_weight()
    {
        $category = Category::factory()->create();
        
        $feature = Feature::factory()->create([
            'category_id' => $category->id
        ]);
        
        $preset = Preset::create([
            'category_id' => $category->id,
            'name' => 'Test Preset'
        ]);

        $preset->features()->attach($feature->id, ['weight' => 75]);

        $this->assertCount(1, $preset->features);
        $this->assertEquals(75, $preset->features->first()->pivot->weight);
    }
}
