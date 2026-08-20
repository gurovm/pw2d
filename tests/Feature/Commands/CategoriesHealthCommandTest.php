<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Spec 032 §"pw2d:categories:health" command — table output, --due filtering,
 * --json shape, unknown tenant handling, and the exit-code contract (FAILURE
 * only when a PUBLISHED landing page's category carries import_debt/stale).
 */
class CategoriesHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function makeTenant(string $id): Tenant
    {
        Tenant::create(['id' => $id, 'name' => $id]);
        return Tenant::find($id);
    }

    private function makeCategoryWithImportDebt(Tenant $tenant): Category
    {
        tenancy()->initialize($tenant);

        $category = Category::factory()->create();
        $product  = Product::factory()->create(['category_id' => $category->id]);
        ProductOffer::create([
            'product_id'        => $product->id,
            'url'               => 'https://example.com/import-debt',
            'raw_title'         => $product->name,
            'scraped_price'     => 50,
            'health_checked_at' => null,
        ]);

        tenancy()->end();

        return $category;
    }

    private function attachLandingPage(Tenant $tenant, Category $category, string $status): void
    {
        tenancy()->initialize($tenant);

        LandingPage::factory()->create([
            'category_id' => $category->id,
            'status'      => $status,
            'picks'       => [],
        ]);

        tenancy()->end();
    }

    /** @test */
    public function it_exits_failure_when_a_published_pages_category_carries_import_debt(): void
    {
        $tenant   = $this->makeTenant('cat-health-cmd-fail');
        $category = $this->makeCategoryWithImportDebt($tenant);
        $this->attachLandingPage($tenant, $category, 'published');

        $exitCode = Artisan::call('pw2d:categories:health', ['tenant' => 'cat-health-cmd-fail']);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    /** @test */
    public function it_exits_success_when_only_a_draft_pages_category_carries_import_debt(): void
    {
        $tenant   = $this->makeTenant('cat-health-cmd-draft');
        $category = $this->makeCategoryWithImportDebt($tenant);
        $this->attachLandingPage($tenant, $category, 'draft');

        $exitCode = Artisan::call('pw2d:categories:health', ['tenant' => 'cat-health-cmd-draft']);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    /** @test */
    public function it_exits_success_when_no_landing_page_backs_the_flagged_category(): void
    {
        $tenant = $this->makeTenant('cat-health-cmd-nopage');
        $this->makeCategoryWithImportDebt($tenant);

        $exitCode = Artisan::call('pw2d:categories:health', ['tenant' => 'cat-health-cmd-nopage']);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    /** @test */
    public function unknown_tenant_argument_fails(): void
    {
        $exitCode = Artisan::call('pw2d:categories:health', ['tenant' => 'does-not-exist']);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    /** @test */
    public function omitting_the_tenant_argument_runs_every_tenant(): void
    {
        $tenantA = $this->makeTenant('cat-health-cmd-all-a');
        $tenantB = $this->makeTenant('cat-health-cmd-all-b');

        $categoryA = $this->makeCategoryWithImportDebt($tenantA);
        $this->attachLandingPage($tenantA, $categoryA, 'published');

        $categoryB = $this->makeCategoryWithImportDebt($tenantB);
        $this->attachLandingPage($tenantB, $categoryB, 'draft');

        $exitCode = Artisan::call('pw2d:categories:health');
        $output   = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('cat-health-cmd-all-a', $output);
        $this->assertStringContainsString('cat-health-cmd-all-b', $output);
    }

    /** @test */
    public function due_option_hides_healthy_categories(): void
    {
        $tenant = $this->makeTenant('cat-health-cmd-due');

        tenancy()->initialize($tenant);
        $healthyCategory = Category::factory()->create(['name' => 'Healthy Category']);
        for ($i = 0; $i < 45; $i++) {
            $product = Product::factory()->create(['category_id' => $healthyCategory->id]);
            ProductOffer::create([
                'product_id'        => $product->id,
                'url'               => "https://example.com/due-{$i}",
                'raw_title'         => $product->name,
                'scraped_price'     => 50,
                'condition'         => 'new',
                'health_checked_at' => now(),
            ]);
        }
        tenancy()->end();

        $dueCategory = $this->makeCategoryWithImportDebt($tenant);

        Artisan::call('pw2d:categories:health', ['tenant' => 'cat-health-cmd-due', '--due' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('Healthy Category', $output);
        $this->assertStringContainsString($dueCategory->slug, $output);
    }

    /** @test */
    public function json_option_emits_valid_json_with_reasons(): void
    {
        $tenant   = $this->makeTenant('cat-health-cmd-json');
        $category = $this->makeCategoryWithImportDebt($tenant);

        Artisan::call('pw2d:categories:health', ['tenant' => 'cat-health-cmd-json', '--json' => true]);
        $output = Artisan::output();

        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
        $row = collect($decoded)->firstWhere('category_id', $category->id);
        $this->assertNotNull($row);
        $this->assertContains('import_debt', $row['reasons']);
        $this->assertSame('cat-health-cmd-json', $row['tenant_id']);
    }

    /** @test */
    public function table_output_lists_every_category_sorted_by_oldest_check(): void
    {
        $tenant = $this->makeTenant('cat-health-cmd-table');

        tenancy()->initialize($tenant);
        $older = Category::factory()->create(['name' => 'Older Category']);
        $productOld = Product::factory()->create(['category_id' => $older->id]);
        ProductOffer::create([
            'product_id'        => $productOld->id,
            'url'               => 'https://example.com/older',
            'raw_title'         => $productOld->name,
            'scraped_price'     => 50,
            'condition'         => 'new',
            'health_checked_at' => now()->subDays(20),
        ]);

        $newer = Category::factory()->create(['name' => 'Newer Category']);
        $productNew = Product::factory()->create(['category_id' => $newer->id]);
        ProductOffer::create([
            'product_id'        => $productNew->id,
            'url'               => 'https://example.com/newer',
            'raw_title'         => $productNew->name,
            'scraped_price'     => 50,
            'condition'         => 'new',
            'health_checked_at' => now()->subDays(1),
        ]);
        tenancy()->end();

        Artisan::call('pw2d:categories:health', ['tenant' => 'cat-health-cmd-table']);
        $output = Artisan::output();

        $olderPos = strpos($output, $older->slug);
        $newerPos = strpos($output, $newer->slug);

        $this->assertNotFalse($olderPos);
        $this->assertNotFalse($newerPos);
        $this->assertLessThan($newerPos, $olderPos, 'the older (oldest_check ASC) category must render first');
    }
}
