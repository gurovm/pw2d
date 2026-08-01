<?php

declare(strict_types=1);

namespace Tests\Feature\LandingPage;

use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Spec 027 review S1 — LandingPage cache invalidation must be deterministic,
 * derived from the row's own `tenant_id` column, NOT the ambient tenancy()
 * context. Otherwise a save from tinker/a queued job/any code path outside an
 * initialized tenant forgets `tcentral:...` keys and leaves the real tenant's
 * caches stale.
 */
class LandingPageCacheKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    /** @test */
    public function cache_key_is_tenant_scoped_even_when_ambient_tenancy_is_not_initialized(): void
    {
        Tenant::create(['id' => 'lp-cachekey-tenant', 'name' => 'LP CacheKey Tenant']);
        $tenant = Tenant::find('lp-cachekey-tenant');
        tenancy()->initialize($tenant);

        $category = Category::factory()->create(['slug' => 'lp-cachekey-cat']);
        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-cachekey',
        ]);

        // Ambient tenancy ends — mimics tinker, a queued job, or any future code
        // path that touches this model outside an initialized tenant context.
        tenancy()->end();

        $this->assertSame(
            'tlp-cachekey-tenant:landing:best-lp-cachekey',
            $page->cacheKey(),
            "cacheKey() must derive from the row's own tenant_id, not the (now-null) ambient tenant"
        );
    }

    /** @test */
    public function saving_without_ambient_tenancy_still_forgets_the_correct_tenants_caches(): void
    {
        Tenant::create(['id' => 'lp-cachekey-save-tenant', 'name' => 'LP CacheKey Save Tenant']);
        $tenant = Tenant::find('lp-cachekey-save-tenant');
        tenancy()->initialize($tenant);

        $category = Category::factory()->create(['slug' => 'lp-cachekey-save-cat']);
        $page = LandingPage::factory()->create([
            'category_id' => $category->id,
            'slug'        => 'best-lp-cachekey-save',
        ]);

        $realCacheKey      = $page->cacheKey();
        $realSitemapKey    = 'tlp-cachekey-save-tenant:sitemap:xml';
        $centralSitemapKey = 'tcentral:sitemap:xml';

        Cache::put($realCacheKey, ['stale' => true], 3600);
        Cache::put($realSitemapKey, '<xml>stale</xml>', 600);
        Cache::put($centralSitemapKey, '<xml>unrelated central cache</xml>', 600);

        // The ambient tenant context is gone at save time — exactly the bug S1 fixes.
        tenancy()->end();

        $page->intro = 'Updated after ending ambient tenancy.';
        $page->save();

        $this->assertFalse(Cache::has($realCacheKey), "The real tenant's view-model cache must be forgotten");
        $this->assertFalse(Cache::has($realSitemapKey), "The real tenant's sitemap cache must be forgotten");
        $this->assertTrue(Cache::has($centralSitemapKey), 'An unrelated central-context cache key must be left untouched');
    }
}
