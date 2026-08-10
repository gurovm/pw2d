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
use Tests\TestCase;

/**
 * Spec 029 §A3 — GET /api/extension/rescan-list?category_id={id}.
 */
class RescanListControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerifyExtensionToken::class, InitializeTenancyFromPayload::class]);
    }

    private function makeOffer(Category $category, string $slug, ?string $healthCheckedAt = null): ProductOffer
    {
        $store   = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $category->tenant_id], ['name' => 'Amazon']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null, 'is_ignored' => false]);

        return ProductOffer::create([
            'tenant_id'         => $category->tenant_id,
            'product_id'        => $product->id,
            'store_id'          => $store->id,
            'url'               => "https://www.amazon.com/dp/{$slug}",
            'raw_title'         => $product->name,
            'health_checked_at' => $healthCheckedAt,
        ]);
    }

    /** @test */
    public function it_returns_offers_for_the_categorys_non_ignored_processed_products(): void
    {
        $category = Category::factory()->create();
        $offer    = $this->makeOffer($category, 'B0GOOD00001');

        $ignoredProduct = Product::factory()->create(['category_id' => $category->id, 'status' => null, 'is_ignored' => true]);
        ProductOffer::create([
            'tenant_id'  => $category->tenant_id,
            'product_id' => $ignoredProduct->id,
            'store_id'   => Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $category->tenant_id], ['name' => 'Amazon'])->id,
            'url'        => 'https://www.amazon.com/dp/B0IGNORED1',
            'raw_title'  => $ignoredProduct->name,
        ]);

        $pendingProduct = Product::factory()->create(['category_id' => $category->id, 'status' => 'pending_ai']);
        ProductOffer::create([
            'tenant_id'  => $category->tenant_id,
            'product_id' => $pendingProduct->id,
            'store_id'   => Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $category->tenant_id], ['name' => 'Amazon'])->id,
            'url'        => 'https://www.amazon.com/dp/B0PENDING1',
            'raw_title'  => $pendingProduct->name,
        ]);

        $response = $this->getJson("/api/extension/rescan-list?category_id={$category->id}");

        $response->assertOk();
        $offers = $response->json('offers');

        $this->assertCount(1, $offers);
        $this->assertSame($offer->id, $offers[0]['offer_id']);
        $this->assertSame($offer->product_id, $offers[0]['product_id']);
        $this->assertSame('https://www.amazon.com/dp/B0GOOD00001', $offers[0]['url']);
        $this->assertSame('B0GOOD00001', $offers[0]['asin']);
    }

    /** @test */
    public function it_orders_offers_oldest_health_checked_at_first_with_never_checked_offers_by_updated_at(): void
    {
        $category = Category::factory()->create();

        $recentlyChecked = $this->makeOffer($category, 'B0RECENT001', now()->subDay()->toDateTimeString());
        $staleChecked     = $this->makeOffer($category, 'B0STALE0001', now()->subMonth()->toDateTimeString());
        $neverChecked     = $this->makeOffer($category, 'B0NEVER0001', null);
        // Force a distinguishable, old updated_at for the never-checked offer so it
        // sorts first (oldest) ahead of the stale-but-checked offer.
        $neverChecked->forceFill(['updated_at' => now()->subYear()])->saveQuietly();

        $response = $this->getJson("/api/extension/rescan-list?category_id={$category->id}");

        $ids = collect($response->json('offers'))->pluck('offer_id')->all();

        $this->assertSame([
            $neverChecked->id,
            $staleChecked->id,
            $recentlyChecked->id,
        ], $ids);
    }

    /** @test */
    public function it_does_not_leak_offers_across_tenants(): void
    {
        Tenant::create(['id' => 'rescan-tenant-a', 'name' => 'Tenant A']);
        $tenantA = Tenant::find('rescan-tenant-a');
        tenancy()->initialize($tenantA);
        $categoryA = Category::factory()->create();
        $offerA    = $this->makeOffer($categoryA, 'B0TENANTA01');
        tenancy()->end();

        Tenant::create(['id' => 'rescan-tenant-b', 'name' => 'Tenant B']);
        $tenantB = Tenant::find('rescan-tenant-b');
        tenancy()->initialize($tenantB);
        $categoryB = Category::factory()->create();
        $this->makeOffer($categoryB, 'B0TENANTB01');
        tenancy()->end();

        // Re-enable the real tenancy-resolving middleware (VerifyExtensionToken stays
        // disabled from setUp) — mirrors the sibling endpoints' own token-based tenant
        // resolution exactly (X-Tenant-Id header, same as OfferIngestionController).
        $this->withMiddleware([InitializeTenancyFromPayload::class]);

        // Authenticate as tenant A, but request tenant B's category_id — the category_id
        // validation rule is scoped to the REQUESTING tenant (tenant('id')), so a
        // cross-tenant category_id must fail validation, never leak tenant B's offers.
        $this->withHeaders(['X-Tenant-Id' => $tenantA->id])
            ->getJson("/api/extension/rescan-list?category_id={$categoryB->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);

        // Tenant A's own category still correctly resolves to tenant A's own offer.
        $this->withHeaders(['X-Tenant-Id' => $tenantA->id])
            ->getJson("/api/extension/rescan-list?category_id={$categoryA->id}")
            ->assertOk()
            ->assertJsonPath('offers.0.offer_id', $offerA->id)
            ->assertJsonCount(1, 'offers');
    }

    /** @test */
    public function it_returns_403_without_a_valid_extension_token(): void
    {
        $this->withMiddleware([VerifyExtensionToken::class]);
        config(['services.extension.token' => 'valid-token']);

        $category = Category::factory()->create();

        $this->getJson("/api/extension/rescan-list?category_id={$category->id}")
            ->assertStatus(403)
            ->assertJson(['error' => 'Unauthorized.']);
    }

    /** @test */
    public function missing_category_id_returns_422(): void
    {
        $this->getJson('/api/extension/rescan-list')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }
}
