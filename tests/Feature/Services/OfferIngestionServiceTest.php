<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\AuditLandingPageFreshnessJob;
use App\Jobs\ProcessPendingProduct;
use App\Jobs\RecalculateCategoryPriceTiers;
use App\Models\AiMatchingDecision;
use App\Models\Category;
use App\Models\Feature;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\OfferIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Spec 027 Addendum A §2b — OfferIngestionService::processIncomingOffer() server-side
 * condition guard (defense in depth alongside BatchImportController/ProductImportController).
 *
 * Spec 029 — full-field refresh semantics (A1), listing-health condition/high_price
 * handling (A2), and tier-recalc dispatch (A4).
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

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'url'           => 'https://example.com/product/1',
            'store_slug'    => 'example-store',
            'raw_title'     => 'Lelit Bianca V3 Dual Boiler Espresso Machine',
            'brand'         => '',
            'scraped_price' => 149.99,
            'image_url'     => null,
            'category_id'   => null,
            'rating'        => null,
            'reviews_count' => null,
        ], $overrides);
    }

    /** @test */
    public function a_renewed_raw_title_is_skipped_and_no_product_is_created(): void
    {
        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $service = app(OfferIngestionService::class);

        $result = $service->processIncomingOffer($this->basePayload([
            'raw_title'   => 'Logitech G915 TKL (Renewed)',
            'category_id' => $category->id,
        ]));

        $this->assertSame('skipped_condition', $result['action']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_offers', 0);
    }

    // =========================================================================
    // A1 — full-field refresh semantics
    // =========================================================================

    /** @test */
    public function refresh_updates_all_provided_fields_and_leaves_absent_fields_untouched(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null]);
        $offer   = ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/product/1',
            'scraped_price' => 99.00,
            'raw_title'     => 'Old Title',
            'image_url'     => 'https://example.com/old.jpg',
            'stock_status'  => 'in_stock',
        ]);

        $service = app(OfferIngestionService::class);

        // Payload omits image_url and stock_status entirely (not provided this scrape).
        $result = $service->processIncomingOffer($this->basePayload([
            'raw_title'     => 'New Title From Scraper',
            'scraped_price' => 129.99,
            'category_id'   => $category->id,
        ]));

        $this->assertSame('refreshed', $result['action']);

        $offer->refresh();
        $this->assertEquals(129.99, (float) $offer->scraped_price);
        $this->assertSame('New Title From Scraper', $offer->raw_title);
        // Absent fields must NOT be wiped.
        $this->assertSame('https://example.com/old.jpg', $offer->image_url);
        $this->assertSame('in_stock', $offer->stock_status);
    }

    /** @test */
    public function refresh_updates_image_url_and_stock_status_when_provided(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null]);
        $offer   = ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/product/1',
            'scraped_price' => 99.00,
            'raw_title'     => 'Old Title',
            'image_url'     => 'https://example.com/old.jpg',
            'stock_status'  => 'out_of_stock',
        ]);

        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'  => $category->id,
            'image_url'    => 'https://example.com/new.jpg',
            'stock_status' => 'in_stock',
        ]));

        $offer->refresh();
        $this->assertSame('https://example.com/new.jpg', $offer->image_url);
        $this->assertSame('in_stock', $offer->stock_status);
    }

    /** @test */
    public function raw_title_is_always_overwritten_on_refresh_even_when_it_heals_a_cleaned_title(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null, 'name' => 'Lelit Bianca V3']);
        // Simulates the structural blindness bug: raw_title equals the cleaned product name.
        $offer = ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/product/1',
            'scraped_price' => 99.00,
            'raw_title'     => 'Lelit Bianca V3', // cleaned, not the real raw listing title
        ]);

        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id' => $category->id,
            'raw_title'   => 'Lelit Bianca V3 Dual Boiler Espresso Machine - 2026 Model, Stainless Steel',
        ]));

        $offer->refresh();
        $this->assertSame(
            'Lelit Bianca V3 Dual Boiler Espresso Machine - 2026 Model, Stainless Steel',
            $offer->raw_title
        );
    }

    /** @test */
    public function reviews_count_refreshes_when_provided_and_is_left_untouched_when_omitted(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create([
            'category_id'          => $category->id,
            'status'                => null,
            'amazon_reviews_count' => 500,
        ]);
        ProductOffer::create([
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'url'        => 'https://example.com/product/1',
            'raw_title'  => 'Old Title',
        ]);

        // Omitted reviews_count must NOT reset the stored value.
        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'reviews_count' => null,
        ]));
        $this->assertSame(500, $product->fresh()->amazon_reviews_count);

        // Provided reviews_count (including a lower one) refreshes to the latest value.
        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'reviews_count' => 12,
        ]));
        $this->assertSame(12, $product->fresh()->amazon_reviews_count);
    }

    /** @test */
    public function a_freshly_created_product_stores_null_reviews_count_instead_of_zero(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'reviews_count' => null,
        ]));

        $product = Product::where('category_id', $category->id)->firstOrFail();
        $this->assertNull($product->amazon_reviews_count);
    }

    /** @test */
    public function updateOrCreate_prevents_a_duplicate_entry_crash_on_product_id_store_id(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $brand = \App\Models\Brand::factory()->create(['name' => 'Lelit']);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'brand_id'    => $brand->id,
            'name'        => 'Lelit Bianca V3',
            'status'      => null,
        ]);

        // Pre-seed AI memory so this resolves to the "matched" branch, and pre-seed an
        // offer from the SAME store the incoming payload will report — the exact
        // (product_id, store_id) collision Q1 protects against.
        \App\Models\AiMatchingDecision::create([
            'scraped_raw_name'    => 'Lelit Bianca V3 Dual Boiler Espresso Machine',
            'existing_product_id' => $product->id,
            'is_match'            => true,
        ]);
        $store = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        ProductOffer::create([
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'url'        => 'https://example.com/product/OLD-URL',
            'raw_title'  => 'Lelit Bianca V3',
        ]);

        // A different URL for the same product+store — would previously have crashed
        // any raw ::create() on the (product_id, store_id) unique constraint.
        $result = app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id' => $category->id,
            'url'         => 'https://example.com/product/NEW-URL',
            'brand'       => 'Lelit',
        ]));

        $this->assertSame('matched', $result['action']);
        $this->assertDatabaseCount('product_offers', 1);
        $this->assertDatabaseHas('product_offers', [
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'url'        => 'https://example.com/product/NEW-URL',
        ]);
    }

    /** @test */
    public function security_m2_a_poisoned_ai_match_cache_pointing_at_another_tenants_product_is_never_attached(): void
    {
        Queue::fake();

        // A product that genuinely belongs to a DIFFERENT tenant.
        Tenant::create(['id' => 'offer-ingestion-other-tenant', 'name' => 'Other Tenant']);
        $otherTenant = Tenant::find('offer-ingestion-other-tenant');
        tenancy()->end();
        tenancy()->initialize($otherTenant);
        $otherTenantCategory = Category::factory()->create();
        $otherTenantProduct  = Product::factory()->create(['category_id' => $otherTenantCategory->id]);
        tenancy()->end();

        // Back to THIS test's tenant.
        tenancy()->initialize($this->tenant);

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        // Poisoned/stale cache row: written under THIS tenant (so the tenant-scoped
        // cache lookup finds it), but its existing_product_id references the OTHER
        // tenant's product — exactly the corruption/regression scenario Security M2
        // guards against (a bare ->exists() check without a tenant_id filter would
        // have attached this tenant's offer to another tenant's product).
        AiMatchingDecision::create([
            'scraped_raw_name'    => 'Lelit Bianca V3 Dual Boiler Espresso Machine',
            'existing_product_id' => $otherTenantProduct->id,
            'is_match'            => true,
        ]);

        $result = app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id' => $category->id,
            'brand'       => 'Lelit',
        ]));

        // Must fall through to creating a brand-new product for THIS tenant instead
        // of attaching to the other tenant's product.
        $this->assertSame('created', $result['action']);
        $this->assertNotSame($otherTenantProduct->id, $result['product_id']);

        $product = Product::find($result['product_id']);
        $this->assertNotNull($product);
        $this->assertSame($this->tenant->id, $product->tenant_id);
    }

    // =========================================================================
    // A2 — listing-health (condition + listing_flags)
    // =========================================================================

    /** @test */
    public function each_negative_condition_flags_the_offer_and_ignores_the_product(): void
    {
        Queue::fake();

        foreach (['renewed', 'refurbished', 'open_box', 'used'] as $condition) {
            $category = Category::factory()->create();
            Feature::factory()->create(['category_id' => $category->id]);

            $result = app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
                'category_id' => $category->id,
                'url'         => "https://example.com/product/{$condition}",
                'condition'   => $condition,
            ]));

            $this->assertSame('flagged_condition', $result['action'], "condition={$condition}");

            $product = Product::find($result['product_id']);
            $this->assertTrue($product->is_ignored, "condition={$condition} must ignore the product");

            $offer = ProductOffer::where('product_id', $product->id)->first();
            $this->assertSame($condition, $offer->condition);
        }
    }

    /** @test */
    public function a_newly_flagged_landing_page_pick_logs_a_warning_naming_the_slug(): void
    {
        Queue::fake();
        Log::spy();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null, 'is_ignored' => false]);
        ProductOffer::create([
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'url'        => 'https://example.com/product/1',
            'raw_title'  => 'Old Title',
        ]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-espresso-machines',
            'picks'       => [['product_id' => $product->id, 'role' => 'overall']],
        ]);

        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id' => $category->id,
            'condition'   => 'renewed',
        ]));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => str_contains($message, 'landing-page pick')
                && in_array($page->slug, $context['landing_page_slugs'] ?? [], true))
            ->once();
    }

    /** @test */
    public function high_price_flags_the_offer_without_ignoring_the_product(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $result = app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'condition'     => 'new',
            'listing_flags' => ['high_price'],
        ]));

        $this->assertSame('created', $result['action']);

        $product = Product::find($result['product_id']);
        $this->assertFalse($product->is_ignored);

        $offer = ProductOffer::where('product_id', $product->id)->first();
        $this->assertSame(['high_price'], $offer->listing_flags);
        $this->assertSame('new', $offer->condition);
    }

    /** @test */
    public function unavailable_flags_the_offer_without_ignoring_the_product_and_coerces_stock_status(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        // Unavailable page: no price, no stock_status in the payload — the flag
        // alone must set the offer out_of_stock (Spec 029, 2026-08-12).
        $result = app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'scraped_price' => null,
            'condition'     => 'new',
            'listing_flags' => ['unavailable'],
        ]));

        $this->assertSame('created', $result['action']);

        $product = Product::find($result['product_id']);
        $this->assertFalse($product->is_ignored, 'unavailable is offer-level — the product stays visible');

        $offer = ProductOffer::where('product_id', $product->id)->first();
        $this->assertSame(['unavailable'], $offer->listing_flags);
        $this->assertSame('new', $offer->condition);
        $this->assertSame('out_of_stock', $offer->stock_status);
        $this->assertNotNull($offer->health_checked_at);
    }

    /** @test */
    public function an_explicit_payload_stock_status_is_never_overridden_by_the_unavailable_flag(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);

        $result = app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'condition'     => 'new',
            'listing_flags' => ['unavailable'],
            'stock_status'  => 'back_ordered',
        ]));

        $offer = ProductOffer::where('product_id', $result['product_id'])->first();
        $this->assertSame(['unavailable'], $offer->listing_flags);
        $this->assertSame('back_ordered', $offer->stock_status, 'the extension saw the page — an explicit stock_status wins');
    }

    /** @test */
    public function high_price_flag_on_a_landing_page_pick_dispatches_the_freshness_audit_job(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null, 'is_ignored' => false]);
        ProductOffer::create([
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'url'        => 'https://example.com/product/high-price',
            'raw_title'  => 'Old Title',
        ]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'picks'       => [['product_id' => $product->id, 'role' => 'overall']],
        ]);

        // Refresh path (same store/url as the existing offer) so ListingHealthService::apply()
        // runs against an already-picked product.
        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'url'           => 'https://example.com/product/high-price',
            'condition'     => 'new',
            'listing_flags' => ['high_price'],
        ]));

        Queue::assertPushed(AuditLandingPageFreshnessJob::class, function (AuditLandingPageFreshnessJob $job) use ($page) {
            $ref  = new \ReflectionClass($job);
            $prop = $ref->getProperty('landingPageId');
            $prop->setAccessible(true);

            return $prop->getValue($job) === $page->id;
        });
    }

    /** @test */
    public function a_clean_explicit_report_clears_condition_and_flags_and_stamps_health_checked_at(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null]);
        $offer   = ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/product/1',
            'raw_title'     => 'Old Title',
            'condition'     => 'renewed',
            'listing_flags' => ['high_price', 'unavailable'],
        ]);

        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'condition'     => 'new',
            'listing_flags' => [],
        ]));

        $offer->refresh();
        $this->assertSame('new', $offer->condition);
        $this->assertSame([], $offer->listing_flags, 'a clean rescan clears high_price AND unavailable — flags flip both ways');
        $this->assertNotNull($offer->health_checked_at);
    }

    /** @test */
    public function b3_an_unknown_report_is_stamp_only_stores_unknown_and_touches_nothing_else(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null]);
        $offer   = ProductOffer::create([
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'url'        => 'https://example.com/product/1',
            'raw_title'  => 'Old Title',
        ]);

        $result = app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'condition'     => 'unknown',
            'listing_flags' => [],
        ]));

        $this->assertSame('refreshed', $result['action'], 'unknown must never override the base action');

        $offer->refresh();
        $this->assertSame('unknown', $offer->condition, 'an "unknown" report must be stored as-is, never coerced to "new"');
        $this->assertNull($offer->listing_flags, 'stamp-only: an unverified page must not write flags');
        $this->assertNotNull($offer->health_checked_at, 'the stamp records that the extension looked');
    }

    /** @test */
    public function b3_an_unknown_report_never_clears_existing_flags_and_never_dispatches_the_freshness_audit(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null, 'is_ignored' => false]);
        $offer   = ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/product/unverified',
            'raw_title'     => 'Old Title',
            'condition'     => 'new',
            'listing_flags' => ['high_price'],
        ]);

        LandingPage::factory()->create([
            'category_id' => $category->id,
            'picks'       => [['product_id' => $product->id, 'role' => 'overall']],
        ]);

        // The extension could NOT verify this page (missing title / no buy-box) —
        // an 'unknown' report off it must not be treated as a clean rescan.
        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'url'           => 'https://example.com/product/unverified',
            'condition'     => 'unknown',
            'listing_flags' => [],
        ]));

        $offer->refresh();
        $this->assertSame(['high_price'], $offer->listing_flags, 'an unverified page must never wipe a previously stored flag');
        $this->assertSame('unknown', $offer->condition);
        $this->assertNotNull($offer->health_checked_at);
        $this->assertFalse($product->fresh()->is_ignored);

        Queue::assertNotPushed(AuditLandingPageFreshnessJob::class);
    }

    /** @test */
    public function b4_a_refurbished_raw_title_on_an_existing_offer_rescan_flags_the_canonical_condition_and_ignores_the_product(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null, 'is_ignored' => false]);
        $offer   = ProductOffer::create([
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'url'        => 'https://example.com/product/1',
            'raw_title'  => 'Jura E8 Espresso Machine',
        ]);

        // Rescan payload carries NO explicit `condition` — only the raw title marker.
        // 029B-B4: the raw marker 'refurbish' must be mapped to the CANONICAL
        // 'refurbished' before ListingHealthService::apply(); before the fix it
        // missed NEGATIVE_CONDITIONS and was stored verbatim via the clean branch
        // with the product left visible.
        $result = app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id' => $category->id,
            'raw_title'   => 'Refurbished Jura E8 Espresso Machine, Chrome',
        ]));

        $this->assertSame('flagged_condition', $result['action']);

        $offer->refresh();
        $this->assertSame('refurbished', $offer->condition, 'raw marker must be stored as canonical vocabulary, never verbatim');
        $this->assertTrue($product->fresh()->is_ignored);
    }

    /** @test */
    public function s1_a_clean_report_clearing_a_previously_set_high_price_flag_dispatches_the_freshness_audit_job(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null, 'is_ignored' => false]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/product/recovering',
            'raw_title'     => 'Old Title',
            'condition'     => 'new',
            'listing_flags' => ['high_price'],
        ]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'picks'       => [['product_id' => $product->id, 'role' => 'overall']],
        ]);

        // A clean explicit report clearing the previously-set high_price flag — the
        // listing recovered, so the page must be re-audited just as instantly as when
        // the flag was first set (both directions, S1).
        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'url'           => 'https://example.com/product/recovering',
            'condition'     => 'new',
            'listing_flags' => [],
        ]));

        Queue::assertPushed(AuditLandingPageFreshnessJob::class, function (AuditLandingPageFreshnessJob $job) use ($page) {
            $ref  = new \ReflectionClass($job);
            $prop = $ref->getProperty('landingPageId');
            $prop->setAccessible(true);

            return $prop->getValue($job) === $page->id;
        });
    }

    /** @test */
    public function absent_condition_leaves_listing_health_fields_untouched(): void
    {
        Queue::fake();

        $category = Category::factory()->create();
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null]);
        $offer   = ProductOffer::create([
            'product_id' => $product->id,
            'store_id'   => $store->id,
            'url'        => 'https://example.com/product/1',
            'raw_title'  => 'Old Title',
        ]);

        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id' => $category->id,
        ]));

        $offer->refresh();
        $this->assertNull($offer->condition);
        $this->assertNull($offer->listing_flags);
        $this->assertNull($offer->health_checked_at);
    }

    // =========================================================================
    // A4 — tier recalc dispatch
    // =========================================================================

    /** @test */
    public function a_price_refresh_on_an_existing_offer_dispatches_tier_recalc_once(): void
    {
        Queue::fake();

        $category = Category::factory()->create(['budget_max' => 50, 'midrange_max' => 150]);
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/product/1',
            'scraped_price' => 40,
            'raw_title'     => 'Old Title',
        ]);

        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'scraped_price' => 199.99,
        ]));

        Queue::assertPushed(RecalculateCategoryPriceTiers::class, 1);
    }

    /** @test */
    public function creating_a_brand_new_product_does_not_dispatch_tier_recalc(): void
    {
        Queue::fake();

        $category = Category::factory()->create(['budget_max' => 50, 'midrange_max' => 150]);
        Feature::factory()->create(['category_id' => $category->id]);

        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id' => $category->id,
        ]));

        Queue::assertNotPushed(RecalculateCategoryPriceTiers::class);
    }

    /** @test */
    public function perf_h1_a_refresh_with_an_unchanged_price_does_not_dispatch_tier_recalc(): void
    {
        Queue::fake();

        $category = Category::factory()->create(['budget_max' => 50, 'midrange_max' => 150]);
        Feature::factory()->create(['category_id' => $category->id]);
        $store   = Store::create(['name' => 'Example Store', 'slug' => 'example-store']);
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => null]);
        ProductOffer::create([
            'product_id'    => $product->id,
            'store_id'      => $store->id,
            'url'           => 'https://example.com/product/1',
            'scraped_price' => 149.99,
            'raw_title'     => 'Old Title',
        ]);

        // Same price as already stored — a redundant rescan must not queue a
        // category-wide recalc job (Perf H1 / Reviewer S5).
        app(OfferIngestionService::class)->processIncomingOffer($this->basePayload([
            'category_id'   => $category->id,
            'scraped_price' => 149.99,
        ]));

        Queue::assertNotPushed(RecalculateCategoryPriceTiers::class);
    }
}
