<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\LandingPageResource;
use App\Filament\Resources\LandingPageResource\Pages\EditLandingPage;
use App\Filament\Resources\LandingPageResource\Pages\ListLandingPages;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 027 §8 / review S5 — minimal Filament LandingPageResource.
 *
 * NOTE on scope: full HTTP requests through the admin panel layout
 * (`$this->get('/admin/...')`) are blocked by a PRE-EXISTING, unrelated bug —
 * `ProblemProducts::getNavigationBadge()` uses raw REGEXP SQL, unsupported by
 * the sqlite in-memory test connection, and crashes on ANY panel page render
 * (tracked as F12 in docs/tasks/todo.md; SeoDashboardTest's two HTTP tests are
 * skipped for the same reason). `Livewire::test()` on an individual Resource
 * Page component does NOT render the panel layout/navigation (confirmed
 * empirically — same technique F17 established for widgets), so it sidesteps
 * F12 entirely and is used here instead of `$this->get()`.
 */
class LandingPageResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'admin@pw2d.com']);

        Tenant::create(['id' => 'lp-resource-tenant', 'name' => 'LP Resource Tenant']);
        $this->tenant = Tenant::find('lp-resource-tenant');
        tenancy()->initialize($this->tenant);

        $this->actingAs($this->admin);
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    // =========================================================================
    // Registration / structure
    // =========================================================================

    /** @test */
    public function resource_is_discovered_by_the_admin_panel(): void
    {
        $this->assertContains(LandingPageResource::class, Filament::getPanel('admin')->getResources());
    }

    /** @test */
    public function resource_declares_index_create_and_edit_pages(): void
    {
        $pages = LandingPageResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('create', $pages);
        $this->assertArrayHasKey('edit', $pages);
    }

    // =========================================================================
    // List page
    // =========================================================================

    /** @test */
    public function list_page_renders_and_shows_category_slug_and_status(): void
    {
        $category = Category::factory()->create(['name' => 'Resource Test Category', 'slug' => 'lp-resource-list-cat']);

        LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-resource-list',
            'status'      => 'draft',
        ]);

        Livewire::test(ListLandingPages::class)
            ->assertOk()
            ->assertSee('Resource Test Category')
            ->assertSee('best-lp-resource-list')
            ->assertSee('Draft');
    }

    // =========================================================================
    // Spec 030 §B5 — Freshness badge column + navigation badge
    // =========================================================================

    /** @test */
    public function list_page_shows_fresh_badge_for_a_page_with_no_stale_reasons(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-resource-fresh-cat']);

        LandingPage::factory()->create([
            'category_id'   => $category->id,
            'slug'          => 'best-lp-resource-fresh',
            'stale_reasons' => [],
        ]);

        Livewire::test(ListLandingPages::class)
            ->assertOk()
            ->assertSee('FRESH');
    }

    /** @test */
    public function list_page_shows_stale_badge_and_reasons_for_a_stale_page(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-resource-stale-cat']);

        LandingPage::factory()->create([
            'category_id'   => $category->id,
            'slug'          => 'best-lp-resource-stale',
            'stale_reasons' => ['pick_ineligible', 'price_drift'],
        ]);

        Livewire::test(ListLandingPages::class)
            ->assertOk()
            ->assertSee('STALE')
            ->assertSee('pick_ineligible');
    }

    /** @test */
    public function navigation_badge_counts_only_published_stale_pages(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-resource-badge-cat']);

        // Counts: published + stale.
        LandingPage::factory()->create([
            'category_id'   => $category->id,
            'slug'          => 'best-lp-resource-badge-published-stale',
            'status'        => 'published',
            'stale_reasons' => ['pick_ineligible'],
        ]);

        // Excluded: draft + stale (not published).
        $draftCategory = Category::factory()->create(['slug' => 'lp-resource-badge-draft-cat']);
        LandingPage::factory()->create([
            'category_id'   => $draftCategory->id,
            'slug'          => 'best-lp-resource-badge-draft-stale',
            'status'        => 'draft',
            'stale_reasons' => ['pick_ineligible'],
        ]);

        // Excluded: published + fresh.
        $freshCategory = Category::factory()->create(['slug' => 'lp-resource-badge-fresh-cat']);
        LandingPage::factory()->create([
            'category_id'   => $freshCategory->id,
            'slug'          => 'best-lp-resource-badge-published-fresh',
            'status'        => 'published',
            'stale_reasons' => [],
        ]);

        $this->assertSame('1', LandingPageResource::getNavigationBadge());
    }

    /** @test */
    public function navigation_badge_is_null_when_no_published_pages_are_stale(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-resource-badge-none-cat']);
        LandingPage::factory()->create([
            'category_id'   => $category->id,
            'slug'          => 'best-lp-resource-badge-none',
            'status'        => 'published',
            'stale_reasons' => [],
        ]);

        $this->assertNull(LandingPageResource::getNavigationBadge());
    }

    /** @test */
    public function navigation_badge_is_scoped_to_the_current_tenant(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-resource-badge-tenant-a-cat']);
        LandingPage::factory()->create([
            'category_id'   => $category->id,
            'slug'          => 'best-lp-resource-badge-tenant-a',
            'status'        => 'published',
            'stale_reasons' => ['pick_ineligible'],
        ]);

        $this->assertSame('1', LandingPageResource::getNavigationBadge());

        // Switch tenant context — a second tenant with zero stale published pages
        // must see a null badge, not tenant A's count.
        tenancy()->end();
        Tenant::create(['id' => 'lp-resource-tenant-b', 'name' => 'LP Resource Tenant B']);
        $tenantB = Tenant::find('lp-resource-tenant-b');
        tenancy()->initialize($tenantB);

        $this->assertNull(LandingPageResource::getNavigationBadge());

        tenancy()->end();
        tenancy()->initialize($this->tenant);
    }

    // =========================================================================
    // Publish without AI (the core S5 constraint)
    // =========================================================================

    /** @test */
    public function editing_and_publishing_a_draft_page_never_touches_ai_service(): void
    {
        // A throwing spy — if the edit/save flow ever calls AiService, the test fails loudly.
        $spy = new class extends AiService {
            public function __construct() {}

            public function generateLandingPageContent(\App\Models\Category $category, array $picks, array $excludeFaqQuestions, int $scoredProductCount = 0): array
            {
                throw new \RuntimeException('AiService must NOT be called by the Filament publish flow.');
            }
        };
        app()->instance(AiService::class, $spy);

        $category = Category::factory()->create(['name' => 'Publish Flow Category', 'slug' => 'lp-resource-publish-cat']);
        $product  = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'lp-resource-publish-product',
            'is_ignored'  => false,
            'status'      => null,
        ]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-resource-publish',
            'status'      => 'draft',
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'Original headline', 'body' => 'Original body'],
            ],
        ]);

        Livewire::test(EditLandingPage::class, ['record' => $page->getRouteKey()])
            ->assertFormSet(['status' => false]) // draft -> toggle off
            ->fillForm(['status' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('published', $page->fresh()->status, 'Toggling the form status must publish the page directly, with no AI call in between');
    }

    /** @test */
    public function editing_headline_and_body_persists_without_changing_the_algorithmically_selected_pick(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-resource-edit-cat']);
        $product  = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'lp-resource-edit-product',
            'is_ignored'  => false,
            'status'      => null,
        ]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-resource-edit',
            'picks'       => [
                ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'Old headline', 'body' => 'Old body'],
            ],
        ]);

        Livewire::test(EditLandingPage::class, ['record' => $page->getRouteKey()])
            ->fillForm([
                'picks' => [
                    ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'Edited headline', 'body' => 'Edited body'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $page->fresh();
        $this->assertSame('Edited headline', $fresh->picks[0]['headline']);
        $this->assertSame($product->id, $fresh->picks[0]['product_id'], 'product_id must survive the save even though the field is disabled in the form');
        $this->assertSame('overall', $fresh->picks[0]['role'], 'role must survive the save even though the field is disabled in the form');
    }

    // =========================================================================
    // Security M3 / Reviewer B3 — picks identity + est_price_snapshot cannot be
    // tampered or wiped by a Filament save.
    // =========================================================================

    /** @test */
    public function a_prose_edit_preserves_est_price_snapshot(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-resource-snapshot-cat']);
        $product  = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'lp-resource-snapshot-product',
            'is_ignored'  => false,
            'status'      => null,
        ]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-resource-snapshot',
            'picks'       => [
                [
                    'product_id'         => $product->id,
                    'role'               => 'overall',
                    'headline'           => 'Old headline',
                    'body'               => 'Old body',
                    'est_price_snapshot' => 199.99,
                ],
            ],
        ]);

        // Livewire's fillForm sets raw component state — the est_price_snapshot key
        // isn't even a declared repeater field, so it's never present in $data at all,
        // simulating exactly what a real prose-only edit submits.
        Livewire::test(EditLandingPage::class, ['record' => $page->getRouteKey()])
            ->fillForm([
                'picks' => [
                    ['product_id' => $product->id, 'role' => 'overall', 'headline' => 'New headline', 'body' => 'New body'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $page->fresh();
        $this->assertSame('New headline', $fresh->picks[0]['headline']);
        $this->assertEquals(199.99, $fresh->picks[0]['est_price_snapshot'], 'est_price_snapshot must survive a prose-only edit');
    }

    /** @test */
    public function a_tampered_product_id_and_role_cannot_survive_a_save(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-resource-tamper-cat']);
        $realProduct = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'lp-resource-tamper-real-product',
            'is_ignored'  => false,
            'status'      => null,
        ]);
        $decoyProduct = Product::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'lp-resource-tamper-decoy-product',
        ]);

        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-resource-tamper',
            'picks'       => [
                [
                    'product_id'         => $realProduct->id,
                    'role'               => 'overall',
                    'headline'           => 'Headline',
                    'body'               => 'Body',
                    'est_price_snapshot' => 42.00,
                ],
            ],
        ]);

        // Simulates a crafted Livewire update payload trying to swap the pick's
        // identity — `dehydrated(false)` means this never even reaches $data, and
        // mutateFormDataBeforeSave() force-restores from the stored record anyway.
        Livewire::test(EditLandingPage::class, ['record' => $page->getRouteKey()])
            ->fillForm([
                'picks' => [
                    ['product_id' => $decoyProduct->id, 'role' => 'budget', 'headline' => 'Headline', 'body' => 'Body'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $page->fresh();
        $this->assertSame($realProduct->id, $fresh->picks[0]['product_id'], 'a tampered product_id must never survive a save');
        $this->assertSame('overall', $fresh->picks[0]['role'], 'a tampered role must never survive a save');
        $this->assertEquals(42.00, $fresh->picks[0]['est_price_snapshot'], 'est_price_snapshot must survive alongside identity restoration');
    }

    // =========================================================================
    // Perf M3 / Security M3 — itemLabel uses the persisted product_name, never a
    // cross-tenant Product::withoutGlobalScopes() lookup.
    // =========================================================================

    /** @test */
    public function item_label_uses_the_persisted_product_name_without_a_cross_tenant_lookup(): void
    {
        $category = Category::factory()->create(['slug' => 'lp-resource-label-cat']);

        // No Product row exists for this id under ANY tenant — if itemLabel ever
        // fell back to a lookup it could only render "product #<id>", proving the
        // persisted `product_name` key is what's actually rendered.
        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-resource-label',
            'picks'       => [
                [
                    'product_id'   => 999999,
                    'role'         => 'overall',
                    'headline'     => 'Headline',
                    'body'         => 'Body',
                    'product_name' => 'Persisted Product Name From Generation',
                ],
            ],
        ]);

        Livewire::test(EditLandingPage::class, ['record' => $page->getRouteKey()])
            ->assertOk()
            ->assertSee('Persisted Product Name From Generation');
    }
}
