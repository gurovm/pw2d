<?php

declare(strict_types=1);

namespace Tests\Feature\Compare;

use App\Models\Category;
use App\Models\Feature;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 027 §9.7 — compare-content.blade.php's contextual "Read our ranked
 * guide" link, driven by ProductCompare::publishedLandingPage().
 *
 * Full HTTP render ($this->get('/compare/{slug}')) — matches the load-bearing
 * lesson in PresetContentDepthTest: layout-level partials need a real HTTP
 * render, not just Livewire::test(), to catch inclusion bugs.
 */
class LandingPageCompareLinkTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'lp-link-tenant', 'name' => 'LP Link Tenant']);
        $this->tenant = Tenant::find('lp-link-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    /**
     * @return array{0: Category, 1: Product}
     */
    private function makeLeafCategoryWithProduct(string $slug): array
    {
        $category = Category::factory()->create(['slug' => $slug, 'name' => ucfirst(str_replace('-', ' ', $slug))]);
        Feature::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => $slug . '-product',
            'is_ignored'  => false,
            'status'      => null,
        ]);

        return [$category, $product];
    }

    /** @test */
    public function contextual_link_renders_when_a_published_landing_page_exists(): void
    {
        [$category, $product] = $this->makeLeafCategoryWithProduct('lp-link-published');

        $page = LandingPage::factory()->published()->create([
            'category_id'    => $category->id,
            'slug'           => 'lp-link-published-best',
            'title_template' => 'The Best Compare Link Published Guide',
            'picks'          => [['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b']],
        ]);

        tenancy()->end();

        $html = $this->get('/compare/' . $category->slug)->getContent();

        $this->assertStringContainsString('Read our ranked guide', $html);
        $this->assertStringContainsString(route('landing.show', $page->slug), $html);
        $this->assertStringContainsString('The Best Compare Link Published Guide', $html);
    }

    /** @test */
    public function contextual_link_is_absent_when_no_landing_page_exists(): void
    {
        [$category, $product] = $this->makeLeafCategoryWithProduct('lp-link-none');

        tenancy()->end();

        $html = $this->get('/compare/' . $category->slug)->getContent();

        $this->assertStringNotContainsString('Read our ranked guide', $html);
    }

    /** @test */
    public function contextual_link_is_absent_when_the_landing_page_is_a_draft(): void
    {
        [$category, $product] = $this->makeLeafCategoryWithProduct('lp-link-draft');

        LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'lp-link-draft-best',
            'status'      => 'draft',
            'picks'       => [['product_id' => $product->id, 'role' => 'overall', 'headline' => 'h', 'body' => 'b']],
        ]);

        tenancy()->end();

        $html = $this->get('/compare/' . $category->slug)->getContent();

        $this->assertStringNotContainsString('Read our ranked guide', $html);
    }
}
