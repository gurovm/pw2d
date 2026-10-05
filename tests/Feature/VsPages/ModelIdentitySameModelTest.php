<?php

declare(strict_types=1);

namespace Tests\Feature\VsPages;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\ModelIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 043 — the extracted pairwise decision (picks behaviour is pinned by the existing SelectLandingPagePicks tests). */
class ModelIdentitySameModelTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, ?Brand $brand, ?string $model = null): Product
    {
        $p = Product::factory()->create([
            'category_id' => Category::factory()->create()->id,
            'brand_id'    => $brand?->id,
            'name'        => $name,
            'model'       => $model,
        ]);

        return $p->load('brand');
    }

    /** @test */
    public function model_column_decides_when_both_sides_have_one(): void
    {
        $brand = Brand::factory()->create(['name' => 'Jura']);

        $this->assertTrue(ModelIdentity::sameModel(
            $this->product('Jura E8 Black', $brand, 'E8'),
            $this->product('Totally Different Name', $brand, 'e-8'),
        ));
        $this->assertFalse(ModelIdentity::sameModel(
            $this->product('Jura E8 Black', $brand, 'E8'),
            $this->product('Jura E8 White', $brand, 'E6'),
        ));
    }

    /** @test */
    public function heuristic_key_matches_colour_variants_and_separates_models(): void
    {
        $brand = Brand::factory()->create(['name' => 'Jura']);

        $this->assertTrue(ModelIdentity::sameModel($this->product('Jura Z10 Gen 1', $brand), $this->product('Jura Z10 Aluminum White', $brand)));
        $this->assertFalse(ModelIdentity::sameModel($this->product('Jura Z10 Aluminum White', $brand), $this->product('Jura Z8 Aluminum White', $brand)));
        $this->assertSame(['path' => 'heuristic', 'key' => $brand->id . ':z10'], ModelIdentity::match($this->product('Jura Z10 Gen 1', $brand), $this->product('Jura Z10 White', $brand)));
    }

    /** @test */
    public function similarity_fallback_applies_only_without_a_model_token(): void
    {
        $brand = Brand::factory()->create(['name' => 'Gaggia']);

        $this->assertTrue(ModelIdentity::sameModel($this->product('Gaggia Cadorna Prestige', $brand), $this->product('Gaggia Cadorna Prestige Black', $brand)));
        $this->assertFalse(ModelIdentity::sameModel($this->product('Gaggia Cadorna Prestige', $brand), $this->product('Gaggia Brera', $brand)));
    }
}
