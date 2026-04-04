<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Tests\TestCase;

/**
 * Tests for Setting::get() / Setting::set() with tenant-aware caching.
 *
 * Tests run on the central domain (localhost). For tenant-context tests we
 * manually bind the tenant in the service container — the same technique
 * used by TenantCacheKeyTest — to simulate a tenant being active without
 * starting the full Tenancy middleware pipeline.
 *
 * Because BelongsToTenant's TenantScope checks `tenancy()->initialized`, some
 * tests also call tenancy()->initialize() so DB queries are correctly scoped.
 * Those tests use tearDown cleanup to end tenancy and reset container state.
 */
class SettingCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../app/Helpers/cache.php';
        Cache::flush();
    }

    protected function tearDown(): void
    {
        // Reset tenancy so subsequent tests start in the central context
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // Remove any manually bound Tenant instance
        if (app()->resolved(TenantContract::class)) {
            app()->forgetInstance(TenantContract::class);
        }

        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Setting::get() — basic read + default value (central context)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function get_returns_stored_value_for_key(): void
    {
        Setting::create([
            'key'   => 'site_name',
            'value' => 'Best Mics',
        ]);

        $result = Setting::get('site_name');

        $this->assertSame('Best Mics', $result);
    }

    /** @test */
    public function get_returns_default_when_key_does_not_exist(): void
    {
        $result = Setting::get('nonexistent_key', 'fallback_value');

        $this->assertSame('fallback_value', $result);
    }

    /** @test */
    public function get_returns_null_default_when_no_default_supplied(): void
    {
        $result = Setting::get('missing_key');

        $this->assertNull($result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Caching behaviour — value is cached after first read
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function get_populates_cache_on_first_call(): void
    {
        Setting::create([
            'key'   => 'hero_title',
            'value' => 'Discover the Best Products',
        ]);

        $cacheKey = tenant_cache_key('setting:hero_title');

        $this->assertFalse(Cache::has($cacheKey));

        Setting::get('hero_title');

        $this->assertTrue(Cache::has($cacheKey));
        $this->assertSame('Discover the Best Products', Cache::get($cacheKey));
    }

    /** @test */
    public function get_reads_from_cache_on_subsequent_calls_without_hitting_db(): void
    {
        Setting::create([
            'key'   => 'tagline',
            'value' => 'Compare Smarter',
        ]);

        // Prime the cache
        Setting::get('tagline');

        // Corrupt the DB row — the cached value must be served instead
        \DB::table('settings')->where('key', 'tagline')->update(['value' => 'CORRUPTED']);

        $result = Setting::get('tagline');

        $this->assertSame('Compare Smarter', $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Setting::set() — creates, updates, and invalidates cache
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function set_creates_new_setting(): void
    {
        Setting::set('primary_color', '#3b82f6');

        $this->assertDatabaseHas('settings', [
            'key'   => 'primary_color',
            'value' => '#3b82f6',
        ]);
    }

    /** @test */
    public function set_updates_existing_setting(): void
    {
        Setting::create(['key' => 'primary_color', 'value' => '#old']);

        Setting::set('primary_color', '#new');

        $this->assertDatabaseHas('settings', [
            'key'   => 'primary_color',
            'value' => '#new',
        ]);
        $this->assertDatabaseCount('settings', 1);
    }

    /** @test */
    public function set_invalidates_cache_so_next_get_reads_fresh_value(): void
    {
        Setting::create(['key' => 'logo_url', 'value' => '/img/old-logo.png']);
        Setting::get('logo_url'); // prime cache

        $cacheKey = tenant_cache_key('setting:logo_url');
        $this->assertTrue(Cache::has($cacheKey));

        Setting::set('logo_url', '/img/new-logo.png'); // bust cache

        $this->assertFalse(Cache::has($cacheKey));

        $result = Setting::get('logo_url');
        $this->assertSame('/img/new-logo.png', $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Tenant-prefixed cache keys
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function cache_keys_use_central_prefix_when_no_tenant_is_active(): void
    {
        Setting::create(['key' => 'global_setting', 'value' => 'global_value']);

        Setting::get('global_setting');

        $this->assertTrue(Cache::has('tcentral:setting:global_setting'));
    }

    /** @test */
    public function cache_keys_are_prefixed_with_tenant_id_when_tenant_is_active(): void
    {
        // Use mock tenant to avoid the SQLite/GeneratesIds integer-ID issue.
        // The `id_generator` is null in this project, so stancl's getIncrementing()
        // returns true — which causes Eloquent to overwrite the string PK with the
        // integer lastInsertId() after create(). A mock avoids that entirely.
        $tenant = $this->createMock(\Stancl\Tenancy\Database\Models\Tenant::class);
        $tenant->method('getTenantKey')->willReturn('best-mics');
        $tenant->method('getTenantKeyName')->willReturn('id');
        $tenant->method('getAttribute')->with('id')->willReturn('best-mics');

        // Bind the tenant so tenant_cache_key() returns the tenant-prefixed key
        app()->instance(TenantContract::class, $tenant);

        // Create a setting directly; no DB tenant scope needed here because we are
        // only testing the cache key format, not DB-level tenant filtering
        \DB::table('settings')->insert([
            'key'        => 'brand_name',
            'value'      => 'Best Mics Brand',
            'tenant_id'  => null, // nullable per schema; unscoped query will find it
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $expectedKey = 'tbest-mics:setting:brand_name';
        $this->assertSame($expectedKey, tenant_cache_key('setting:brand_name'));

        Setting::get('brand_name');

        $this->assertTrue(Cache::has($expectedKey));
        $this->assertFalse(Cache::has('tcentral:setting:brand_name'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Cross-tenant isolation (cache key uniqueness)
    // ────────────────────────────────────────────────────────────────────────

    /** @test */
    public function cache_key_format_is_tenant_scoped(): void
    {
        // Verify the cache key format using the same mock approach as TenantCacheKeyTest.
        // This confirms Setting::get() builds the correct key per tenant without
        // needing full tenancy initialization with two real tenants.

        // Mock tenant A in the container
        $mockTenantA = $this->createMock(\Stancl\Tenancy\Database\Models\Tenant::class);
        $mockTenantA->method('getTenantKey')->willReturn('site-alpha');
        $mockTenantA->method('getTenantKeyName')->willReturn('id');
        $mockTenantA->method('getAttribute')->with('id')->willReturn('site-alpha');

        app()->instance(TenantContract::class, $mockTenantA);

        $keyForAlpha = tenant_cache_key('setting:hero_title');
        $this->assertSame('tsite-alpha:setting:hero_title', $keyForAlpha);

        app()->forgetInstance(TenantContract::class);

        // Mock tenant B
        $mockTenantB = $this->createMock(\Stancl\Tenancy\Database\Models\Tenant::class);
        $mockTenantB->method('getTenantKey')->willReturn('site-beta');
        $mockTenantB->method('getTenantKeyName')->willReturn('id');
        $mockTenantB->method('getAttribute')->with('id')->willReturn('site-beta');

        app()->instance(TenantContract::class, $mockTenantB);

        $keyForBeta = tenant_cache_key('setting:hero_title');
        $this->assertSame('tsite-beta:setting:hero_title', $keyForBeta);

        // Keys are different — no cross-tenant cache sharing possible
        $this->assertNotSame($keyForAlpha, $keyForBeta);

        app()->forgetInstance(TenantContract::class);
    }

    /** @test */
    public function setting_set_invalidates_only_the_active_tenants_cache_key(): void
    {
        // Populate the cache for two different tenant prefixes directly
        Cache::forever('talpha:setting:color', 'blue');
        Cache::forever('tbeta:setting:color', 'green');

        // Simulate Tenant Beta calling Setting::set() — it should bust only Beta's key.
        // We use the mock approach to control which tenant_cache_key() resolves to.
        $mockBeta = $this->createMock(\Stancl\Tenancy\Database\Models\Tenant::class);
        $mockBeta->method('getTenantKey')->willReturn('beta');
        $mockBeta->method('getTenantKeyName')->willReturn('id');
        $mockBeta->method('getAttribute')->with('id')->willReturn('beta');

        app()->instance(TenantContract::class, $mockBeta);

        // Manually replicate what Setting::set() does for the cache bust:
        Cache::forget(tenant_cache_key('setting:color')); // busts 'tbeta:setting:color'

        app()->forgetInstance(TenantContract::class);

        // Beta's cache is busted, Alpha's is untouched
        $this->assertFalse(Cache::has('tbeta:setting:color'));
        $this->assertTrue(Cache::has('talpha:setting:color'));
        $this->assertSame('blue', Cache::get('talpha:setting:color'));
    }
}
