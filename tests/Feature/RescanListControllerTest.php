<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\InitializeTenancyFromPayload;
use App\Http\Middleware\VerifyExtensionToken;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 029 §A3 / Spec 031 — GET /api/extension/rescan-list?category_id={id}
 * (scope=category, default) and ?scope=picks (Spec 031).
 */
class RescanListControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerifyExtensionToken::class, InitializeTenancyFromPayload::class]);
    }

    private function makeOffer(
        Category $category,
        string $slug,
        ?string $healthCheckedAt = null,
        array $productAttributes = [],
    ): ProductOffer {
        $store   = Store::firstOrCreate(['slug' => 'amazon', 'tenant_id' => $category->tenant_id], ['name' => 'Amazon']);
        $product = Product::factory()->create(array_merge([
            'category_id' => $category->id,
            'status'      => null,
            'is_ignored'  => false,
        ], $productAttributes));

        return ProductOffer::create([
            'tenant_id'         => $category->tenant_id,
            'product_id'        => $product->id,
            'store_id'          => $store->id,
            'url'               => "https://www.amazon.com/dp/{$slug}",
            'raw_title'         => $product->name,
            'health_checked_at' => $healthCheckedAt,
        ]);
    }

    private function makeSecondStoreOffer(ProductOffer $offer, string $slug, ?string $healthCheckedAt = null): ProductOffer
    {
        $store = Store::firstOrCreate(['slug' => 'other-store', 'tenant_id' => $offer->tenant_id], ['name' => 'Other Store']);

        return ProductOffer::create([
            'tenant_id'         => $offer->tenant_id,
            'product_id'        => $offer->product_id,
            'store_id'          => $store->id,
            'url'               => "https://www.other-store.com/p/{$slug}",
            'raw_title'         => $offer->raw_title,
            'health_checked_at' => $healthCheckedAt,
        ]);
    }

    private function makeLandingPageWithPicks(
        Category $category,
        string $slug,
        array $productIds,
        string $status = 'published',
    ): LandingPage {
        return LandingPage::factory()->create([
            'tenant_id'   => $category->tenant_id,
            'category_id' => $category->id,
            'slug'        => $slug,
            'status'      => $status,
            'picks'       => collect($productIds)->map(fn (int $productId) => [
                'product_id' => $productId,
                'role'       => 'overall',
                'headline'   => 'Headline',
                'body'       => 'Body',
            ])->all(),
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

    /** @test */
    public function an_invalid_scope_value_returns_422(): void
    {
        $this->getJson('/api/extension/rescan-list?scope=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scope']);
    }

    // -------------------------------------------------------------------------
    // Spec 031 — scope=picks
    // -------------------------------------------------------------------------

    /** @test */
    public function scope_picks_returns_exactly_the_tenants_pick_offers_and_nothing_else(): void
    {
        $category = Category::factory()->create();

        $pickOffer    = $this->makeOffer($category, 'B0PICK00001');
        $nonPickOffer = $this->makeOffer($category, 'B0NONPICK01');
        $this->makeLandingPageWithPicks($category, 'best-widgets', [$pickOffer->product_id]);

        $response = $this->getJson('/api/extension/rescan-list?scope=picks');

        $response->assertOk();
        $offers = $response->json('offers');

        $this->assertCount(1, $offers);
        $this->assertSame($pickOffer->id, $offers[0]['offer_id']);
        $this->assertSame($pickOffer->product_id, $offers[0]['product_id']);
        $this->assertNotEquals($nonPickOffer->product_id, $offers[0]['product_id']);
    }

    /** @test */
    public function scope_picks_excludes_non_pick_products_in_the_same_category(): void
    {
        $category = Category::factory()->create();

        $pickOffer = $this->makeOffer($category, 'B0PICK00002');
        // Two non-pick products in the SAME category as the pick.
        $this->makeOffer($category, 'B0SIBLING01');
        $this->makeOffer($category, 'B0SIBLING02');
        $this->makeLandingPageWithPicks($category, 'best-gadgets', [$pickOffer->product_id]);

        $offers = $this->getJson('/api/extension/rescan-list?scope=picks')->json('offers');

        $this->assertCount(1, $offers);
        $this->assertSame($pickOffer->product_id, $offers[0]['product_id']);
    }

    /** @test */
    public function scope_picks_includes_all_offers_of_a_multi_store_pick_product(): void
    {
        $category   = Category::factory()->create();
        $firstOffer = $this->makeOffer($category, 'B0MULTI0001');
        $secondOffer = $this->makeSecondStoreOffer($firstOffer, 'B0MULTI0001');
        $this->makeLandingPageWithPicks($category, 'best-multi-store', [$firstOffer->product_id]);

        $offers = $this->getJson('/api/extension/rescan-list?scope=picks')->json('offers');

        $this->assertCount(2, $offers);
        $offerIds = collect($offers)->pluck('offer_id')->all();
        $this->assertContains($firstOffer->id, $offerIds);
        $this->assertContains($secondOffer->id, $offerIds);
    }

    /** @test */
    public function scope_picks_includes_picks_from_draft_landing_pages(): void
    {
        $category = Category::factory()->create();
        $offer    = $this->makeOffer($category, 'B0DRAFT0001');
        $this->makeLandingPageWithPicks($category, 'draft-best-widgets', [$offer->product_id], status: 'draft');

        $offers = $this->getJson('/api/extension/rescan-list?scope=picks')->json('offers');

        $this->assertCount(1, $offers);
        $this->assertSame($offer->id, $offers[0]['offer_id']);
    }

    /** @test */
    public function scope_picks_includes_offers_even_when_the_pick_product_is_ignored_or_pending(): void
    {
        // Deliberate: unlike scope=category, scope=picks does NOT filter by
        // is_ignored/status — a pick that has since drifted into either state is
        // exactly the kind of thing this pass exists to surface, not skip.
        $category = Category::factory()->create();
        $ignoredOffer = $this->makeOffer($category, 'B0IGNOREDPK', productAttributes: ['is_ignored' => true]);
        $pendingOffer = $this->makeOffer($category, 'B0PENDINGPK', productAttributes: ['status' => 'pending_ai']);
        $this->makeLandingPageWithPicks($category, 'best-drifted', [
            $ignoredOffer->product_id,
            $pendingOffer->product_id,
        ]);

        $offers = $this->getJson('/api/extension/rescan-list?scope=picks')->json('offers');

        $offerIds = collect($offers)->pluck('offer_id')->all();
        $this->assertCount(2, $offers);
        $this->assertContains($ignoredOffer->id, $offerIds);
        $this->assertContains($pendingOffer->id, $offerIds);
    }

    /** @test */
    public function scope_picks_orders_offers_oldest_first(): void
    {
        $category = Category::factory()->create();

        $recentlyChecked = $this->makeOffer($category, 'B0PRECENT01', now()->subDay()->toDateTimeString());
        $staleChecked     = $this->makeOffer($category, 'B0PSTALE001', now()->subMonth()->toDateTimeString());
        $neverChecked     = $this->makeOffer($category, 'B0PNEVER001', null);
        $neverChecked->forceFill(['updated_at' => now()->subYear()])->saveQuietly();

        $this->makeLandingPageWithPicks($category, 'best-ordering', [
            $recentlyChecked->product_id,
            $staleChecked->product_id,
            $neverChecked->product_id,
        ]);

        $ids = collect($this->getJson('/api/extension/rescan-list?scope=picks')->json('offers'))
            ->pluck('offer_id')->all();

        $this->assertSame([
            $neverChecked->id,
            $staleChecked->id,
            $recentlyChecked->id,
        ], $ids);
    }

    /** @test */
    public function scope_picks_reports_the_landing_page_slug(): void
    {
        $category = Category::factory()->create();
        $offer    = $this->makeOffer($category, 'B0SLUG00001');
        $this->makeLandingPageWithPicks($category, 'best-slugged-thing', [$offer->product_id]);

        $offers = $this->getJson('/api/extension/rescan-list?scope=picks')->json('offers');

        $this->assertSame('best-slugged-thing', $offers[0]['landing_page_slug']);
    }

    /** @test */
    public function scope_picks_reports_each_offers_own_product_category_id(): void
    {
        // T2 contract addendum: /api/extension/ingest-offer requires category_id
        // and a picks run spans every category, so every row must carry its own
        // product's category_id (not the ignored request param).
        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();
        $offerA    = $this->makeOffer($categoryA, 'B0CATIDA001');
        $offerB    = $this->makeOffer($categoryB, 'B0CATIDB001');
        $this->makeLandingPageWithPicks($categoryA, 'best-a', [$offerA->product_id]);
        $this->makeLandingPageWithPicks($categoryB, 'best-b', [$offerB->product_id]);

        $offers = collect($this->getJson('/api/extension/rescan-list?scope=picks')->json('offers'));

        // Every row, not just a spot check: none may be missing category_id (the
        // extension's own preflight refuses to start a run if even one is null).
        $this->assertCount(2, $offers);
        $offers->each(fn (array $offer) => $this->assertNotNull($offer['category_id']));

        $byOfferId = $offers->keyBy('offer_id');
        $this->assertSame($categoryA->id, $byOfferId[$offerA->id]['category_id']);
        $this->assertSame($categoryB->id, $byOfferId[$offerB->id]['category_id']);
    }

    /** @test */
    public function scope_picks_resolves_a_multi_page_pick_to_the_first_slug_alphabetically(): void
    {
        $category = Category::factory()->create();
        $offer    = $this->makeOffer($category, 'B0MULTIPAGE1');

        // Same product picked on two pages — created out of alpha order to prove
        // the tie-break is by slug, not insertion order.
        $this->makeLandingPageWithPicks($category, 'zzz-best-last', [$offer->product_id]);
        $this->makeLandingPageWithPicks($category, 'aaa-best-first', [$offer->product_id]);

        $offers = $this->getJson('/api/extension/rescan-list?scope=picks')->json('offers');

        $this->assertCount(1, $offers);
        $this->assertSame('aaa-best-first', $offers[0]['landing_page_slug']);
    }

    /** @test */
    public function scope_picks_does_not_leak_offers_across_tenants(): void
    {
        Tenant::create(['id' => 'rescan-picks-tenant-a', 'name' => 'Tenant A']);
        $tenantA = Tenant::find('rescan-picks-tenant-a');
        tenancy()->initialize($tenantA);
        $categoryA = Category::factory()->create();
        $offerA    = $this->makeOffer($categoryA, 'B0PTENANTA1');
        $this->makeLandingPageWithPicks($categoryA, 'tenant-a-best', [$offerA->product_id]);
        tenancy()->end();

        Tenant::create(['id' => 'rescan-picks-tenant-b', 'name' => 'Tenant B']);
        $tenantB = Tenant::find('rescan-picks-tenant-b');
        tenancy()->initialize($tenantB);
        $categoryB = Category::factory()->create();
        $offerB    = $this->makeOffer($categoryB, 'B0PTENANTB1');
        $this->makeLandingPageWithPicks($categoryB, 'tenant-b-best', [$offerB->product_id]);
        tenancy()->end();

        $this->withMiddleware([InitializeTenancyFromPayload::class]);

        $offers = $this->withHeaders(['X-Tenant-Id' => $tenantA->id])
            ->getJson('/api/extension/rescan-list?scope=picks')
            ->assertOk()
            ->json('offers');

        $this->assertCount(1, $offers);
        $this->assertSame($offerA->id, $offers[0]['offer_id']);
    }

    /** @test */
    public function scope_picks_succeeds_without_a_category_id(): void
    {
        $category = Category::factory()->create();
        $offer    = $this->makeOffer($category, 'B0NOCATID01');
        $this->makeLandingPageWithPicks($category, 'best-no-category-id', [$offer->product_id]);

        $this->getJson('/api/extension/rescan-list?scope=picks')
            ->assertOk()
            ->assertJsonCount(1, 'offers');
    }

    /** @test */
    public function scope_picks_ignores_a_supplied_category_id_entirely(): void
    {
        // Decision (docs/questions.md): category_id is IGNORED, not validated,
        // under scope=picks — a stale/foreign/nonexistent value must never 422
        // or silently narrow the sweep. Prove it doesn't narrow: two picks in
        // TWO different categories, and passing either category's id (or a
        // nonexistent one, which would 422 under scope=category) still returns
        // BOTH picks, not just the one matching the supplied id.
        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();
        $offerA    = $this->makeOffer($categoryA, 'B0IGNORECATA');
        $offerB    = $this->makeOffer($categoryB, 'B0IGNORECATB');
        $this->makeLandingPageWithPicks($categoryA, 'best-ignore-category-a', [$offerA->product_id]);
        $this->makeLandingPageWithPicks($categoryB, 'best-ignore-category-b', [$offerB->product_id]);

        $this->getJson("/api/extension/rescan-list?scope=picks&category_id={$categoryA->id}")
            ->assertOk()
            ->assertJsonCount(2, 'offers');

        $this->getJson('/api/extension/rescan-list?scope=picks&category_id=999999')
            ->assertOk()
            ->assertJsonCount(2, 'offers');
    }

    /** @test */
    public function scope_picks_returns_403_without_a_valid_extension_token(): void
    {
        $this->withMiddleware([VerifyExtensionToken::class]);
        config(['services.extension.token' => 'valid-token']);

        $this->getJson('/api/extension/rescan-list?scope=picks')
            ->assertStatus(403)
            ->assertJson(['error' => 'Unauthorized.']);
    }

    // NOTE (regression test for the Spec 030 §B1 `LIKE` bug): sqlite's TEXT-backed
    // json() column preserves Laravel's raw, space-free json_encode output, so a
    // `picks LIKE '%"product_id":N,%'` implementation would PASS every test in
    // this file on sqlite even though it silently matches nothing on MySQL (which
    // re-serializes a native `json` column to a SPACE-PADDED string on cast to
    // text). There is no way to express a meaningful failing-under-LIKE test on
    // this test suite's sqlite connection — the bug is connection-specific, not
    // data-specific. See AuditLandingPageFreshnessJob's docblock and
    // docs/lessons.md for the same conclusion reached previously. The mitigation
    // here is structural, not test-covered: pickProductSlugs() never issues a
    // JSON `LIKE` query at all.
}
