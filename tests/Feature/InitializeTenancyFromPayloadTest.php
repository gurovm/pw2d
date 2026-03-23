<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitializeTenancyFromPayloadTest extends TestCase
{
    use RefreshDatabase;

    private string $validToken = 'test-extension-token-for-testing';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.extension.token' => $this->validToken]);
    }

    /** @test */
    public function request_without_tenant_id_returns_422(): void
    {
        $response = $this->getJson('/api/categories', [
            'X-Extension-Token' => $this->validToken,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Tenant ID required.']);
    }

    /** @test */
    public function request_with_invalid_tenant_id_returns_404(): void
    {
        $response = $this->getJson('/api/categories', [
            'X-Extension-Token' => $this->validToken,
            'X-Tenant-Id' => 'nonexistent-tenant',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Invalid tenant.']);
    }

    /** @test */
    public function request_with_valid_tenant_id_initializes_tenancy(): void
    {
        $tenant = Tenant::create(['id' => 'test-tenant', 'name' => 'Test Tenant']);

        $response = $this->getJson('/api/categories', [
            'X-Extension-Token' => $this->validToken,
            'X-Tenant-Id' => 'test-tenant',
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function tenant_id_from_input_parameter_works_as_fallback(): void
    {
        $tenant = Tenant::create(['id' => 'test-tenant', 'name' => 'Test Tenant']);

        $response = $this->getJson('/api/categories?tenant_id=test-tenant', [
            'X-Extension-Token' => $this->validToken,
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function header_takes_precedence_over_input_parameter(): void
    {
        $tenant = Tenant::create(['id' => 'real-tenant', 'name' => 'Real Tenant']);

        // Header has valid tenant, input has invalid — should succeed via header
        $response = $this->getJson('/api/categories?tenant_id=nonexistent', [
            'X-Extension-Token' => $this->validToken,
            'X-Tenant-Id' => 'real-tenant',
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function post_endpoint_requires_tenant_id(): void
    {
        $response = $this->postJson('/api/products/batch-import', [
            'category_id' => 1,
            'products' => [],
        ], [
            'X-Extension-Token' => $this->validToken,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Tenant ID required.']);
    }
}
