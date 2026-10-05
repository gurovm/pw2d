<?php

declare(strict_types=1);

namespace Tests\Feature\VsPages;

use App\Models\Category;
use App\Models\FeaturePreset;
use App\Models\LandingPage;
use App\Models\Preset;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\VsPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VsPageControllerTest extends TestCase
{
    use RefreshDatabase, VsFixtures;

    private Category $category;
    private Product $a;
    private Product $b;
    private VsPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initTenant('vs-ctrl-tenant');
        $this->category = Category::factory()->create(['name' => 'Espresso Machines', 'slug' => 'espresso-machines']);

        $speed = $this->makeFeature($this->category, 'Speed', 1);
        $build = $this->makeFeature($this->category, 'Build', 2);

        $this->a = $this->makeLiveProduct($this->category, 'Lelit Bianca', 2000, [$speed->id => 90, $build->id => 80]);
        $this->b = $this->makeLiveProduct($this->category, 'Profitec Drive', 2100, [$speed->id => 70, $build->id => 82]);

        $preset = Preset::factory()->create(['category_id' => $this->category->id, 'name' => 'The Purist', 'sort_order' => 1]);
        FeaturePreset::create(['preset_id' => $preset->id, 'feature_id' => $speed->id, 'weight' => 100]);

        $this->page = VsPage::factory()->published()->create([
            'category_id'  => $this->category->id,
            'product_a_id' => $this->a->id,
            'product_b_id' => $this->b->id,
            'slug'         => 'lelit-bianca-vs-profitec-drive',
            'title'        => 'Lelit Bianca vs Profitec Drive: scores, price and which to buy',
            'verdict'      => '<p>' . str_repeat('Buy the Bianca for speed. ', 12) . '</p>',
        ]);
    }

    protected function tearDown(): void
    {
        $this->endTenancy();
        parent::tearDown();
    }

    /** @test */
    public function a_published_page_renders_with_the_expected_content(): void
    {
        $response = $this->get('/vs/lelit-bianca-vs-profitec-drive');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Lelit Bianca vs Profitec Drive\s*<\/h1>/', $html);
        $this->assertStringContainsString('<title>Lelit Bianca vs Profitec Drive: scores, price and which to buy</title>', $html);
        $this->assertStringContainsString('rel="canonical" href="' . url('/vs/lelit-bianca-vs-profitec-drive') . '"', $html);
        $response->assertSee('~$2,000', false);
        $response->assertSee('8.5 / 10', false);          // Bianca: (90+80)/2/10
        $response->assertSee('Feature scores');
        $response->assertSee('Better for The Purist:', false);
        $response->assertSee('Where Lelit Bianca wins');
        $response->assertSee('Where Profitec Drive wins');
        $response->assertSee('Who should buy which');
        $response->assertSee('Verdict');
        $response->assertSee('Which is better?');
        $response->assertSee('/product/lelit-bianca', false);
        $response->assertSee('/product/profitec-drive', false);
        $response->assertSee('/compare/espresso-machines', false);
    }

    /** @test */
    public function the_meta_description_is_the_verdict_stripped_and_cut_near_155_chars(): void
    {
        $html = $this->get('/vs/lelit-bianca-vs-profitec-drive')->getContent();

        preg_match('/<meta name="description"\s+content="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'meta description missing');
        $this->assertStringNotContainsString('<p>', $m[1]);
        $this->assertStringStartsWith('Buy the Bianca for speed.', $m[1]);
        $this->assertLessThanOrEqual(160, mb_strlen(html_entity_decode($m[1])));
    }

    /** @test */
    public function the_schema_is_a_breadcrumb_list_only_with_no_offer_rating_or_product(): void
    {
        $html = $this->get('/vs/lelit-bianca-vs-profitec-drive')->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $blocks = array_map(fn ($j) => json_decode($j, true), $m[1]);
        $types = array_column($blocks, '@type');

        $this->assertContains('BreadcrumbList', $types);
        $this->assertNotContains('Product', $types);
        $json = implode('', $m[1]);
        $this->assertStringNotContainsString('"Offer"', $json);
        $this->assertStringNotContainsString('aggregateRating', $json);
        $this->assertStringContainsString('Lelit Bianca vs Profitec Drive', $json);
    }

    /** @test */
    public function a_draft_page_is_404(): void
    {
        $this->page->update(['status' => 'draft']);

        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertNotFound();
    }

    /** @test */
    public function an_unknown_slug_is_404(): void
    {
        $this->get('/vs/nope-vs-nada')->assertNotFound();
    }

    /** @test */
    public function a_hidden_pending_or_category_less_product_makes_the_page_404_even_when_cached(): void
    {
        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertOk(); // warm the 1h cache

        $this->b->update(['is_ignored' => true]);
        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertNotFound();

        $this->b->update(['is_ignored' => false, 'status' => 'pending']);
        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertNotFound();

        $this->b->update(['status' => null, 'category_id' => null]);
        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertNotFound();
    }

    /** @test */
    public function another_tenants_page_is_404(): void
    {
        $this->endTenancy();
        Tenant::create(['id' => 'vs-ctrl-other', 'name' => 'Other']);
        tenancy()->initialize(Tenant::find('vs-ctrl-other'));

        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertNotFound();
    }

    /** @test */
    public function the_view_model_is_cached_under_a_tenant_scoped_key(): void
    {
        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertOk();

        $this->assertTrue(Cache::has('tvs-ctrl-tenant:vs:lelit-bianca-vs-profitec-drive'));
    }

    /** @test */
    public function saving_the_page_busts_its_cache(): void
    {
        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertOk();
        $this->page->update(['intro' => '<p>Fresh intro.</p>']);

        $this->assertFalse(Cache::has('tvs-ctrl-tenant:vs:lelit-bianca-vs-profitec-drive'));
        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertSee('Fresh intro.');
    }

    /** @test */
    public function the_page_links_to_the_published_best_page_when_there_is_one(): void
    {
        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertDontSee('/best/', false);

        Cache::flush();
        LandingPage::factory()->published()->create(['category_id' => $this->category->id, 'slug' => 'best-espresso']);

        $this->get('/vs/lelit-bianca-vs-profitec-drive')->assertSee('/best/best-espresso', false);
    }

    /** @test */
    public function the_best_page_lists_published_head_to_head_pages_and_nothing_otherwise(): void
    {
        LandingPage::factory()->published()->create(['category_id' => $this->category->id, 'slug' => 'best-espresso']);

        $this->get('/best/best-espresso')->assertOk()
            ->assertSee('Head-to-head')
            ->assertSee('Lelit Bianca vs Profitec Drive')
            ->assertSee('/vs/lelit-bianca-vs-profitec-drive', false);

        // A draft page, then a hidden product, drop out of the list.
        $this->page->update(['status' => 'draft']);
        $this->get('/best/best-espresso')->assertOk()->assertDontSee('Head-to-head');
    }

    /** @test */
    public function the_best_page_has_no_head_to_head_section_without_vs_pages(): void
    {
        $this->page->delete();
        LandingPage::factory()->published()->create(['category_id' => $this->category->id, 'slug' => 'best-espresso']);

        $this->get('/best/best-espresso')->assertOk()->assertDontSee('Head-to-head');
    }

    /** @test */
    public function the_best_page_skips_a_vs_page_whose_product_is_hidden(): void
    {
        LandingPage::factory()->published()->create(['category_id' => $this->category->id, 'slug' => 'best-espresso']);
        $this->b->update(['is_ignored' => true]);

        $this->get('/best/best-espresso')->assertOk()->assertDontSee('Head-to-head');
    }

    /** @test */
    public function the_sitemap_lists_published_vs_pages_only(): void
    {
        $c = $this->makeLiveProduct($this->category, 'Rocket Appartamento', 1900);
        VsPage::factory()->create([
            'category_id' => $this->category->id, 'product_a_id' => $this->a->id, 'product_b_id' => $c->id,
            'slug' => 'draft-vs-page', 'status' => 'draft',
        ]);

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString(url('/vs/lelit-bianca-vs-profitec-drive'), $xml);
        $this->assertStringNotContainsString('draft-vs-page', $xml);
    }

    /** @test */
    public function the_sitemap_drops_a_page_whose_product_is_hidden(): void
    {
        $this->b->update(['is_ignored' => true]);

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertStringNotContainsString('/vs/lelit-bianca-vs-profitec-drive', $xml);
    }

    /** @test */
    public function h1_breadcrumb_and_head_to_head_use_comparison_names_but_cards_keep_full_names(): void
    {
        $this->a->update(['model' => 'Bianca']);
        $this->a->brand->update(['name' => 'Lelit']);
        $this->b->update(['model' => 'Drive']);
        $this->b->brand->update(['name' => 'Profitec']);
        $this->a->update(['name' => 'Bianca V3 Espresso Machine, Black']);
        $this->page->update(['intro' => '<p>x</p>']);

        $html = $this->get('/vs/lelit-bianca-vs-profitec-drive')->getContent();

        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Lelit Bianca vs Profitec Drive\s*<\/h1>/', $html);
        $this->assertStringContainsString('Bianca V3 Espresso Machine, Black', $html); // card keeps the full name
        $this->assertStringContainsString('Lelit Bianca vs Profitec Drive', $html);

        LandingPage::factory()->published()->create(['category_id' => $this->category->id, 'slug' => 'best-espresso']);
        $this->get('/best/best-espresso')->assertSee('Lelit Bianca vs Profitec Drive');
    }
}
