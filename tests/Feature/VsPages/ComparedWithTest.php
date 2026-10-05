<?php

declare(strict_types=1);

namespace Tests\Feature\VsPages;

use App\Models\Category;
use App\Models\Product;
use App\Models\VsPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComparedWithTest extends TestCase
{
    use RefreshDatabase, VsFixtures;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTenant('cw-tenant');
        $this->category = Category::factory()->create(['name' => 'Espresso', 'slug' => 'espresso']);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        $this->endTenancy();
        parent::tearDown();
    }

    /** Loaded the way ProductCompare::selectedProduct loads it. */
    private function loaded(Product $p): Product
    {
        return Product::with(['brand', 'featureValues.feature', 'offers.store', 'category.features'])->findOrFail($p->id);
    }

    private function render(Product $p): string
    {
        return Blade::render('<x-compared-with :product="$product" />', ['product' => $this->loaded($p)]);
    }

    /** @test */
    public function it_renders_rivals_with_name_link_score_and_price(): void
    {
        $f = $this->makeFeature($this->category, 'Speed');
        $base = $this->makeLiveProduct($this->category, 'Base 100', 1000, [$f->id => 50]);
        $this->makeLiveProduct($this->category, 'Rival Two', 1100, [$f->id => 85]);

        $html = $this->render($base);

        $this->assertStringContainsString('Compared with', $html);
        $this->assertStringContainsString('href="' . route('product.show', ['product' => 'rival-two']) . '"', $html);
        $this->assertStringContainsString('Rival Two', $html);
        $this->assertStringContainsString('8.5 / 10', $html);
        $this->assertStringContainsString('~$1,100', $html);
        $this->assertStringNotContainsString('Read the comparison', $html);
    }

    /** @test */
    public function it_renders_nothing_without_rivals(): void
    {
        $base = $this->makeLiveProduct($this->category, 'Lonely 100', 1000);
        $this->makeLiveProduct($this->category, 'Too Dear 200', 5000);

        $this->assertSame('', trim($this->render($base)));
    }

    /** @test */
    public function it_links_to_a_published_comparison_in_either_order_but_not_a_draft(): void
    {
        $base = $this->makeLiveProduct($this->category, 'Base 100', 1000);
        $r1   = $this->makeLiveProduct($this->category, 'Rival One', 1000);
        $r2   = $this->makeLiveProduct($this->category, 'Rival Two', 1010);
        $r3   = $this->makeLiveProduct($this->category, 'Rival Three', 1020);

        VsPage::factory()->published()->create(['category_id' => $this->category->id, 'product_a_id' => $base->id, 'product_b_id' => $r1->id, 'slug' => 'base-vs-one']);
        VsPage::factory()->published()->create(['category_id' => $this->category->id, 'product_a_id' => $r2->id, 'product_b_id' => $base->id, 'slug' => 'two-vs-base']);
        VsPage::factory()->create(['category_id' => $this->category->id, 'product_a_id' => $base->id, 'product_b_id' => $r3->id, 'slug' => 'base-vs-three']);

        $html = $this->render($base);

        $this->assertStringContainsString(route('vs.show', ['slug' => 'base-vs-one']), $html);
        $this->assertStringContainsString(route('vs.show', ['slug' => 'two-vs-base']), $html);
        $this->assertStringNotContainsString('base-vs-three', $html);
        $this->assertSame(2, substr_count($html, 'Read the comparison'));
    }

    /** @return array{0: int, 1: int, 2: string} total queries, vs_pages queries, html */
    private function measure(Product $base): array
    {
        $product = $this->loaded($base);

        // Any lazy load inside the block throws.
        Model::preventLazyLoading(true);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $html = Blade::render('<x-compared-with :product="$product" />', ['product' => $product]);

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        Model::preventLazyLoading(false);

        return [
            $queries->count(),
            $queries->filter(fn ($q) => str_contains($q, 'vs_pages'))->count(),
            $html,
        ];
    }

    /** @test */
    public function it_has_no_n_plus_one_and_one_vs_page_query(): void
    {
        $f = $this->makeFeature($this->category, 'Speed');

        // Scenario 1: one rival.
        $small = $this->makeLiveProduct($this->category, 'Small Base', 1000, [$f->id => 50]);
        $r = $this->makeLiveProduct($this->category, 'Small Rival', 1000, [$f->id => 60]);
        VsPage::factory()->published()->create(['category_id' => $this->category->id, 'product_a_id' => $small->id, 'product_b_id' => $r->id, 'slug' => 'small-vs']);

        // Scenario 2: three rivals (the limit) in another category, each with a VS page.
        $other = Category::factory()->create(['name' => 'Grinders', 'slug' => 'grinders']);
        $g = $this->makeFeature($other, 'Speed');
        $big = $this->makeLiveProduct($other, 'Big Base', 1000, [$g->id => 50]);
        foreach (['One', 'Two', 'Three', 'Four'] as $i => $n) {
            $x = $this->makeLiveProduct($other, "Big Rival {$n}", 1000 + $i * 10, [$g->id => 60 + $i]);
            VsPage::factory()->published()->create(['category_id' => $other->id, 'product_a_id' => $big->id, 'product_b_id' => $x->id, 'slug' => "big-vs-{$n}"]);
        }

        [$smallTotal, $smallVs, $smallHtml] = $this->measure($small);
        [$bigTotal, $bigVs, $bigHtml] = $this->measure($big);

        $this->assertSame(1, substr_count($smallHtml, 'Read the comparison'));
        $this->assertSame(3, substr_count($bigHtml, 'Read the comparison'));
        $this->assertSame(1, $smallVs);
        $this->assertSame(1, $bigVs);
        $this->assertSame($smallTotal, $bigTotal, 'query count must not grow with the number of rivals');
    }

    /** @test */
    public function the_block_shows_on_the_product_page(): void
    {
        $base = $this->makeLiveProduct($this->category, 'Base 100', 1000);
        $this->makeLiveProduct($this->category, 'Rival Two', 1100);

        $this->get('/product/base-100')->assertOk()->assertSee('Compared with')->assertSee('Rival Two');
    }
}
