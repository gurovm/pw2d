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
}
