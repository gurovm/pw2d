<?php

namespace Tests\Feature\Compare;

use App\Livewire\ProductCompare;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Product;
use App\Models\ProductFeatureValue;
use App\Models\ProductOffer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests for Spec 024 — Compare-Page CWV Initial-Render Weight Cut (F31).
 *
 * Verifies that ProductCompare::$renderLimit caps the first server response
 * at 6 cards, the x-intersect sentinel wires the reveal, H2H/pinned modes
 * are exempt, and match_score is present on every rendered card.
 *
 * Tests run on localhost (central domain) — tenancy is NOT initialized,
 * so BelongsToTenant scoping is bypassed. Factory-created records have
 * tenant_id = null, which is fine in central context.
 *
 * IMPORTANT: Products MUST have an explicit `slug` (the factory does not
 * generate one), and `status = null` + `is_ignored = false` (defaults) so
 * they pass the scoredProducts() filter.
 */
class CompareRenderLimitTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Create a category with one feature, a brand, and N scored products.
     * Each product gets a ProductFeatureValue so the scoring service has data.
     *
     * @return array{category: Category, feature: Feature, brand: Brand, products: \Illuminate\Support\Collection}
     */
    private function scaffoldCategory(int $productCount, string $categorySlug = 'test-cat'): array
    {
        $category = Category::factory()->create(['slug' => $categorySlug]);
        $feature  = Feature::factory()->create(['category_id' => $category->id]);
        $brand    = Brand::factory()->create();

        $products = collect();
        for ($i = 1; $i <= $productCount; $i++) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'brand_id'    => $brand->id,
                'slug'        => "{$categorySlug}-product-{$i}",
                'status'      => null,
                'is_ignored'  => false,
            ]);

            // Attach a feature value so scoring works correctly.
            ProductFeatureValue::factory()->create([
                'product_id' => $product->id,
                'feature_id' => $feature->id,
                'raw_value'  => $i * 5.0, // distinct scores so order is deterministic
            ]);

            $products->push($product);
        }

        return compact('category', 'feature', 'brand', 'products');
    }

    // ── Test 1: Initial render shows exactly 6 cards ─────────────────────────

    /** @test */
    public function initial_visible_products_is_6_when_category_has_more_than_6_products(): void
    {
        ['category' => $category] = $this->scaffoldCategory(14);

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        // The component's visibleProducts computed property should return exactly 6.
        $visibleCount = $component->instance()->visibleProducts->count();

        $this->assertSame(6, $visibleCount,
            "Expected visibleProducts to be capped at renderLimit=6 on initial render; got {$visibleCount}.");
    }

    /** @test */
    public function initial_http_render_contains_exactly_6_product_cards(): void
    {
        ['category' => $category] = $this->scaffoldCategory(14);

        $response = $this->get('/compare/' . $category->slug);

        $response->assertStatus(200);

        // Count `wire:key="product-{id}"` occurrences — one per rendered card.
        $html = $response->getContent();
        $cardMatches = preg_match_all('/wire:key="product-\d+"/', $html, $m);

        $this->assertSame(6, $cardMatches,
            "Expected exactly 6 product cards in the initial HTTP response; found {$cardMatches}.");
    }

    // ── Test 2: [data-reveal-sentinel] present in initial render ─────────────

    /** @test */
    public function reveal_sentinel_is_present_in_initial_http_render_when_more_than_6_products(): void
    {
        ['category' => $category] = $this->scaffoldCategory(14);

        $response = $this->get('/compare/' . $category->slug);

        $response->assertStatus(200);
        $response->assertSee('data-reveal-sentinel', escape: false);
    }

    // ── Test 3: After revealMore() → 12 cards ────────────────────────────────

    /** @test */
    public function reveal_more_raises_visible_products_to_12(): void
    {
        ['category' => $category] = $this->scaffoldCategory(14);

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        // Verify we start at 6.
        $this->assertSame(6, $component->instance()->visibleProducts->count());

        // Trigger the scroll reveal — simulates the x-intersect sentinel firing.
        $component->call('revealMore');

        // renderLimit should now be 12 (= displayLimit), so all 12 initial slots are filled.
        $this->assertSame(12, $component->instance()->visibleProducts->count());
    }

    /** @test */
    public function has_more_to_reveal_is_false_after_reveal_more_catches_up_to_display_limit(): void
    {
        // 14 products, displayLimit=12 by default; after revealMore renderLimit=12=displayLimit.
        ['category' => $category] = $this->scaffoldCategory(14);

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        // Before reveal: renderLimit(6) < displayLimit(12) → hasMoreToReveal should be true.
        $this->assertTrue($component->instance()->hasMoreToReveal(),
            'hasMoreToReveal() should be true before revealMore() fires.');

        $component->call('revealMore');

        // After reveal: renderLimit(12) == displayLimit(12) → sentinel should disappear.
        $this->assertFalse($component->instance()->hasMoreToReveal(),
            'hasMoreToReveal() should be false once renderLimit reaches displayLimit.');
    }

    // ── Test 4: [data-reveal-sentinel] gone once hasMoreToReveal is false ─────

    /** @test */
    public function reveal_sentinel_is_absent_after_reveal_catches_up_to_display_limit(): void
    {
        // 14 products; after one revealMore call, renderLimit = displayLimit = 12.
        ['category' => $category] = $this->scaffoldCategory(14);

        // Verify sentinel disappears by checking the re-rendered HTML after reveal.
        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);
        $component->call('revealMore');

        // The component view is re-rendered; $hasMoreToReveal = false means no sentinel block.
        $html = $component->html();
        $this->assertStringNotContainsString('data-reveal-sentinel', $html,
            'Reveal sentinel should not be present in HTML once hasMoreToReveal() returns false.');
    }

    // ── Test 5: Load-more composition ────────────────────────────────────────

    /** @test */
    public function load_more_re_arms_sentinel_and_reveal_more_continues(): void
    {
        // Seed 28 products so we have enough beyond displayLimit (12) and renderLimit (6).
        ['category' => $category] = $this->scaffoldCategory(28);

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        // Step 1: reveal → renderLimit = 12, displayLimit = 12 → sentinel gone.
        $component->call('revealMore');
        $this->assertFalse($component->instance()->hasMoreToReveal(),
            'After first revealMore sentinel should be gone (renderLimit == displayLimit = 12).');
        $this->assertSame(12, $component->instance()->visibleProducts->count());

        // Step 2: "Load more" button fires loadMore() → displayLimit = 24.
        // renderLimit stays at 12, so hasMoreToReveal() becomes true again.
        $component->call('loadMore');
        $this->assertTrue($component->instance()->hasMoreToReveal(),
            'After loadMore(), displayLimit=24 > renderLimit=12, so sentinel must re-arm.');

        // Step 3: two more revealMore() calls → renderLimit = 18, then 24.
        $component->call('revealMore');
        $this->assertSame(18, $component->instance()->visibleProducts->count());
        $this->assertTrue($component->instance()->hasMoreToReveal(),
            'After second revealMore, renderLimit=18 < displayLimit=24, sentinel still armed.');

        $component->call('revealMore');
        $this->assertSame(24, $component->instance()->visibleProducts->count());
        $this->assertFalse($component->instance()->hasMoreToReveal(),
            'After third revealMore, renderLimit=24 == displayLimit=24, sentinel gone again.');
    }

    // ── Test 6: H2H Arena EXEMPT from renderLimit ─────────────────────────────

    /** @test */
    public function h2h_arena_mode_is_exempt_from_render_limit_cap(): void
    {
        ['category' => $category, 'products' => $products] = $this->scaffoldCategory(14);

        // Pin 4 products (the H2H maximum) and enter arena mode.
        $pinnedIds = $products->take(4)->pluck('id')->toArray();

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        // Build the compareList and activate H2H arena.
        foreach ($pinnedIds as $id) {
            $component->call('toggleCompare', $id);
        }
        $component->call('startComparison');

        // isComparing should be true now.
        $component->assertSet('isComparing', true);

        // visibleProducts must return all 4 pinned items — NOT capped at 6.
        $visible = $component->instance()->visibleProducts;
        $this->assertSame(4, $visible->count(),
            "H2H Arena should render all 4 pinned products, not be capped at renderLimit=6.");

        // No reveal sentinel should be present in H2H mode.
        $this->assertFalse($component->instance()->hasMoreToReveal(),
            'hasMoreToReveal() must be false in H2H Arena mode.');
    }

    /** @test */
    public function h2h_arena_mode_has_no_reveal_sentinel_in_html(): void
    {
        ['category' => $category, 'products' => $products] = $this->scaffoldCategory(14);

        $pinnedIds = $products->take(3)->pluck('id')->toArray();

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        foreach ($pinnedIds as $id) {
            $component->call('toggleCompare', $id);
        }
        $component->call('startComparison');

        $html = $component->html();
        $this->assertStringNotContainsString('data-reveal-sentinel', $html,
            'H2H Arena must not render the reveal sentinel.');
    }

    // ── Test 7: Pinned-staging EXEMPT from renderLimit cap ────────────────────

    /** @test */
    public function pinned_staging_mode_is_exempt_from_render_limit_cap(): void
    {
        // 14 products; pin 3 without entering H2H arena (staging mode).
        ['category' => $category, 'products' => $products] = $this->scaffoldCategory(14);

        $pinnedIds = $products->take(3)->pluck('id')->toArray();

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        foreach ($pinnedIds as $id) {
            $component->call('toggleCompare', $id);
        }

        // isComparing is still false (staging, not arena).
        $component->assertSet('isComparing', false);
        $component->assertSet('compareList', $pinnedIds);

        // In staging mode, visibleProducts returns up to displayLimit (12), not renderLimit (6).
        $visible = $component->instance()->visibleProducts;

        // All 3 pinned products must appear in the visible set.
        $visibleIds = $visible->pluck('id')->toArray();
        foreach ($pinnedIds as $pinnedId) {
            $this->assertContains($pinnedId, $visibleIds,
                "Pinned product ID {$pinnedId} must appear in visibleProducts during staging.");
        }

        // Staging returns up to displayLimit (12) — more than renderLimit (6).
        $this->assertGreaterThan(6, $visible->count(),
            'Pinned-staging mode must return more than 6 products (bypasses renderLimit).');
    }

    // ── Test 8: match_score present on every initially rendered card ──────────

    /** @test */
    public function match_score_is_present_on_all_initially_rendered_products(): void
    {
        ['category' => $category] = $this->scaffoldCategory(14);

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        $visible = $component->instance()->visibleProducts;

        $this->assertSame(6, $visible->count());

        foreach ($visible as $product) {
            $this->assertNotNull($product->match_score,
                "match_score must be set on every initially rendered card; product ID {$product->id} has null.");
            $this->assertIsNumeric($product->match_score,
                "match_score must be numeric on product ID {$product->id}.");
        }
    }

    /** @test */
    public function match_score_is_present_on_revealed_products(): void
    {
        ['category' => $category] = $this->scaffoldCategory(14);

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);
        $component->call('revealMore');

        $visible = $component->instance()->visibleProducts;

        // After reveal there are up to 12 products — all must have match_score.
        foreach ($visible as $product) {
            $this->assertNotNull($product->match_score,
                "match_score must be set on revealed cards; product ID {$product->id} has null.");
            $this->assertIsNumeric($product->match_score,
                "match_score must be numeric on product ID {$product->id}.");
        }
    }

    // ── Test 9: Edge case — category with ≤6 products ────────────────────────

    /** @test */
    public function category_with_fewer_than_6_products_shows_all_and_no_sentinel(): void
    {
        ['category' => $category, 'products' => $products] = $this->scaffoldCategory(4);

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        // All 4 products must be visible.
        $this->assertSame(4, $component->instance()->visibleProducts->count());

        // No sentinel (nothing to reveal).
        $this->assertFalse($component->instance()->hasMoreToReveal());

        $html = $component->html();
        $this->assertStringNotContainsString('data-reveal-sentinel', $html);
    }

    /** @test */
    public function category_with_exactly_6_products_shows_all_and_no_sentinel(): void
    {
        ['category' => $category] = $this->scaffoldCategory(6);

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        $this->assertSame(6, $component->instance()->visibleProducts->count());
        $this->assertFalse($component->instance()->hasMoreToReveal());

        $html = $component->html();
        $this->assertStringNotContainsString('data-reveal-sentinel', $html);
    }

    // ── Test 10: HTTP render card-count is smaller when >6 products exist ─────

    /** @test */
    public function initial_http_render_card_count_halves_compared_to_full_display_limit(): void
    {
        // Verify the initial render actually has half the cards a naive 12-cap would emit.
        ['category' => $category] = $this->scaffoldCategory(14);

        $response = $this->get('/compare/' . $category->slug);
        $response->assertStatus(200);

        $html = $response->getContent();
        $cardCount = preg_match_all('/wire:key="product-\d+"/', $html, $m);

        // 6 cards initially (renderLimit) vs the old 12 (displayLimit) — meaningful cut.
        $this->assertLessThanOrEqual(6, $cardCount,
            "Initial HTTP render must not exceed 6 product cards (was {$cardCount}).");
        $this->assertGreaterThanOrEqual(1, $cardCount,
            'At least one product card must appear in the initial response.');
    }

    // ── Test 11: revealMore() is capped at displayLimit, never overshoots ─────

    /** @test */
    public function reveal_more_never_exceeds_display_limit(): void
    {
        // Only 8 products — displayLimit=12 but only 8 exist.
        ['category' => $category] = $this->scaffoldCategory(8);

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        // Call revealMore multiple times; renderLimit must never exceed displayLimit.
        $component->call('revealMore');
        $component->call('revealMore');
        $component->call('revealMore');

        $instance   = $component->instance();
        $renderLimit  = $instance->renderLimit;
        $displayLimit = $instance->displayLimit;

        $this->assertLessThanOrEqual($displayLimit, $renderLimit,
            "renderLimit ({$renderLimit}) must never exceed displayLimit ({$displayLimit}).");

        // Visible products must be capped at actual product count (8), not displayLimit.
        $this->assertSame(8, $instance->visibleProducts->count());
    }

    // ── Test 12: H2H pinned set has correct match_scores ──────────────────────

    /** @test */
    public function h2h_arena_products_have_match_score_attached(): void
    {
        ['category' => $category, 'products' => $products] = $this->scaffoldCategory(14);

        $pinnedIds = $products->take(4)->pluck('id')->toArray();

        $component = Livewire::test(ProductCompare::class, ['slug' => $category->slug]);

        foreach ($pinnedIds as $id) {
            $component->call('toggleCompare', $id);
        }
        $component->call('startComparison');

        $visible = $component->instance()->visibleProducts;

        foreach ($visible as $product) {
            $this->assertNotNull($product->match_score,
                "H2H Arena product ID {$product->id} must have match_score attached.");
        }
    }

    // ── Test 13: ItemList schema reflects displayLimit, not renderLimit ─────────
    //
    // Spec 024 blocker: Googlebot sees only the initial HTTP response (never fires
    // x-intersect). Passing the render-capped visibleProducts (6 items) to SeoSchema
    // would emit an ItemList with only 6 ListItems and a meta description of
    // "Compare 6 top…" — halving the rich-result signal on the exact pages we're
    // optimising. schemaProducts() (top displayLimit = 12) must feed SeoSchema instead.

    /** @test */
    public function initial_http_render_itemlist_schema_contains_displaylimit_entries_not_renderlimit(): void
    {
        // Seed 14 products — more than displayLimit(12) and well above renderLimit(6).
        // Products need a Store + offer so schemaProducts() eager-load can resolve brand/offers.
        $category = Category::factory()->create(['slug' => 'schema-test-cat']);
        $feature  = Feature::factory()->create(['category_id' => $category->id]);
        $brand    = Brand::factory()->create(['name' => 'SchemaBrand']);

        $store = Store::create([
            'tenant_id'  => null,
            'name'       => 'Schema Store',
            'slug'       => 'schema-store-' . uniqid(),
            'is_active'  => true,
        ]);

        for ($i = 1; $i <= 14; $i++) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'brand_id'    => $brand->id,
                'slug'        => "schema-cat-product-{$i}",
                'name'        => "Schema Product {$i}",
                'status'      => null,
                'is_ignored'  => false,
            ]);

            ProductFeatureValue::factory()->create([
                'product_id' => $product->id,
                'feature_id' => $feature->id,
                'raw_value'  => $i * 5.0,
            ]);

            ProductOffer::create([
                'product_id'   => $product->id,
                'store_id'     => $store->id,
                'tenant_id'    => null,
                'url'          => "https://example.com/p/{$i}",
                'scraped_price' => 99.99,
                'raw_title'    => "Schema Product {$i} Raw",
                'image_url'    => "https://cdn.example.com/img/{$i}.jpg",
            ]);
        }

        // Full HTTP render — this is ALL Googlebot ever sees.
        $response = $this->get('/compare/' . $category->slug);
        $response->assertStatus(200);

        $html = $response->getContent();

        // Extract all JSON-LD <script> blocks and find the ItemList one.
        preg_match_all(
            '/<script[^>]+type="application\/ld\+json"[^>]*>(.*?)<\/script>/si',
            $html,
            $matches,
        );

        $itemListSchema = null;
        foreach ($matches[1] as $raw) {
            $decoded = json_decode(trim($raw), true);
            if (is_array($decoded) && ($decoded['@type'] ?? '') === 'ItemList') {
                $itemListSchema = $decoded;
                break;
            }
        }

        $this->assertNotNull($itemListSchema,
            'An ItemList JSON-LD schema must be present in the initial HTTP response.');

        $itemCount = count($itemListSchema['itemListElement'] ?? []);

        // The critical assertion: schema must reflect displayLimit (12), NOT renderLimit (6).
        // With 14 products seeded and displayLimit=12, the ItemList must have 12 entries.
        $this->assertSame(12, $itemCount,
            "ItemList must contain displayLimit(12) entries in the initial response, "
            . "not renderLimit(6). Got {$itemCount}. "
            . "This guards against schemaProducts() accidentally being replaced by visibleProducts().");
    }

    /** @test */
    public function meta_description_reflects_displaylimit_count_not_renderlimit(): void
    {
        // Seed 14 products; default displayLimit = 12, renderLimit = 6.
        // The meta description must read "Compare 12 top…", not "Compare 6 top…".
        $category = Category::factory()->create([
            'slug' => 'meta-desc-test-cat',
            'name' => 'Meta Widgets',
        ]);
        $feature = Feature::factory()->create(['category_id' => $category->id]);
        $brand   = Brand::factory()->create();

        for ($i = 1; $i <= 14; $i++) {
            $product = Product::factory()->create([
                'category_id' => $category->id,
                'brand_id'    => $brand->id,
                'slug'        => "meta-product-{$i}",
                'status'      => null,
                'is_ignored'  => false,
            ]);
            ProductFeatureValue::factory()->create([
                'product_id' => $product->id,
                'feature_id' => $feature->id,
                'raw_value'  => $i * 3.0,
            ]);
        }

        $response = $this->get('/compare/' . $category->slug);
        $response->assertStatus(200);

        $html = $response->getContent();

        // The meta description is rendered in the layout <head>.
        // It must say "Compare 12 top…" (displayLimit), not "Compare 6 top…" (renderLimit).
        $this->assertStringContainsString(
            'Compare 12 top',
            $html,
            'Meta description must reference displayLimit (12) products, not renderLimit (6).',
        );

        $this->assertStringNotContainsString(
            'Compare 6 top',
            $html,
            'Meta description must NOT reference renderLimit (6) — that would mislead Googlebot.',
        );
    }
}
