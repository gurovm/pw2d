<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Category;
use App\Models\Feature;
use App\Models\Tenant;
use App\Services\OfferIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 027 Addendum A §2b — OfferIngestionService::processIncomingOffer() server-side
 * condition guard (defense in depth alongside BatchImportController/ProductImportController).
 */
class OfferIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => 'offer-ingestion-tenant', 'name' => 'Offer Ingestion Tenant']);
        $this->tenant = Tenant::find('offer-ingestion-tenant');
        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    /** @test */
    public function a_renewed_raw_title_is_skipped_and_no_product_is_created(): void
    {
        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $service = app(OfferIngestionService::class);

        $result = $service->processIncomingOffer([
            'url'           => 'https://example.com/product/1',
            'store_slug'    => 'example-store',
            'raw_title'     => 'Logitech G915 TKL (Renewed)',
            'brand'         => '',
            'scraped_price' => 149.99,
            'image_url'     => null,
            'category_id'   => $category->id,
            'rating'        => null,
            'reviews_count' => null,
        ]);

        $this->assertSame('skipped_condition', $result['action']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_offers', 0);
    }
}
