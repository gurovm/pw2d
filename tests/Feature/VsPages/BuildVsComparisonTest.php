<?php

declare(strict_types=1);

namespace Tests\Feature\VsPages;

use App\Actions\BuildVsComparison;
use App\Models\Category;
use App\Models\FeaturePreset;
use App\Models\Preset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildVsComparisonTest extends TestCase
{
    use RefreshDatabase, VsFixtures;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTenant('cmp-tenant');
        $this->category = Category::factory()->create(['name' => 'Espresso', 'slug' => 'espresso']);
    }

    protected function tearDown(): void
    {
        $this->endTenancy();
        parent::tearDown();
    }

    /** @test */
    public function feature_winners_need_a_three_point_gap_otherwise_tie(): void
    {
        $speed = $this->makeFeature($this->category, 'Speed', 1);
        $build = $this->makeFeature($this->category, 'Build', 2);
        $noise = $this->makeFeature($this->category, 'Noise', 3);
        $price = $this->makeFeature($this->category, 'Value', 4);

        $a = $this->makeLiveProduct($this->category, 'A 1', 1000, [$speed->id => 90, $build->id => 70, $noise->id => 60, $price->id => 50]);
        $b = $this->makeLiveProduct($this->category, 'B 2', 1000, [$speed->id => 80, $build->id => 72, $noise->id => 63, $price->id => 80]);

        $result = (new BuildVsComparison())->handle($a, $b);

        $this->assertSame(['Speed', 'Build', 'Noise', 'Value'], array_column($result['features'], 'feature'));
        $this->assertSame(['a', null, 'b', 'b'], array_column($result['features'], 'winner'));
        $this->assertSame(1, $result['a_wins']);
        $this->assertSame(2, $result['b_wins']);
        $this->assertSame(90, $result['features'][0]['a']);
        $this->assertSame(80, $result['features'][0]['b']);
    }

    /** @test */
    public function features_missing_a_score_on_either_side_are_left_out(): void
    {
        $speed = $this->makeFeature($this->category, 'Speed');
        $build = $this->makeFeature($this->category, 'Build');

        $a = $this->makeLiveProduct($this->category, 'A 1', 1000, [$speed->id => 90, $build->id => 70]);
        $b = $this->makeLiveProduct($this->category, 'B 2', 1000, [$speed->id => 50]);

        $result = (new BuildVsComparison())->handle($a, $b);

        $this->assertSame(['Speed'], array_column($result['features'], 'feature'));
    }

    /** @test */
    public function preset_winners_use_the_picks_formula_and_one_percent_tie(): void
    {
        $speed = $this->makeFeature($this->category, 'Speed');
        $build = $this->makeFeature($this->category, 'Build');

        $a = $this->makeLiveProduct($this->category, 'A 1', 1000, [$speed->id => 90, $build->id => 50]);
        $b = $this->makeLiveProduct($this->category, 'B 2', 1000, [$speed->id => 60, $build->id => 80]);

        $purist = Preset::factory()->create(['category_id' => $this->category->id, 'name' => 'The Purist', 'sort_order' => 1]);
        FeaturePreset::create(['preset_id' => $purist->id, 'feature_id' => $speed->id, 'weight' => 100]);
        FeaturePreset::create(['preset_id' => $purist->id, 'feature_id' => $build->id, 'weight' => 20]);
        // A: 9000 + 1000 = 10000   B: 6000 + 1600 = 7600  -> a

        $builder = Preset::factory()->create(['category_id' => $this->category->id, 'name' => 'The Builder', 'sort_order' => 2]);
        FeaturePreset::create(['preset_id' => $builder->id, 'feature_id' => $build->id, 'weight' => 100]);
        // A: 5000   B: 8000 -> b

        $tie = Preset::factory()->create(['category_id' => $this->category->id, 'name' => 'The Mix', 'sort_order' => 3]);
        FeaturePreset::create(['preset_id' => $tie->id, 'feature_id' => $speed->id, 'weight' => 50]);
        FeaturePreset::create(['preset_id' => $tie->id, 'feature_id' => $build->id, 'weight' => 100]);
        // A: 4500 + 5000 = 9500   B: 3000 + 8000 = 11000 -> b (not a tie)

        $result = (new BuildVsComparison())->handle($a, $b);

        $this->assertSame(
            [['preset' => 'The Purist', 'winner' => 'a'], ['preset' => 'The Builder', 'winner' => 'b'], ['preset' => 'The Mix', 'winner' => 'b']],
            $result['presets'],
        );
    }

    /** @test */
    public function presets_within_one_percent_are_a_tie_and_weightless_presets_are_skipped(): void
    {
        $speed = $this->makeFeature($this->category, 'Speed');

        $a = $this->makeLiveProduct($this->category, 'A 1', 1000, [$speed->id => 90]);
        $b = $this->makeLiveProduct($this->category, 'B 2', 1000, [$speed->id => 89.5]);

        $p = Preset::factory()->create(['category_id' => $this->category->id, 'name' => 'Close', 'sort_order' => 1]);
        FeaturePreset::create(['preset_id' => $p->id, 'feature_id' => $speed->id, 'weight' => 10]);
        Preset::factory()->create(['category_id' => $this->category->id, 'name' => 'Empty', 'sort_order' => 2]);

        $result = (new BuildVsComparison())->handle($a, $b);

        $this->assertSame([['preset' => 'Close', 'winner' => null]], $result['presets']);
    }

    /** @test */
    public function preset_scores_match_select_landing_page_picks_weighting(): void
    {
        // Same formula as SelectLandingPagePicks: sum(raw_value x weight) over weighted features only.
        $speed = $this->makeFeature($this->category, 'Speed');
        $build = $this->makeFeature($this->category, 'Build');
        $ignored = $this->makeFeature($this->category, 'Unweighted');

        // Unweighted feature must not influence the preset winner.
        $a = $this->makeLiveProduct($this->category, 'A 1', 1000, [$speed->id => 70, $build->id => 70, $ignored->id => 0]);
        $b = $this->makeLiveProduct($this->category, 'B 2', 1000, [$speed->id => 60, $build->id => 60, $ignored->id => 100]);

        $p = Preset::factory()->create(['category_id' => $this->category->id, 'name' => 'Core', 'sort_order' => 1]);
        FeaturePreset::create(['preset_id' => $p->id, 'feature_id' => $speed->id, 'weight' => 50]);
        FeaturePreset::create(['preset_id' => $p->id, 'feature_id' => $build->id, 'weight' => 50]);

        $this->assertSame('a', (new BuildVsComparison())->handle($a, $b)['presets'][0]['winner']);
    }
}
