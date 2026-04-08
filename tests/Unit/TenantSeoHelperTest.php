<?php

declare(strict_types=1);

namespace Tests\Unit;

use Stancl\Tenancy\Contracts\Tenant;
use Tests\TestCase;

/**
 * Unit tests for the tenant_seo() helper function.
 *
 * Uses the same mock-tenant injection pattern as TenantCacheKeyTest — bind a
 * mock into the container so tenant() reads attributes from it, then unbind
 * in tearDown to prevent state leaking between tests.
 */
class TenantSeoHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../app/Helpers/cache.php';
    }

    protected function tearDown(): void
    {
        if (app()->bound(Tenant::class)) {
            app()->forgetInstance(Tenant::class);
        }

        parent::tearDown();
    }

    // ── No-tenant (central domain) fallbacks ────────────────────────────────

    public function test_title_suffix_defaults_to_pw2d_when_no_tenant(): void
    {
        if (app()->bound(Tenant::class)) {
            app()->forgetInstance(Tenant::class);
        }

        $this->assertSame('Pw2D', tenant_seo('title_suffix'));
    }

    public function test_default_title_contains_pw2d_when_no_tenant(): void
    {
        if (app()->bound(Tenant::class)) {
            app()->forgetInstance(Tenant::class);
        }

        $this->assertSame('Pw2D — AI Product Recommendations', tenant_seo('default_title'));
    }

    public function test_default_description_contains_pw2d_when_no_tenant(): void
    {
        if (app()->bound(Tenant::class)) {
            app()->forgetInstance(Tenant::class);
        }

        $result = tenant_seo('default_description');
        $this->assertStringContainsString('Pw2D', $result);
        $this->assertStringContainsString('AI-powered', $result);
    }

    public function test_unknown_key_returns_null(): void
    {
        $this->assertNull(tenant_seo('nonexistent_key'));
    }

    // ── Tenant with brand_name but no explicit seo_* keys ───────────────────

    public function test_title_suffix_falls_back_to_brand_name_when_seo_key_unset(): void
    {
        $this->bindMockTenant('acme', ['brand_name' => 'Acme Shop']);

        $this->assertSame('Acme Shop', tenant_seo('title_suffix'));
    }

    public function test_default_title_falls_back_to_brand_name_when_seo_key_unset(): void
    {
        $this->bindMockTenant('acme', ['brand_name' => 'Acme Shop']);

        $this->assertSame('Acme Shop — AI Product Recommendations', tenant_seo('default_title'));
    }

    public function test_default_description_falls_back_to_brand_name_when_seo_key_unset(): void
    {
        $this->bindMockTenant('acme', ['brand_name' => 'Acme Shop']);

        $result = tenant_seo('default_description');
        $this->assertStringContainsString('Acme Shop', $result);
    }

    // ── Tenant with explicit seo_* keys set ─────────────────────────────────

    public function test_title_suffix_returns_explicit_value_when_set(): void
    {
        $this->bindMockTenant('acme', [
            'brand_name'        => 'Acme Shop',
            'seo_title_suffix'  => 'Acme | Best Deals',
        ]);

        $this->assertSame('Acme | Best Deals', tenant_seo('title_suffix'));
    }

    public function test_default_title_returns_explicit_value_when_set(): void
    {
        $this->bindMockTenant('acme', [
            'brand_name'        => 'Acme Shop',
            'seo_default_title' => 'Acme Shop - Custom Title',
        ]);

        $this->assertSame('Acme Shop - Custom Title', tenant_seo('default_title'));
    }

    public function test_default_description_returns_explicit_value_when_set(): void
    {
        $this->bindMockTenant('acme', [
            'brand_name'              => 'Acme Shop',
            'seo_default_description' => 'A custom description for Acme.',
        ]);

        $this->assertSame('A custom description for Acme.', tenant_seo('default_description'));
    }

    public function test_default_image_returns_explicit_value_when_set(): void
    {
        $this->bindMockTenant('acme', [
            'brand_name'        => 'Acme Shop',
            'seo_default_image' => 'https://acme.com/og-image.png',
        ]);

        $this->assertSame('https://acme.com/og-image.png', tenant_seo('default_image'));
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    /**
     * Bind a mock tenant into the container with the given attribute map.
     *
     * @param  string               $id
     * @param  array<string,string> $attributes
     */
    private function bindMockTenant(string $id, array $attributes): void
    {
        $tenant = $this->createMock(\Stancl\Tenancy\Database\Models\Tenant::class);
        $tenant->method('getTenantKey')->willReturn($id);
        $tenant->method('getTenantKeyName')->willReturn('id');
        $tenant->method('getAttribute')->willReturnCallback(
            fn (string $key) => $attributes[$key] ?? null
        );

        app()->instance(Tenant::class, $tenant);
    }
}
