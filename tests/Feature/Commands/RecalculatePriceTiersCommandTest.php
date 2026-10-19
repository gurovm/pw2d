<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Spec 029 addendum 2026-10-19 — nightly tier safety net (C2) + its summary log line (C3).
 */
class RecalculatePriceTiersCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_is_scheduled_daily_at_0320_before_the_landing_page_audit(): void
    {
        $events = collect(app(Schedule::class)->events());

        $find = fn (string $name): ?Event => $events->first(
            fn (Event $e) => str_contains((string) $e->command, $name)
        );

        $tiers = $find('products:recalculate-tiers');
        $audit = $find('pw2d:landing-pages:audit');

        $this->assertNotNull($tiers, 'products:recalculate-tiers is not scheduled');
        $this->assertSame('20 3 * * *', $tiers->expression);
        $this->assertTrue($tiers->withoutOverlapping);
        $this->assertTrue($tiers->runInBackground);
        $this->assertNotNull($audit);
        $this->assertSame('30 3 * * *', $audit->expression);
    }

    /** @test */
    public function it_logs_a_summary_line_with_fixed_and_skipped_counts(): void
    {
        Tenant::create(['id' => 'tier-cmd-tenant', 'name' => 'Tier Cmd Tenant']);
        tenancy()->initialize(Tenant::find('tier-cmd-tenant'));

        $category = Category::factory()->create(['budget_max' => 50, 'midrange_max' => 150]);
        $store    = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product  = Product::factory()->create(['category_id' => $category->id, 'status' => null, 'price_tier' => 1]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/product/1',
            'scraped_price' => 400,
            'raw_title'     => 'Pricey',
        ]);

        tenancy()->end();

        Log::spy();

        Artisan::call('products:recalculate-tiers');

        Log::shouldHaveReceived('info')
            ->with('products:recalculate-tiers: done', ['fixed' => 1, 'skipped' => 0])
            ->once();
        $this->assertSame(3, $product->fresh()->price_tier);
    }
}
