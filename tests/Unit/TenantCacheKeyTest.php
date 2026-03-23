<?php

namespace Tests\Unit;

use Stancl\Tenancy\Contracts\Tenant;
use Tests\TestCase;

class TenantCacheKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the helper is loaded even if composer dump-autoload hasn't run yet
        require_once __DIR__ . '/../../app/Helpers/cache.php';
    }

    protected function tearDown(): void
    {
        // Unbind tenant after each test to avoid leaking state
        if (app()->bound(Tenant::class)) {
            app()->forgetInstance(Tenant::class);
        }

        parent::tearDown();
    }

    public function test_returns_central_prefix_when_no_tenant_is_active(): void
    {
        // Ensure no tenant is bound
        if (app()->bound(Tenant::class)) {
            app()->forgetInstance(Tenant::class);
        }

        $this->assertSame('tcentral:foo', tenant_cache_key('foo'));
    }

    public function test_prefixes_complex_cache_keys_without_tenant(): void
    {
        $this->assertSame(
            'tcentral:setting:gemini_model',
            tenant_cache_key('setting:gemini_model')
        );
    }

    public function test_prefixes_product_cache_keys_without_tenant(): void
    {
        $this->assertSame(
            'tcentral:products:cat5:b3:p200',
            tenant_cache_key('products:cat5:b3:p200')
        );
    }

    public function test_prefixes_similar_products_key_without_tenant(): void
    {
        $this->assertSame(
            'tcentral:similar_products_42',
            tenant_cache_key('similar_products_42')
        );
    }

    public function test_returns_tenant_prefix_when_tenant_is_active(): void
    {
        $tenant = $this->createMock(\Stancl\Tenancy\Database\Models\Tenant::class);
        $tenant->method('getTenantKey')->willReturn('acme');
        $tenant->method('getTenantKeyName')->willReturn('id');
        $tenant->method('getAttribute')->with('id')->willReturn('acme');

        app()->instance(Tenant::class, $tenant);

        $this->assertSame('tacme:foo', tenant_cache_key('foo'));
    }

    public function test_returns_tenant_prefix_for_complex_keys(): void
    {
        $tenant = $this->createMock(\Stancl\Tenancy\Database\Models\Tenant::class);
        $tenant->method('getTenantKey')->willReturn('best-mics');
        $tenant->method('getTenantKeyName')->willReturn('id');
        $tenant->method('getAttribute')->with('id')->willReturn('best-mics');

        app()->instance(Tenant::class, $tenant);

        $this->assertSame(
            'tbest-mics:setting:gemini_model',
            tenant_cache_key('setting:gemini_model')
        );
    }
}
