<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\VsPageResource;
use App\Filament\Resources\VsPageResource\Pages\ListVsPages;
use App\Filament\Resources\VsPageResource\Pages\ViewVsPage;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VsPage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\VsPages\VsFixtures;
use Tests\TestCase;

/** Spec 043 — read-mostly VsPageResource (Livewire page tests, same technique as LandingPageResourceTest). */
class VsPageResourceTest extends TestCase
{
    use RefreshDatabase, VsFixtures;

    private VsPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['email' => 'admin@pw2d.com']));
        $tenant = $this->initTenant('vs-resource-tenant');
        Filament::setTenant($tenant);

        $category = Category::factory()->create(['name' => 'Espresso', 'slug' => 'espresso']);
        $a = $this->makeLiveProduct($category, 'Alpha 1', 1000);
        $b = $this->makeLiveProduct($category, 'Beta 2', 1000);

        $this->page = VsPage::factory()->create([
            'category_id' => $category->id, 'product_a_id' => $a->id, 'product_b_id' => $b->id,
            'slug' => 'alpha-1-vs-beta-2', 'stale_reasons' => ['price_drift'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->endTenancy();
        parent::tearDown();
    }

    /** @test */
    public function the_list_shows_pair_category_status_and_stale_reasons(): void
    {
        Livewire::test(ListVsPages::class)
            ->assertOk()
            ->assertSee('Alpha 1 vs Beta 2')
            ->assertSee('Espresso')
            ->assertSee('Draft')
            ->assertSee('price_drift');
    }

    /** @test */
    public function the_status_toggle_publishes_and_unpublishes(): void
    {
        Livewire::test(ListVsPages::class)->callTableAction('toggleStatus', $this->page);
        $this->assertSame('published', $this->page->fresh()->status);

        Livewire::test(ListVsPages::class)->callTableAction('toggleStatus', $this->page);
        $this->assertSame('draft', $this->page->fresh()->status);
    }

    /** @test */
    public function the_view_page_renders_and_there_is_no_create_page(): void
    {
        Livewire::test(ViewVsPage::class, ['record' => $this->page->getRouteKey()])
            ->assertOk()
            ->assertSee('alpha-1-vs-beta-2');

        $this->assertFalse(VsPageResource::canCreate());
        $this->assertArrayNotHasKey('create', VsPageResource::getPages());
    }
}
