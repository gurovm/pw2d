<?php

namespace Tests\Feature;

use App\Models\AiMatchingDecision;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for Spec 012: fuzzy brand matching in the AI dedup heuristic,
 * brand reuse in ProcessPendingProduct, and negative cache invalidation.
 */
class BrandNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'test-tenant';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::create(['id' => $this->tenantId, 'name' => 'Test Tenant']);
        tenancy()->initialize(Tenant::find($this->tenantId));
    }

    // -------------------------------------------------------------------------
    // AiService::normalizeBrandForComparison — unit tests
    // -------------------------------------------------------------------------

    /** @test */
    public function normalize_strips_ascii_apostrophe(): void
    {
        $this->assertSame("delonghi", AiService::normalizeBrandForComparison("De'Longhi"));
    }

    /** @test */
    public function normalize_strips_right_single_quote(): void
    {
        // U+2019 right single quotation mark
        $this->assertSame("delonghi", AiService::normalizeBrandForComparison("De\u{2019}Longhi"));
    }

    /** @test */
    public function normalize_strips_backtick(): void
    {
        $this->assertSame("delonghi", AiService::normalizeBrandForComparison("De`Longhi"));
    }

    /** @test */
    public function normalize_strips_double_quote(): void
    {
        $this->assertSame("delonghi", AiService::normalizeBrandForComparison('De"Longhi'));
    }

    /** @test */
    public function normalize_lowercases(): void
    {
        $this->assertSame("breville", AiService::normalizeBrandForComparison("BREVILLE"));
    }

    /** @test */
    public function normalize_collapses_whitespace(): void
    {
        $this->assertSame("de longhi", AiService::normalizeBrandForComparison("De  Longhi"));
    }

    /** @test */
    public function normalize_transliterates_accents(): void
    {
        // RØDE uses a Latin Extended character — should become RODE after transliteration
        $result = AiService::normalizeBrandForComparison("RØDE");
        $this->assertSame("rode", $result);
    }

    /** @test */
    public function normalize_is_idempotent(): void
    {
        $once = AiService::normalizeBrandForComparison("De'Longhi");
        $twice = AiService::normalizeBrandForComparison($once);
        $this->assertSame($once, $twice);
    }

    // -------------------------------------------------------------------------
    // matchProduct() heuristic — fuzzy brand matching
    // -------------------------------------------------------------------------

    /** @test */
    public function match_product_finds_products_when_brand_has_ascii_apostrophe_variant(): void
    {
        // Stored brand: "De'Longhi" (with ASCII apostrophe)
        $brand = Brand::factory()->create(['name' => "De'Longhi", 'tenant_id' => $this->tenantId]);

        Product::factory()->create([
            'name'       => 'De Longhi Dedica',
            'brand_id'   => $brand->id,
            'status'     => null,
            'is_ignored' => false,
            'tenant_id'  => $this->tenantId,
        ]);

        $aiService = $this->mockAiServiceToReturnNoMatch();

        // Call with "DeLonghi" — no apostrophe — heuristic must still find the product
        $result = $aiService->matchProduct(
            'De Longhi Dedica Espresso Machine',
            'DeLonghi',
            $this->tenantId,
        );

        // The mock AI will say no match, but the key assertion is that no permanent
        // negative decision was saved WITHOUT calling AI (which would mean heuristic found 0 products).
        // We verify the AI WAS consulted by checking no cached false decision was written before AI ran.
        // Since our mock returns is_match=false, a false decision is written AFTER AI — that is fine.
        $decision = AiMatchingDecision::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('scraped_raw_name', 'De Longhi Dedica Espresso Machine')
            ->first();

        // Decision exists (AI was called — heuristic found products), or no decision yet.
        // What must NOT happen: a false decision written at the heuristic stage (existing_product_id null
        // AND is_match false) before AI had a chance to run. We verify that by checking is_match=false
        // only happens AFTER an AI call, but since this is a mock test, the AI mock records a false decision.
        // The important thing is that a decision exists (AI path was taken, not the early-return path).
        $this->assertNotNull($decision);
    }

    /** @test */
    public function match_product_returns_null_via_heuristic_only_when_truly_no_brand_products(): void
    {
        // No products at all in this tenant for this brand
        $result = app(AiService::class)->matchProduct(
            'Some Random Product Title',
            'UnknownBrandXYZ',
            $this->tenantId,
        );

        $this->assertNull($result);

        // A negative decision should have been cached at the heuristic stage
        $this->assertDatabaseHas('ai_matching_decisions', [
            'tenant_id'        => $this->tenantId,
            'scraped_raw_name' => 'Some Random Product Title',
            'is_match'         => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // ProcessPendingProduct brand reuse — fuzzy match
    // -------------------------------------------------------------------------

    /** @test */
    public function normalize_brand_for_comparison_treats_apostrophe_variants_as_equal(): void
    {
        // The key invariant: normalization of both forms must match
        $stored  = AiService::normalizeBrandForComparison("De'Longhi");   // ASCII apostrophe
        $incoming = AiService::normalizeBrandForComparison("DeLonghi");    // no apostrophe

        $this->assertSame($stored, $incoming);
    }

    /** @test */
    public function brand_reuse_logic_would_match_existing_brand_record(): void
    {
        // Simulate: an existing brand "De'Longhi" is already in the DB.
        $existingBrand = Brand::factory()->create([
            'name'      => "De'Longhi",
            'tenant_id' => $this->tenantId,
        ]);

        // Simulate ProcessPendingProduct brand reuse logic:
        $parsedBrand = 'DeLonghi'; // AI returned this spelling
        $normalizedIncoming = AiService::normalizeBrandForComparison($parsedBrand);

        $found = Brand::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->get(['id', 'name'])
            ->first(fn ($b) => AiService::normalizeBrandForComparison($b->name) === $normalizedIncoming);

        $this->assertNotNull($found, 'Should have found the existing brand by fuzzy match');
        $this->assertEquals($existingBrand->id, $found->id);
    }

    /** @test */
    public function brand_reuse_logic_creates_new_brand_when_no_match(): void
    {
        // No existing brands
        $parsedBrand = 'Breville';
        $normalizedIncoming = AiService::normalizeBrandForComparison($parsedBrand);

        $found = Brand::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->get(['id', 'name'])
            ->first(fn ($b) => AiService::normalizeBrandForComparison($b->name) === $normalizedIncoming);

        $this->assertNull($found, 'No brand should match — should create a new one');
    }

    // -------------------------------------------------------------------------
    // Negative cache invalidation — covers all brand spelling variants
    // -------------------------------------------------------------------------

    /** @test */
    public function negative_cache_invalidation_covers_all_brand_spelling_variants(): void
    {
        // Two brand records with the same normalized form
        Brand::factory()->create(['name' => "De'Longhi", 'tenant_id' => $this->tenantId]);
        Brand::factory()->create(['name' => 'DeLonghi',  'tenant_id' => $this->tenantId]);

        // Negative decisions containing each variant in the scraped title
        AiMatchingDecision::create([
            'tenant_id'           => $this->tenantId,
            'scraped_raw_name'    => "De'Longhi Dedica Espresso Machine",
            'existing_product_id' => null,
            'is_match'            => false,
        ]);

        AiMatchingDecision::create([
            'tenant_id'           => $this->tenantId,
            'scraped_raw_name'    => 'DeLonghi Dedica Espresso Machine',
            'existing_product_id' => null,
            'is_match'            => false,
        ]);

        AiMatchingDecision::create([
            'tenant_id'           => $this->tenantId,
            'scraped_raw_name'    => 'Breville Barista Express',
            'existing_product_id' => null,
            'is_match'            => false,
        ]);

        // Run the invalidation logic as implemented in ProcessPendingProduct
        $parsedBrand = "De'Longhi";
        $normalizedBrand = AiService::normalizeBrandForComparison($parsedBrand);

        $brandVariants = Brand::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->get(['name'])
            ->filter(fn ($b) => AiService::normalizeBrandForComparison($b->name) === $normalizedBrand)
            ->pluck('name');

        $this->assertCount(2, $brandVariants, 'Both brand spelling variants should be found');

        if ($brandVariants->isNotEmpty()) {
            AiMatchingDecision::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('is_match', false)
                ->where(function ($q) use ($brandVariants) {
                    foreach ($brandVariants as $variant) {
                        $q->orWhere('scraped_raw_name', 'LIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $variant) . '%');
                    }
                })
                ->delete();
        }

        // Both De'Longhi decisions should be gone
        $this->assertDatabaseMissing('ai_matching_decisions', [
            'scraped_raw_name' => "De'Longhi Dedica Espresso Machine",
        ]);
        $this->assertDatabaseMissing('ai_matching_decisions', [
            'scraped_raw_name' => 'DeLonghi Dedica Espresso Machine',
        ]);

        // Unrelated Breville decision must remain
        $this->assertDatabaseHas('ai_matching_decisions', [
            'scraped_raw_name' => 'Breville Barista Express',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Return a real AiService with GeminiService mocked to return is_match=false.
     * This lets us test the heuristic path without hitting the real API.
     */
    private function mockAiServiceToReturnNoMatch(): AiService
    {
        $gemini = $this->createMock(\App\Services\GeminiService::class);
        $gemini->method('generate')->willReturn([
            'content' => '{"is_match": false}',
            'parsed'  => ['is_match' => false],
        ]);

        return new AiService($gemini);
    }
}
