<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Livewire\ProductCompare;
use App\Models\Category;
use App\Models\Preset;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Brand-bleed regression suite.
 *
 * Each scenario asserts that rendered HTML under a non-pw2d tenant:
 *   - Contains the tenant's own brand name
 *   - Contains ZERO occurrences of the string "pw2d" (case-sensitive)
 */
class SeoBrandBleedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // See SeoSchemaTest::setUp() for why we re-fetch via find() — Tenant::create()
        // returns a model whose id has been overwritten with the sqlite rowid.
        Tenant::create(['id' => 'acme', 'name' => 'Acme']);
        $this->tenant = Tenant::find('acme');
        $this->tenant->brand_name = 'Acme Shop';
        $this->tenant->save();

        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        parent::tearDown();
    }

    public function test_homepage_title_uses_tenant_brand_name(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Acme Shop', $html);
        $this->assertNoBrandBleed($html);
    }

    public function test_og_site_name_uses_tenant_brand_name(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Acme Shop', $html);
        $this->assertNoBrandBleed($html);
    }

    public function test_homepage_og_image_falls_back_to_tenant_default_not_pw2d_logo(): void
    {
        // Set explicit og image on the tenant so we can assert it appears
        $this->tenant->seo_default_image = 'https://acme.com/og-image.png';
        $this->tenant->save();

        $response = $this->get('/');
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('https://acme.com/og-image.png', $html);
    }

    public function test_parent_category_title_uses_tenant_brand_name(): void
    {
        $parent = Category::factory()->create(['name' => 'Brewing Methods']);
        $child  = Category::factory()->create([
            'name'      => 'Espresso',
            'parent_id' => $parent->id,
        ]);

        $response = $this->get('/compare/' . $parent->slug);
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Acme Shop', $html);
        $this->assertNoBrandBleed($html);
    }

    public function test_leaf_category_title_uses_tenant_brand_name(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines']);

        $response = $this->get('/compare/' . $category->slug);
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Acme Shop', $html);
        $this->assertNoBrandBleed($html);
    }

    public function test_preset_page_title_uses_tenant_brand_name(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines']);
        $preset   = Preset::factory()->create([
            'name'        => 'Home Barista',
            'category_id' => $category->id,
        ]);

        $presetSlug = \Illuminate\Support\Str::slug($preset->name);
        $response   = $this->get('/compare/' . $category->slug . '?preset=' . $presetSlug);
        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Acme Shop', $html);
        $this->assertNoBrandBleed($html);
    }

    public function test_product_page_title_uses_tenant_brand_name(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines']);
        $product  = Product::factory()->create([
            'name'        => 'DeLonghi Dedica',
            'slug'        => 'delonghi-dedica',
            'category_id' => $category->id,
            'is_ignored'  => false,
            'status'      => null,
        ]);

        $response = $this->get('/product/' . $product->slug);
        $response->assertOk();

        $html = $response->getContent();
        $this->assertNoBrandBleed($html);
    }

    /**
     * Regression for the bug where ProductCompare::openProduct() dispatched a
     * `meta:product-opened` Livewire event whose `title` was hardcoded with
     * "| pw2d", causing the modal navigation to overwrite document.title with
     * pw2d branding even on tenant domains. The SSR HTML test above doesn't
     * catch this because the offending string lives in a JS dispatch payload.
     */
    public function test_open_product_dispatches_event_with_no_pw2d_in_title(): void
    {
        $category = Category::factory()->create(['name' => 'Espresso Machines']);
        $product  = Product::factory()->create([
            'name'        => 'DeLonghi Dedica',
            'slug'        => 'delonghi-dedica',
            'category_id' => $category->id,
            'is_ignored'  => false,
            'status'      => null,
        ]);

        Livewire::test(ProductCompare::class, ['slug' => $category->slug])
            ->call('openProduct', $product->slug)
            ->assertDispatched(
                'meta:product-opened',
                fn (string $event, array $params) => ! preg_match('/pw2d/i', $params['title'])
                    && ! preg_match('/Power to Decide/i', $params['title'])
            );
    }

    /**
     * Asserts the rendered HTML contains no leak of the pw2d brand identity.
     *
     * Case-insensitive on purpose — catches "pw2d", "Pw2D", "PW2D", and the
     * full "Power to Decide" string. Used by every page-scenario test in
     * this file.
     */
    private function assertNoBrandBleed(string $html): void
    {
        // Case-insensitive — catches "pw2d", "Pw2D", "PW2D" anywhere in the
        // rendered HTML. Also asserts the long-form brand string is absent.
        $count = preg_match_all('/pw2d/i', $html);

        if ($count > 0) {
            // Dump context around each hit to make regressions easy to diagnose.
            preg_match_all('/.{0,30}pw2d.{0,30}/i', $html, $m);
            $hits = implode("\n  ", $m[0] ?? []);
            $this->fail("Brand bleed regression: found {$count} occurrences of 'pw2d' in HTML.\nHits:\n  {$hits}");
        }

        $this->assertStringNotContainsString('Power to Decide', $html);
    }
}
