<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Spec 027 Addendum A §2d — pw2d:flag-condition-products {tenant} {--ignore}.
 *
 * Tests run on central domain. Tenancy is initialized manually for seeding, then
 * ended before Artisan::call so the command initializes tenancy itself — matches
 * GenerateLandingPageCommandTest's established pattern.
 */
class FlagConditionProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'fcp-tenant', 'name' => 'FCP Tenant']);
        Tenant::create(['id' => 'fcp-other-tenant', 'name' => 'FCP Other Tenant']);
        $this->tenant      = Tenant::find('fcp-tenant');
        $this->otherTenant = Tenant::find('fcp-other-tenant');

        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function makeProductWithOfferTitle(Category $category, string $slug, string $rawTitle, array $overrides = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'category_id' => $category->id,
            'slug'        => $slug,
        ], $overrides));

        ProductOffer::create([
            'product_id' => $product->id,
            'url'        => "https://example.com/{$slug}",
            'raw_title'  => $rawTitle,
        ]);

        return $product;
    }

    /** @test */
    public function unknown_tenant_returns_failure(): void
    {
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:flag-condition-products', ['tenant' => 'no-such-tenant']);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    /** @test */
    public function it_lists_condition_marked_products_without_flagging_by_default(): void
    {
        $category = Category::factory()->create();

        $renewed = $this->makeProductWithOfferTitle($category, 'fcp-renewed', 'Logitech G915 TKL (Renewed)');
        $clean   = $this->makeProductWithOfferTitle($category, 'fcp-clean', 'Logitech G915 TKL');

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:flag-condition-products', ['tenant' => 'fcp-tenant']);
        $output   = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString((string) $renewed->id, $output);
        $this->assertStringContainsString('renewed', $output);
        $this->assertStringContainsString('Read-only run', $output);

        tenancy()->initialize($this->tenant);
        $this->assertFalse($renewed->fresh()->is_ignored, 'Without --ignore, is_ignored must not change');
        $this->assertFalse($clean->fresh()->is_ignored);
    }

    /** @test */
    public function it_matches_on_ai_summary_as_well_as_offer_raw_title(): void
    {
        $category = Category::factory()->create();

        $refurbished = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'fcp-refurb-summary',
            'ai_summary'  => 'Solid pick, even if refurbished units are common.',
        ]);

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:flag-condition-products', ['tenant' => 'fcp-tenant']);
        $output   = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString((string) $refurbished->id, $output);
        $this->assertStringContainsString('ai_summary', $output);
        $this->assertStringContainsString('refurbish', $output);
    }

    /** @test */
    public function ignore_flag_sets_is_ignored_true_and_read_only_does_not(): void
    {
        $category = Category::factory()->create();

        $renewed = $this->makeProductWithOfferTitle($category, 'fcp-ignore-renewed', 'Some Keyboard (Open Box)');
        $clean   = $this->makeProductWithOfferTitle($category, 'fcp-ignore-clean', 'Some Keyboard');

        tenancy()->end();

        Artisan::call('pw2d:flag-condition-products', ['tenant' => 'fcp-tenant', '--ignore' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Flagged 1 product', $output);

        tenancy()->initialize($this->tenant);
        $this->assertTrue($renewed->fresh()->is_ignored, '--ignore must flag the matched product');
        $this->assertFalse($clean->fresh()->is_ignored, '--ignore must not touch clean products');
    }

    /** @test */
    public function it_is_tenant_scoped_and_does_not_see_another_tenants_products(): void
    {
        $category = Category::factory()->create();
        $this->makeProductWithOfferTitle($category, 'fcp-scope-mine', 'Clean Keyboard');

        tenancy()->end();
        tenancy()->initialize($this->otherTenant);

        $otherCategory = Category::factory()->create();
        $otherRenewed  = $this->makeProductWithOfferTitle($otherCategory, 'fcp-scope-other', 'Other Keyboard (Renewed)');

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:flag-condition-products', ['tenant' => 'fcp-tenant']);
        $output   = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        // BelongsToTenant global scope means the other tenant's renewed product ($otherRenewed)
        // is invisible entirely — zero matches for this tenant, not a filtered list.
        $this->assertStringContainsString('No condition-marked products found.', $output);
    }

    // =========================================================================
    // --urls review mode (owner follow-up — no live fetch, human-verifiable list)
    // =========================================================================

    /** @test */
    public function urls_option_prints_offer_urls_for_the_right_tenant_and_category(): void
    {
        $category      = Category::factory()->create(['slug' => 'fcp-urls-category']);
        $otherCategory = Category::factory()->create(['slug' => 'fcp-urls-other-category']);

        $inScope = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'fcp-urls-in-scope',
            'ai_summary'  => 'A perfectly clean summary.',
            'is_ignored'  => false,
            'status'      => null,
        ]);
        ProductOffer::create([
            'product_id' => $inScope->id,
            'url'        => 'https://www.amazon.com/dp/B0URLTEST1',
            'raw_title'  => $inScope->name,
        ]);

        $outOfScope = Product::factory()->create([
            'category_id' => $otherCategory->id,
            'slug'        => 'fcp-urls-out-of-scope',
            'ai_summary'  => 'Also clean, but in a different category.',
            'is_ignored'  => false,
            'status'      => null,
        ]);
        ProductOffer::create([
            'product_id' => $outOfScope->id,
            'url'        => 'https://www.amazon.com/dp/B0URLTEST2',
            'raw_title'  => $outOfScope->name,
        ]);

        tenancy()->end();

        $exitCode = Artisan::call('pw2d:flag-condition-products', [
            'tenant'     => 'fcp-tenant',
            '--urls'     => true,
            '--category' => 'fcp-urls-category',
        ]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('https://www.amazon.com/dp/B0URLTEST1', $output);
        $this->assertStringContainsString((string) $inScope->id, $output);
        $this->assertStringNotContainsString('https://www.amazon.com/dp/B0URLTEST2', $output);
    }

    /** @test */
    public function urls_option_without_category_returns_failure(): void
    {
        tenancy()->end();

        $exitCode = Artisan::call('pw2d:flag-condition-products', ['tenant' => 'fcp-tenant', '--urls' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
    }
}
