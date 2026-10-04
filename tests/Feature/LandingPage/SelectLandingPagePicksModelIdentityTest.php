<?php

declare(strict_types=1);

namespace Tests\Feature\LandingPage;

use App\Actions\AuditLandingPageFreshness;
use App\Actions\SelectLandingPagePicks;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Spec 041 Parts 3 + 4 — model-first duplicate identity, rejection logging, and
 * the shared health-check eligibility. Names are real prod names (2026-10-04).
 */
class SelectLandingPagePicksModelIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;
    private Feature $feature;
    private int $score = 90;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'lp-model-tenant', 'name' => 'LP Model Tenant']);
        tenancy()->initialize(Tenant::find('lp-model-tenant'));

        $this->category = Category::factory()->create(['slug' => 'lp-model', 'name' => 'Lp Model']);
        $this->feature  = Feature::factory()->create(['category_id' => $this->category->id, 'is_higher_better' => true, 'unit' => null]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    /** Each call scores 1 lower than the last, so creation order == rank order. */
    private function product(string $name, ?Brand $brand, ?string $model, array $offer = []): Product
    {
        $slug = 'lp-model-' . ++$this->score . '-' . substr(md5($name), 0, 6);

        $product = Product::factory()->create([
            'category_id'   => $this->category->id,
            'brand_id'      => $brand?->id,
            'name'          => $name,
            'model'         => $model,
            'slug'          => $slug,
            'ai_summary'    => 'Summary',
            'is_ignored'    => false,
            'status'        => null,
            'amazon_rating' => null,
            'price_tier'    => 2,
        ]);

        ProductOffer::create(array_merge([
            'product_id'        => $product->id,
            'url'               => "https://example.com/{$slug}",
            'raw_title'         => $name,
            'image_url'         => "https://images.example.com/{$slug}.jpg",
            'scraped_price'     => 100,
            'health_checked_at' => now(),
        ], $offer));

        ProductFeatureValue::factory()->create([
            'product_id' => $product->id,
            'feature_id' => $this->feature->id,
            'raw_value'  => 200 - $this->score,
        ]);

        return $product;
    }

    /** @return list<int> */
    private function pickedIds(): array
    {
        return array_column((new SelectLandingPagePicks())->execute($this->category), 'product_id');
    }

    private function fillerBrands(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $this->product("Filler{$i} Model{$i}", Brand::factory()->create(['name' => "Filler{$i}"]), "Model {$i}");
        }
    }

    /** @test */
    public function thirteen_razer_v3_names_with_distinct_models_are_not_falsely_merged(): void
    {
        $razer = Brand::factory()->create(['name' => 'Razer']);

        $names = [
            'BlackWidow V3', 'BlackWidow V3 Pro', 'BlackWidow V3 Mini', 'BlackWidow V3 Tenkeyless', 'Ornata V3',
            'Ornata V3 X', 'Ornata V3 Tenkeyless', 'Huntsman V3 Pro', 'Huntsman V3 Pro Tenkeyless', 'Huntsman V3 Pro Mini',
            'DeathStalker V2', 'Cynosa V2', 'Huntsman Mini',
        ];

        foreach ($names as $name) {
            $this->product("Razer {$name}", $razer, $name);
        }

        // Before models, every one of these keyed to razer:v3 and the pool collapsed to one pick.
        $this->assertCount(7, array_unique($this->pickedIds()));
    }

    /** @test */
    public function four_oracle_jet_colourways_with_one_model_yield_a_single_pick(): void
    {
        $breville = Brand::factory()->create(['name' => 'Breville']);

        $jets = collect([
            'Breville Oracle Jet Espresso Machine Black Truffle',
            'Breville Oracle Jet Espresso Machine Brushed Stainless Steel',
            'Breville Oracle Jet Espresso Machine Sea Salt',
            'Breville Oracle Jet Espresso Machine Damson Blue',
        ])->map(fn (string $n) => $this->product($n, $breville, 'Oracle Jet'));

        $this->fillerBrands(5);

        $picked = $this->pickedIds();

        $this->assertCount(1, array_intersect($picked, $jets->pluck('id')->all()));
        $this->assertContains($jets->first()->id, $picked, 'the best-ranked colourway is the one kept');
    }

    /** @test */
    public function the_same_model_under_a_different_brand_is_not_a_duplicate(): void
    {
        $this->product('Alpha Wave Keys', Brand::factory()->create(['name' => 'Alpha']), 'Wave Keys');
        $this->product('Beta Wave Keys', Brand::factory()->create(['name' => 'Beta']), 'Wave Keys');
        $this->fillerBrands(3);

        $this->assertCount(5, $this->pickedIds());
    }

    /** @test */
    public function model_comparison_ignores_case_and_punctuation(): void
    {
        $logitech = Brand::factory()->create(['name' => 'Logitech']);

        $a = $this->product('Logitech Wave Keys Graphite', $logitech, 'Wave Keys');
        $b = $this->product('Logitech Wave Keys Off-White', $logitech, 'WAVE-KEYS');
        $this->fillerBrands(5);

        $picked = $this->pickedIds();
        $this->assertContains($a->id, $picked);
        $this->assertNotContains($b->id, $picked);
    }

    /** @test */
    public function models_differing_only_by_a_plus_are_distinct_and_both_can_be_picked(): void
    {
        $shure = Brand::factory()->create(['name' => 'Shure']);

        $a = $this->product('Shure MV7 Podcast Microphone', $shure, 'MV7');
        $b = $this->product('Shure MV7+ Podcast Microphone', $shure, 'MV7+');
        $this->fillerBrands(5);

        $picked = $this->pickedIds();
        $this->assertContains($a->id, $picked);
        $this->assertContains($b->id, $picked);
    }

    /** @test */
    public function a_mixed_pair_with_one_null_model_falls_back_to_the_existing_heuristic(): void
    {
        $razer = Brand::factory()->create(['name' => 'Razer']);

        $a = $this->product('Razer BlackWidow V3', $razer, 'BlackWidow V3');
        $b = $this->product('Razer Huntsman V3', $razer, null); // old path: both key to razer:v3
        $this->fillerBrands(5);

        $picked = $this->pickedIds();
        $this->assertContains($a->id, $picked);
        $this->assertNotContains($b->id, $picked);
    }

    /** @test */
    public function a_duplicate_rejection_is_logged_once_per_pair_with_its_path(): void
    {
        Log::spy();

        $breville = Brand::factory()->create(['name' => 'Breville']);
        $a = $this->product('Breville Oracle Jet Black Truffle', $breville, 'Oracle Jet');
        $b = $this->product('Breville Oracle Jet Sea Salt', $breville, 'Oracle Jet');
        $this->fillerBrands(5);

        $this->pickedIds();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === 'SelectLandingPagePicks: duplicate rejected'
                && $context['path'] === 'model'
                && $context['candidate_id'] === $b->id
                && $context['picked_id'] === $a->id)
            ->once();
    }

    /** @test */
    public function an_offer_never_health_checked_is_not_pick_eligible_in_select_or_audit(): void
    {
        $products = collect(range(1, 6))->map(fn (int $i) => $this->product("Brand{$i} Item{$i}", Brand::factory()->create(['name' => "Brand{$i}"]), "Item {$i}"));

        $picks = collect((new SelectLandingPagePicks())->execute($this->category))
            ->map(fn (array $p) => $p + ['headline' => 'H', 'body' => 'B'])->all();

        $page = LandingPage::factory()->create([
            'category_id' => $this->category->id,
            'slug'        => 'best-lp-model',
            'status'      => 'published',
            'picks'       => $picks,
        ]);

        $this->assertNotContains('pick_ineligible', (new AuditLandingPageFreshness())->execute($page));

        // One shared fixture: the top pick's only offer loses its health check.
        $victim = $products->first();
        ProductOffer::where('product_id', $victim->id)->update(['health_checked_at' => null]);

        $this->assertContains('pick_ineligible', (new AuditLandingPageFreshness())->execute($page->fresh()));
        $this->assertNotContains($victim->id, $this->pickedIds());
    }

    /** @test */
    public function the_min_picks_message_names_the_products_awaiting_a_health_check(): void
    {
        foreach (range(1, 3) as $i) {
            $this->product("Checked{$i} Item{$i}", Brand::factory()->create(['name' => "Checked{$i}"]), "Item {$i}");
        }
        foreach (range(1, 3) as $i) {
            $this->product("Unchecked{$i} Item{$i}", Brand::factory()->create(['name' => "Unchecked{$i}"]), "Item {$i}", ['health_checked_at' => null]);
        }

        try {
            $this->pickedIds();
            $this->fail('expected the pool to be too small');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('only 3 eligible pick(s)', $e->getMessage());
            $this->assertStringContainsString('3 products await a health check', $e->getMessage());
        }
    }

    /** @test */
    public function the_message_stays_quiet_when_nothing_awaits_a_check(): void
    {
        $this->fillerBrands(3);

        try {
            $this->pickedIds();
            $this->fail('expected the pool to be too small');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('await a health check', $e->getMessage());
        }
    }
}
