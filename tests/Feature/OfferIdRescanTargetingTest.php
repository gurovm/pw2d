<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\InitializeTenancyFromPayload;
use App\Http\Middleware\VerifyExtensionToken;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Spec 033 — `offer_id` rescan targeting.
 *
 * OfferIngestionController::ingest() / OfferIngestionService::processIncomingOffer()
 * previously re-resolved the offer being rescanned by (store_id, url) with no
 * ordering, so two products in DIFFERENT categories sharing one (store_id, url) —
 * a cross-category duplicate — always collapsed the update onto the lowest-id row.
 * Its twin was structurally unreachable by any rescan, from any category, ever.
 * `offer_id` lets the extension target the exact row it scraped.
 */
class OfferIdRescanTargetingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerifyExtensionToken::class, InitializeTenancyFromPayload::class]);
    }

    private function makeTenant(string $id): Tenant
    {
        Tenant::create(['id' => $id, 'name' => $id]);

        return Tenant::find($id);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'url'           => 'https://www.amazon.com/dp/B0DUPLICATE1',
            'store_slug'    => 'amazon',
            'raw_title'     => 'Duplicate Product Listing',
            'brand'         => 'Acme',
            'scraped_price' => 199.99,
            'condition'     => 'new', // needed for ListingHealthService to stamp health_checked_at
        ], $overrides);
    }

    /** @test */
    public function offer_id_targets_the_higher_id_row_leaving_the_lower_id_twin_untouched(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant('offer-id-targeting-tenant');
        tenancy()->initialize($tenant);

        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();
        $store = Store::create(['name' => 'Amazon', 'slug' => 'amazon']);

        // Lower-id product/offer, category A.
        $productA = Product::factory()->create(['category_id' => $categoryA->id, 'status' => null]);
        $offerA = ProductOffer::create([
            'product_id' => $productA->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0DUPLICATE1',
            'raw_title'  => 'Duplicate Product Listing',
        ]);

        // Higher-id product/offer, category B — the structurally unreachable twin
        // before this fix (lowest-id-wins, no ordering).
        $productB = Product::factory()->create(['category_id' => $categoryB->id, 'status' => null]);
        $offerB = ProductOffer::create([
            'product_id' => $productB->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0DUPLICATE1',
            'raw_title'  => 'Duplicate Product Listing',
        ]);

        $this->assertGreaterThan($offerA->id, $offerB->id);

        tenancy()->end();
        $this->withMiddleware([InitializeTenancyFromPayload::class]);

        $this->withHeaders(['X-Tenant-Id' => $tenant->id])
            ->postJson('/api/extension/ingest-offer', $this->payload([
                'category_id' => $categoryB->id,
                'offer_id'    => $offerB->id,
            ]))
            ->assertOk()
            ->assertJson(['success' => true, 'action' => 'refreshed']);

        $offerA->refresh();
        $offerB->refresh();

        $this->assertNotNull($offerB->health_checked_at, 'the targeted higher-id offer must be health-stamped');
        $this->assertNull($offerA->health_checked_at, 'the lower-id twin must remain untouched — this is the whole point of Spec 033');
    }

    /** @test */
    public function offer_id_belonging_to_another_tenant_returns_422_never_a_silent_cross_tenant_write(): void
    {
        $tenantA = $this->makeTenant('offer-id-tenant-a');
        tenancy()->initialize($tenantA);
        $categoryA = Category::factory()->create();
        tenancy()->end();

        $tenantB = $this->makeTenant('offer-id-tenant-b');
        tenancy()->initialize($tenantB);
        $storeB = Store::create(['name' => 'Amazon', 'slug' => 'amazon']);
        $productB = Product::factory()->create(['category_id' => Category::factory()->create()->id, 'status' => null]);
        $offerB = ProductOffer::create([
            'product_id' => $productB->id,
            'store_id'   => $storeB->id,
            'url'        => 'https://www.amazon.com/dp/B0FOREIGN01',
            'raw_title'  => 'Foreign Tenant Offer',
        ]);
        tenancy()->end();

        $this->withMiddleware([InitializeTenancyFromPayload::class]);

        $this->withHeaders(['X-Tenant-Id' => $tenantA->id])
            ->postJson('/api/extension/ingest-offer', $this->payload([
                'category_id' => $categoryA->id,
                'offer_id'    => $offerB->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer_id']);

        // Never a silent cross-tenant write: tenant B's offer must remain untouched.
        $this->assertDatabaseHas('product_offers', [
            'id'                => $offerB->id,
            'health_checked_at' => null,
        ]);
    }

    /** @test */
    public function an_absent_offer_id_falls_back_to_the_url_lookup_exactly_as_before(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant('offer-id-absent-tenant');
        tenancy()->initialize($tenant);
        $category = Category::factory()->create();
        $store = Store::create(['name' => 'Amazon', 'slug' => 'amazon']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null]);
        $offer = ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://www.amazon.com/dp/B0SOLO000001',
            'raw_title'     => 'Solo Listing',
            'scraped_price' => 99.00,
        ]);
        tenancy()->end();

        $this->withMiddleware([InitializeTenancyFromPayload::class]);

        $this->withHeaders(['X-Tenant-Id' => $tenant->id])
            ->postJson('/api/extension/ingest-offer', $this->payload([
                'url'           => 'https://www.amazon.com/dp/B0SOLO000001',
                'category_id'   => $category->id,
                'scraped_price' => 149.00,
                // offer_id deliberately omitted — guards the ~1,000-offer existing flow.
            ]))
            ->assertOk()
            ->assertJson(['success' => true, 'action' => 'refreshed']);

        $offer->refresh();
        $this->assertEquals(149.00, (float) $offer->scraped_price);
        $this->assertNotNull($offer->health_checked_at);
    }

    /** @test */
    public function offer_id_whose_store_disagrees_with_store_slug_falls_back_to_url_and_logs_a_warning(): void
    {
        Queue::fake();
        Log::spy();

        $tenant = $this->makeTenant('offer-id-mismatch-tenant');
        tenancy()->initialize($tenant);
        $category = Category::factory()->create();

        $storeA = Store::create(['name' => 'Clive Coffee', 'slug' => 'clive-coffee']);
        $storeB = Store::create(['name' => 'Amazon', 'slug' => 'amazon']);

        // offer_id target belongs to store A — a stale work-list row (e.g. the
        // store was re-slugged mid-run).
        $productA = Product::factory()->create(['category_id' => $category->id, 'status' => null]);
        $mismatchedOffer = ProductOffer::create([
            'product_id' => $productA->id,
            'store_id'   => $storeA->id,
            'url'        => 'https://clivecoffee.com/products/mismatch-original',
            'raw_title'  => 'Mismatch Original',
        ]);

        // The payload's store_slug/url resolve to a DIFFERENT, genuine offer at store B.
        $productB = Product::factory()->create(['category_id' => $category->id, 'status' => null]);
        $urlMatchOffer = ProductOffer::create([
            'product_id'    => $productB->id,
            'store_id'      => $storeB->id,
            'url'           => 'https://www.amazon.com/dp/B0MISMATCH01',
            'raw_title'     => 'Mismatch URL Target',
            'scraped_price' => 50.00,
        ]);
        tenancy()->end();

        $this->withMiddleware([InitializeTenancyFromPayload::class]);

        $this->withHeaders(['X-Tenant-Id' => $tenant->id])
            ->postJson('/api/extension/ingest-offer', $this->payload([
                'url'           => 'https://www.amazon.com/dp/B0MISMATCH01',
                'store_slug'    => 'amazon',
                'category_id'   => $category->id,
                'offer_id'      => $mismatchedOffer->id,
                'scraped_price' => 75.00,
            ]))
            ->assertOk()
            ->assertJson(['success' => true, 'action' => 'refreshed']);

        $mismatchedOffer->refresh();
        $urlMatchOffer->refresh();

        $this->assertNull($mismatchedOffer->health_checked_at, 'the mismatched offer_id target must never be touched');
        $this->assertNotNull($urlMatchOffer->health_checked_at, 'the URL fallback target is the one actually refreshed');
        $this->assertEquals(75.00, (float) $urlMatchOffer->scraped_price);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => str_contains($message, 'offer_id store mismatch')
                && ($context['offer_id'] ?? null) === $mismatchedOffer->id)
            ->once();
    }

    /** @test */
    public function offer_id_pointing_at_a_nonexistent_offer_returns_422_at_validation(): void
    {
        $tenant = $this->makeTenant('offer-id-deleted-tenant');
        tenancy()->initialize($tenant);
        $category = Category::factory()->create();
        tenancy()->end();

        $this->withMiddleware([InitializeTenancyFromPayload::class]);

        $this->withHeaders(['X-Tenant-Id' => $tenant->id])
            ->postJson('/api/extension/ingest-offer', $this->payload([
                'category_id' => $category->id,
                'offer_id'    => 999999999,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offer_id']);
    }

    /** @test */
    public function url_lookup_matching_multiple_offers_logs_a_warning_naming_the_ids(): void
    {
        Queue::fake();
        Log::spy();

        $tenant = $this->makeTenant('offer-id-multi-match-tenant');
        tenancy()->initialize($tenant);
        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();
        $store = Store::create(['name' => 'Amazon', 'slug' => 'amazon']);

        $productA = Product::factory()->create(['category_id' => $categoryA->id, 'status' => null]);
        $offerA = ProductOffer::create([
            'product_id' => $productA->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0MULTIMATCH1',
            'raw_title'  => 'Duplicate Listing',
        ]);

        $productB = Product::factory()->create(['category_id' => $categoryB->id, 'status' => null]);
        $offerB = ProductOffer::create([
            'product_id' => $productB->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0MULTIMATCH1',
            'raw_title'  => 'Duplicate Listing',
        ]);
        tenancy()->end();

        $this->withMiddleware([InitializeTenancyFromPayload::class]);

        $this->withHeaders(['X-Tenant-Id' => $tenant->id])
            ->postJson('/api/extension/ingest-offer', $this->payload([
                'url'         => 'https://www.amazon.com/dp/B0MULTIMATCH1',
                'category_id' => $categoryB->id,
                // offer_id deliberately omitted — pure URL-fallback path.
            ]))
            ->assertOk();

        // Documented (not fixed by this spec), now-orderBy('id') behaviour: the
        // lowest id absorbs the update.
        $this->assertNotNull($offerA->fresh()->health_checked_at);
        $this->assertNull($offerB->fresh()->health_checked_at);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => str_contains($message, 'multiple offers')
                && in_array($offerA->id, $context['offer_ids'] ?? [], true)
                && in_array($offerB->id, $context['offer_ids'] ?? [], true))
            ->once();
    }
}
