<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializeTenancyFromPayload;
use App\Http\Middleware\VerifyExtensionToken;
use App\Jobs\ProcessPendingProduct;
use App\Jobs\RecalculateCategoryPriceTiers;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for POST /api/products/batch-import (BatchImportController).
 *
 * NOTE: Several tests that exercise the controller body are marked as requiring
 * MySQL because the duplicate-ASIN detection query uses SUBSTRING_INDEX, which
 * is a MySQL-only function not available in the SQLite in-memory test database.
 * Those tests will be skipped automatically on SQLite and run on MySQL CI/CD.
 */
class BatchImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([VerifyExtensionToken::class, InitializeTenancyFromPayload::class]);

        $this->category = Category::factory()->create([
            'budget_max'   => 50,
            'midrange_max' => 150,
        ]);
        Feature::factory()->count(3)->create(['category_id' => $this->category->id]);
    }

    /**
     * Skip the test if the current database driver is not MySQL.
     * The batch import controller uses SUBSTRING_INDEX (MySQL-only) for ASIN dedup.
     */
    private function requireMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'This test requires MySQL — SUBSTRING_INDEX is used for ASIN dedup and is unavailable on SQLite.'
            );
        }
    }

    /**
     * Build a valid batch import payload with sensible defaults.
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'products'    => [
                [
                    'asin'          => 'B0ABC12345',
                    'title'         => 'Sony WH-1000XM5 Wireless Headphones',
                    'price'         => 349.99,
                    'rating'        => 4.7,
                    'reviews_count' => 12500,
                    'image_url'     => 'https://m.media-amazon.com/images/I/test.jpg',
                ],
            ],
        ], $overrides);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Happy path (require MySQL — controller body uses SUBSTRING_INDEX)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function valid_batch_creates_products_offers_and_dispatches_jobs(): void
    {
        $this->requireMysql();
        Queue::fake();

        $response = $this->postJson('/api/products/batch-import', $this->validPayload());

        $response->assertOk()
            ->assertJson([
                'success'   => true,
                'created'   => 1,
                'refreshed' => 0,
            ]);

        $this->assertDatabaseHas('products', [
            'category_id' => $this->category->id,
            'status'      => 'pending_ai',
            'is_ignored'  => false,
        ]);

        $this->assertDatabaseHas('product_offers', [
            'url' => 'https://www.amazon.com/dp/B0ABC12345',
        ]);

        $this->assertDatabaseHas('stores', [
            'slug' => 'amazon',
        ]);

        Queue::assertPushed(ProcessPendingProduct::class);
    }

    /** @test */
    public function batch_creates_multiple_products_and_dispatches_one_job_per_product(): void
    {
        $this->requireMysql();
        Queue::fake();

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'  => 'B0AAA00001',
                    'title' => 'Product Alpha Wireless Headphones',
                    'price' => 199.99,
                ],
                [
                    'asin'  => 'B0AAA00002',
                    'title' => 'Product Beta Over-Ear Headphones',
                    'price' => 299.99,
                ],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['created' => 2, 'refreshed' => 0]);

        $this->assertDatabaseCount('products', 2);
        Queue::assertPushed(ProcessPendingProduct::class, 2);
    }

    /** @test */
    public function amazon_store_is_auto_created_on_first_import(): void
    {
        $this->requireMysql();
        Queue::fake();

        $this->assertDatabaseMissing('stores', ['slug' => 'amazon']);

        $this->postJson('/api/products/batch-import', $this->validPayload());

        $this->assertDatabaseHas('stores', ['slug' => 'amazon', 'name' => 'Amazon']);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Duplicate ASIN handling (require MySQL)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function duplicate_asin_refreshes_existing_offer_and_does_not_create_new_product(): void
    {
        $this->requireMysql();
        Queue::fake();

        // Pre-seed an existing product with an Amazon offer for the same ASIN
        $store = Store::create([
            'tenant_id' => $this->category->tenant_id,
            'slug'      => 'amazon',
            'name'      => 'Amazon',
        ]);

        $existingProduct = Product::factory()->create([
            'category_id' => $this->category->id,
            'status'      => null,
            'name'        => 'Old Product Name',
        ]);

        ProductOffer::create([
            'tenant_id'     => $this->category->tenant_id,
            'product_id'    => $existingProduct->id,
            'store_id'      => $store->id,
            'url'           => 'https://www.amazon.com/dp/B0ABC12345',
            'scraped_price' => 299.99,
            'raw_title'     => 'Old Product Name',
        ]);

        $response = $this->postJson('/api/products/batch-import', $this->validPayload());

        $response->assertOk()->assertJson([
            'success'   => true,
            'created'   => 0,
            'refreshed' => 1,
        ]);

        // No new product was created — still exactly 1
        $this->assertDatabaseCount('products', 1);

        // Offer price was updated to the new scraped value
        $this->assertDatabaseHas('product_offers', [
            'url'           => 'https://www.amazon.com/dp/B0ABC12345',
            'scraped_price' => 349.99,
        ]);

        // No AI job dispatched for a mere price refresh
        Queue::assertNothingPushed();
    }

    /** @test */
    public function duplicate_asin_with_empty_price_and_no_other_evidence_leaves_product_and_offer_untouched(): void
    {
        $this->requireMysql();
        Queue::fake();

        // Regression: live incident 2026-08-16, product 3813 (1Zpresso K-Ultra) —
        // the OLD "dead-listing heuristic" silently set is_ignored=true here on a
        // healthy, $259, condition-new, that-morning-health-verified PREMIUM pick,
        // on nothing but a missing SERP-tile price (a routine, weak, non-authoritative
        // signal — sponsored placement / "see price in cart" / variant-priced parent /
        // plain extraction miss). The asymmetry (product ignored, offer completely
        // clean) was the diagnostic signature of the bug. Fix: a missing SERP price
        // for an existing product with no other evidence now leaves BOTH the product
        // and its offer completely untouched — the product-page listing-health path
        // (condition/listing_flags/stock_status) is the sole owner of availability.
        $store = Store::create([
            'tenant_id' => $this->category->tenant_id,
            'slug'      => 'amazon',
            'name'      => 'Amazon',
        ]);

        $existingProduct = Product::factory()->create([
            'category_id'   => $this->category->id,
            'status'        => null,
            'is_ignored'    => false,
            'amazon_rating' => 4.8,
        ]);

        $healthCheckedAt = now()->subHours(2);
        $existingOffer = ProductOffer::create([
            'tenant_id'         => $this->category->tenant_id,
            'product_id'        => $existingProduct->id,
            'store_id'          => $store->id,
            'url'               => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'         => 'Some Product',
            'scraped_price'     => 259.00,
            'condition'         => 'new',
            'health_checked_at' => $healthCheckedAt,
        ]);

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'  => 'B0ABC12345',
                    'title' => 'Some Product (New Title From SERP)',
                    'price' => null, // SERP tile omitted the price — weak/routine, not authoritative
                ],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['refreshed' => 0, 'skipped' => 1, 'flagged' => 0]);

        // Product is completely untouched — still visible.
        $this->assertDatabaseHas('products', [
            'id'            => $existingProduct->id,
            'is_ignored'    => false,
            'amazon_rating' => 4.8,
        ]);

        // Offer is completely untouched — price, condition, raw_title, and health
        // stamp all survive exactly as they were before this SERP row arrived.
        $offer = $existingOffer->fresh();
        $this->assertSame('Some Product', $offer->raw_title);
        $this->assertEquals(259.00, $offer->scraped_price);
        $this->assertSame('new', $offer->condition);
        $this->assertEquals($healthCheckedAt->timestamp, $offer->health_checked_at->timestamp);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function duplicate_asin_with_empty_price_but_a_legitimate_price_still_refreshes_other_fields(): void
    {
        $this->requireMysql();
        Queue::fake();

        // Companion to the test above: a no-price SERP row must not become a
        // blanket "do nothing" for existing products — a row that DOES carry
        // corroborating condition/flag evidence still falls through to the
        // normal refresh path and heals the offer (covered in depth by the
        // existing "no_price_refresh_with_a_negative_condition..." and
        // "...only_a_recognized_flag..." tests below). This test instead proves
        // the untouched-path is narrowly scoped to price alone: a row that DOES
        // carry a price still refreshes the offer/product normally, even when
        // other optional fields are混omitted.
        $store = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'amazon', 'name' => 'Amazon']);
        $existingProduct = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);
        ProductOffer::create([
            'tenant_id'     => $this->category->tenant_id,
            'product_id'    => $existingProduct->id,
            'store_id'      => $store->id,
            'url'           => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'     => 'Old Title',
            'scraped_price' => 199.99,
        ]);

        $payload = $this->validPayload([
            'products' => [
                ['asin' => 'B0ABC12345', 'title' => 'New Title From SERP', 'price' => 229.99],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['refreshed' => 1, 'skipped' => 0]);

        $this->assertDatabaseHas('product_offers', [
            'product_id'    => $existingProduct->id,
            'raw_title'     => 'New Title From SERP',
            'scraped_price' => 229.99,
        ]);

        Queue::assertPushed(RecalculateCategoryPriceTiers::class);
    }

    /** @test */
    public function unavailable_flag_on_product_page_rescan_still_marks_the_offer_unpurchasable_replacing_the_removed_serp_heuristic(): void
    {
        $this->requireMysql();
        Queue::fake();

        // Proves the replacement mechanism: a genuinely unavailable listing is
        // still caught — just via the authoritative product-page `unavailable`
        // flag (Spec 029 listing-health), never via a SERP tile's missing price.
        // ListingHealth::isPurchasable() (used by bestOffer/picks/compare) reads
        // this exact shape as unpurchasable even though the product itself stays
        // visible (Spec 029: flags are offer-level, point-in-time state).
        $store = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'amazon', 'name' => 'Amazon']);
        $existingProduct = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);
        $existingOffer = ProductOffer::create([
            'tenant_id'     => $this->category->tenant_id,
            'product_id'    => $existingProduct->id,
            'store_id'      => $store->id,
            'url'           => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'     => 'Some Product',
            'scraped_price' => 259.00,
            'condition'     => 'new',
        ]);

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'          => 'B0ABC12345',
                    'title'         => 'Some Product',
                    'price'         => null,
                    'condition'     => 'new',
                    'listing_flags' => ['unavailable'],
                ],
            ],
        ]);

        $this->postJson('/api/products/batch-import', $payload)->assertOk()->assertJson(['refreshed' => 1]);

        $offer = $existingOffer->fresh();
        $this->assertSame(['unavailable'], $offer->listing_flags);
        $this->assertSame('out_of_stock', $offer->stock_status);
        $this->assertFalse(\App\Support\ListingHealth::isPurchasable($offer));

        // Product-level: stays visible (a different, still-purchasable offer isn't
        // required here — `unavailable` is offer-level per Spec 029, never
        // product-level like the old SERP heuristic was).
        $this->assertDatabaseHas('products', ['id' => $existingProduct->id, 'is_ignored' => false]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Price filtering — suspiciously cheap items are skipped (require MySQL)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function new_product_below_half_of_budget_max_is_skipped(): void
    {
        $this->requireMysql();
        Queue::fake();

        // Category budget_max is 50, so the cheap-item threshold is 50 * 0.5 = $25
        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'  => 'B0CHEAP001',
                    'title' => 'Suspiciously Cheap Cable or Accessory',
                    'price' => 10.00, // below $25 threshold
                ],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['created' => 0]);
        $this->assertDatabaseCount('products', 0);
        Queue::assertNothingPushed();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Condition-marker guard (Spec 027 Addendum A §2b) — require MySQL
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function new_product_with_a_renewed_title_is_skipped_not_created(): void
    {
        $this->requireMysql();
        Queue::fake();

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'  => 'B0RENEWED1',
                    'title' => 'Logitech G915 TKL (Renewed)',
                    'price' => 149.99,
                ],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson([
            'success' => true,
            'created' => 0,
            'skipped' => 1,
        ]);

        $this->assertDatabaseCount('products', 0);
        Queue::assertNothingPushed();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Spec 029 — listing-health (condition + listing_flags) — require MySQL
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function a_negative_condition_on_a_new_product_flags_it_without_dispatching_ai(): void
    {
        $this->requireMysql();
        Queue::fake();

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'      => 'B0RENEWED2',
                    'title'     => 'A Clean-Sounding Title With No Marker',
                    'price'     => 149.99,
                    'condition' => 'renewed',
                ],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['created' => 1, 'flagged' => 1]);

        $this->assertDatabaseHas('products', ['is_ignored' => true]);
        $this->assertDatabaseHas('product_offers', ['condition' => 'renewed']);
        Queue::assertNothingPushed();
    }

    /** @test */
    public function high_price_flag_on_a_refreshed_offer_does_not_ignore_the_product(): void
    {
        $this->requireMysql();
        Queue::fake();

        $store = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'amazon', 'name' => 'Amazon']);
        $existingProduct = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);
        ProductOffer::create([
            'tenant_id'  => $this->category->tenant_id,
            'product_id' => $existingProduct->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Product Name',
        ]);

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'          => 'B0ABC12345',
                    'title'         => 'Old Product Name',
                    'price'         => 349.99,
                    'condition'     => 'new',
                    'listing_flags' => ['high_price'],
                ],
            ],
        ]);

        $this->postJson('/api/products/batch-import', $payload)->assertOk();

        $this->assertDatabaseHas('products', ['id' => $existingProduct->id, 'is_ignored' => false]);
        $this->assertDatabaseHas('product_offers', [
            'product_id'    => $existingProduct->id,
            'condition'     => 'new',
            'listing_flags' => json_encode(['high_price']),
        ]);
    }

    /** @test */
    public function unavailable_flag_with_no_price_refreshes_the_offer_and_coerces_stock_without_delisting(): void
    {
        $this->requireMysql();
        Queue::fake();

        $store = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'amazon', 'name' => 'Amazon']);
        $existingProduct = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);
        ProductOffer::create([
            'tenant_id'    => $this->category->tenant_id,
            'product_id'   => $existingProduct->id,
            'store_id'     => $store->id,
            'url'          => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'    => 'Old Product Name',
            'stock_status' => 'in_stock',
        ]);

        // A "Currently unavailable" page: null price, no explicit stock_status.
        // The pre-existing empty-price delisting heuristic must NOT fire — Spec 029:
        // `unavailable` is offer-level, the product stays visible, and the flag
        // alone coerces the offer to out_of_stock.
        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'          => 'B0ABC12345',
                    'title'         => 'Old Product Name',
                    'price'         => null,
                    'condition'     => 'new',
                    'listing_flags' => ['unavailable'],
                ],
            ],
        ]);

        $this->postJson('/api/products/batch-import', $payload)->assertOk()->assertJson(['refreshed' => 1]);

        $this->assertDatabaseHas('products', ['id' => $existingProduct->id, 'is_ignored' => false]);
        $this->assertDatabaseHas('product_offers', [
            'product_id'    => $existingProduct->id,
            'condition'     => 'new',
            'listing_flags' => json_encode(['unavailable']),
            'stock_status'  => 'out_of_stock',
        ]);
    }

    /** @test */
    public function no_price_refresh_with_a_negative_condition_heals_the_offer_and_is_flagged_not_silently_delisted(): void
    {
        $this->requireMysql();
        Queue::fake();

        // Regression: live QA, product 1744 (HyperX Cloud Alpha Wireless,
        // ASIN B09Z6PM1PV) — a RENEWED re-listing with no extractable price used
        // to hit the dead-listing heuristic (wrong reason for is_ignored) and
        // `continue` before the offer heal / health stamp / ListingHealthService
        // ran. It must now fall through to the normal refresh path instead.
        $store = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'amazon', 'name' => 'Amazon']);
        $existingProduct = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);
        $existingOffer = ProductOffer::create([
            'tenant_id'  => $this->category->tenant_id,
            'product_id' => $existingProduct->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B09Z6PM1PV',
            'raw_title'  => 'HyperX Cloud Alpha Wireless',
        ]);

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'      => 'B09Z6PM1PV',
                    'title'     => 'HyperX Cloud Alpha Wireless (Renewed)',
                    'price'     => null,
                    'condition' => 'renewed',
                ],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['flagged' => 1]);

        $this->assertDatabaseHas('products', ['id' => $existingProduct->id, 'is_ignored' => true]);

        $offer = $existingOffer->fresh();
        $this->assertSame('renewed', $offer->condition);
        $this->assertSame('HyperX Cloud Alpha Wireless (Renewed)', $offer->raw_title);
        $this->assertNotNull($offer->health_checked_at);

        // Falls through to the normal existing-offer refresh path (unlike the
        // dead-listing heuristic's early `continue`), so the standard A4 tier
        // recalc dispatch fires — this is NOT a brand-new product, so no AI job.
        Queue::assertPushed(RecalculateCategoryPriceTiers::class);
        Queue::assertNotPushed(ProcessPendingProduct::class);
    }

    /** @test */
    public function no_price_refresh_with_only_a_recognized_flag_is_not_delisted_and_stores_the_flag(): void
    {
        $this->requireMysql();
        Queue::fake();

        // Regression: the pre-fix heuristic only special-cased `unavailable` —
        // any OTHER recognized flag (e.g. `high_price`) paired with a no-price
        // payload was still wrongly delisted as a "dead listing".
        $store = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'amazon', 'name' => 'Amazon']);
        $existingProduct = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);
        $existingOffer = ProductOffer::create([
            'tenant_id'  => $this->category->tenant_id,
            'product_id' => $existingProduct->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Product Name',
        ]);

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'          => 'B0ABC12345',
                    'title'         => 'Old Product Name',
                    'price'         => null,
                    'condition'     => 'new',
                    'listing_flags' => ['high_price'],
                ],
            ],
        ]);

        $this->postJson('/api/products/batch-import', $payload)->assertOk();

        $this->assertDatabaseHas('products', ['id' => $existingProduct->id, 'is_ignored' => false]);

        // Array-cast assertion (not assertDatabaseHas) — a raw `=` comparison of a
        // native MySQL `json` column against a string-encoded value is unreliable
        // (see docs/questions.md); comparing the hydrated/cast PHP array is exact
        // on every DB driver.
        $offer = $existingOffer->fresh();
        $this->assertSame('new', $offer->condition);
        $this->assertSame(['high_price'], $offer->listing_flags);
    }

    /** @test */
    public function b4_an_open_box_title_on_an_existing_offer_rescan_maps_to_canonical_condition_and_ignores_the_product(): void
    {
        $this->requireMysql();
        Queue::fake();

        $store = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'amazon', 'name' => 'Amazon']);
        $existingProduct = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);
        ProductOffer::create([
            'tenant_id'  => $this->category->tenant_id,
            'product_id' => $existingProduct->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Product Name',
        ]);

        // Rescan payload: NO explicit `condition` — the raw title carries an
        // "Open Box" marker. 029B-B4: the raw marker 'open box' must map to the
        // canonical 'open_box'; before the fix it missed NEGATIVE_CONDITIONS and
        // was stored verbatim via the clean branch with the product left visible.
        $payload = $this->validPayload([
            'products' => [
                ['asin' => 'B0ABC12345', 'title' => 'Sony WH-1000XM5 (Open Box)', 'price' => 279.99],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['refreshed' => 1, 'flagged' => 1]);

        $this->assertDatabaseHas('products', ['id' => $existingProduct->id, 'is_ignored' => true]);
        $this->assertDatabaseHas('product_offers', [
            'product_id' => $existingProduct->id,
            'condition'  => 'open_box',
        ]);
    }

    /** @test */
    public function fix1_a_refurbished_title_wins_over_an_explicit_payload_new_and_ignores_the_products_only_offer(): void
    {
        $this->requireMysql();
        Queue::fake();

        $store = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'amazon', 'name' => 'Amazon']);
        $existingProduct = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);
        $existingOffer = ProductOffer::create([
            'tenant_id'  => $this->category->tenant_id,
            'product_id' => $existingProduct->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Delonghi ECAM22110SB Magnifica XS',
        ]);

        // Client wrongly reports 'new' — the title marker must win.
        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'      => 'B0ABC12345',
                    'title'     => 'Refurbished Delonghi ECAM22110SB Magnifica XS',
                    'price'     => 349.99,
                    'condition' => 'new',
                ],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['refreshed' => 1, 'flagged' => 1]);

        $existingOffer->refresh();
        $this->assertSame('refurbished', $existingOffer->condition);
        $this->assertTrue($existingProduct->fresh()->is_ignored, 'the only offer went bad — product must be ignored');
    }

    /** @test */
    public function fix2_a_negative_condition_on_one_offer_leaves_the_product_visible_when_another_offer_is_clean(): void
    {
        $this->requireMysql();
        Queue::fake();

        $existingProduct = Product::factory()->create(['category_id' => $this->category->id, 'status' => null, 'is_ignored' => false]);

        // A clean, priced, non-Amazon offer that must survive this refresh.
        $otherStore = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'whole-latte-love', 'name' => 'Whole Latte Love']);
        ProductOffer::create([
            'tenant_id'     => $this->category->tenant_id,
            'product_id'    => $existingProduct->id,
            'store_id'      => $otherStore->id,
            'url'           => 'https://example.com/wll-offer',
            'raw_title'     => 'Sony WH-1000XM5 Wireless Headphones',
            'scraped_price' => 279.99,
            'condition'     => 'new',
        ]);

        $amazonStore = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'amazon', 'name' => 'Amazon']);
        $amazonOffer = ProductOffer::create([
            'tenant_id'  => $this->category->tenant_id,
            'product_id' => $existingProduct->id,
            'store_id'   => $amazonStore->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Product Name',
        ]);

        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'      => 'B0ABC12345',
                    'title'     => 'Old Product Name',
                    'price'     => 349.99,
                    'condition' => 'refurbished',
                ],
            ],
        ]);

        $response = $this->postJson('/api/products/batch-import', $payload);

        $response->assertOk()->assertJson(['refreshed' => 1, 'flagged' => 1]);

        $amazonOffer->refresh();
        $this->assertSame('refurbished', $amazonOffer->condition);
        $this->assertFalse($existingProduct->fresh()->is_ignored, 'the WLL offer is still clean — product must stay visible');
    }

    /** @test */
    public function s2_a_rating_less_refresh_does_not_wipe_a_previously_known_rating(): void
    {
        $this->requireMysql();
        Queue::fake();

        $store = Store::create(['tenant_id' => $this->category->tenant_id, 'slug' => 'amazon', 'name' => 'Amazon']);
        $existingProduct = Product::factory()->create([
            'category_id'   => $this->category->id,
            'status'        => null,
            'amazon_rating' => 4.5,
        ]);
        ProductOffer::create([
            'tenant_id'  => $this->category->tenant_id,
            'product_id' => $existingProduct->id,
            'store_id'   => $store->id,
            'url'        => 'https://www.amazon.com/dp/B0ABC12345',
            'raw_title'  => 'Old Product Name',
        ]);

        // Rescan payload omits `rating` entirely — must NOT null out the known 4.5.
        $payload = $this->validPayload([
            'products' => [
                ['asin' => 'B0ABC12345', 'title' => 'Old Product Name', 'price' => 349.99],
            ],
        ]);

        $this->postJson('/api/products/batch-import', $payload)->assertOk();

        $this->assertEquals(4.5, $existingProduct->fresh()->amazon_rating);
    }

    /** @test */
    public function invalid_condition_value_returns_422(): void
    {
        $payload = $this->validPayload([
            'products' => [
                ['asin' => 'B0BADCOND1', 'title' => 'Some Product', 'price' => 99.99, 'condition' => 'mint'],
            ],
        ]);

        $this->postJson('/api/products/batch-import', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.condition']);
    }

    /** @test */
    public function unrecognized_listing_flag_returns_422(): void
    {
        $payload = $this->validPayload([
            'products' => [
                ['asin' => 'B0BADFLAG1', 'title' => 'Some Product', 'price' => 99.99, 'listing_flags' => ['made_up_flag']],
            ],
        ]);

        $this->postJson('/api/products/batch-import', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.listing_flags.0']);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Validation failures — these never reach the SUBSTRING_INDEX query
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function missing_category_id_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['category_id']);

        $this->postJson('/api/products/batch-import', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    /** @test */
    public function nonexistent_category_id_returns_422(): void
    {
        $this->postJson('/api/products/batch-import', $this->validPayload(['category_id' => 99999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    /** @test */
    public function missing_products_array_returns_422(): void
    {
        $this->postJson('/api/products/batch-import', ['category_id' => $this->category->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products']);
    }

    /** @test */
    public function empty_products_array_returns_422(): void
    {
        $this->postJson('/api/products/batch-import', $this->validPayload(['products' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products']);
    }

    /** @test */
    public function product_missing_asin_returns_422(): void
    {
        $payload = $this->validPayload([
            'products' => [['title' => 'No ASIN Product', 'price' => 99.99]],
        ]);

        $this->postJson('/api/products/batch-import', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.asin']);
    }

    /** @test */
    public function product_missing_title_returns_422(): void
    {
        $payload = $this->validPayload([
            'products' => [['asin' => 'B0NOTITLE1', 'price' => 99.99]],
        ]);

        $this->postJson('/api/products/batch-import', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.title']);
    }

    /** @test */
    public function product_with_invalid_rating_returns_422(): void
    {
        $payload = $this->validPayload([
            'products' => [
                [
                    'asin'   => 'B0BADRATE1',
                    'title'  => 'Bad Rating Product',
                    'price'  => 99.99,
                    'rating' => 9.9, // above max of 5
                ],
            ],
        ]);

        $this->postJson('/api/products/batch-import', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['products.0.rating']);
    }

    /** @test */
    public function category_with_no_features_returns_400(): void
    {
        // This test does NOT require MySQL — the features check happens before the
        // SUBSTRING_INDEX dedup query is executed
        Queue::fake();

        $emptyCategory = Category::factory()->create();

        $this->postJson('/api/products/batch-import', $this->validPayload(['category_id' => $emptyCategory->id]))
            ->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error'   => 'No Features',
            ]);

        Queue::assertNothingPushed();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Auth — extension token middleware (short-circuits before controller body)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function request_without_extension_token_returns_403(): void
    {
        $this->withMiddleware([VerifyExtensionToken::class]);
        config(['services.extension.token' => 'valid-token']);

        $this->postJson('/api/products/batch-import', $this->validPayload())
            ->assertStatus(403)
            ->assertJson(['error' => 'Unauthorized.']);
    }

    /** @test */
    public function request_with_wrong_extension_token_returns_403(): void
    {
        $this->withMiddleware([VerifyExtensionToken::class]);
        config(['services.extension.token' => 'correct-token']);

        $this->postJson('/api/products/batch-import', $this->validPayload(), [
            'X-Extension-Token' => 'wrong-token',
        ])
            ->assertStatus(403)
            ->assertJson(['error' => 'Unauthorized.']);
    }

    /** @test */
    public function request_with_valid_extension_token_is_not_blocked_by_auth(): void
    {
        // Only testing that the valid token passes the 403 gate.
        // This test still requires MySQL because the controller body uses SUBSTRING_INDEX.
        $this->requireMysql();
        Queue::fake();

        // Re-enable token middleware only; keep tenancy middleware bypassed
        $this->withMiddleware([VerifyExtensionToken::class]);
        $this->withoutMiddleware([InitializeTenancyFromPayload::class]);
        config(['services.extension.token' => 'valid-token']);

        $this->postJson('/api/products/batch-import', $this->validPayload(), [
            'X-Extension-Token' => 'valid-token',
        ])
            ->assertStatus(200);
    }
}
