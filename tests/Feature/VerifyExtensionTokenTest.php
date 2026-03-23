<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyExtensionTokenTest extends TestCase
{
    use RefreshDatabase;

    private string $validToken = 'test-extension-token-for-testing';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.extension.token' => $this->validToken]);

        // Create a test tenant so requests that pass token validation
        // can also pass the tenancy middleware
        Tenant::create(['id' => 'test-tenant', 'name' => 'Test Tenant']);
    }

    /** @test */
    public function valid_header_token_is_accepted(): void
    {
        $response = $this->getJson('/api/categories', [
            'X-Extension-Token' => $this->validToken,
            'X-Tenant-Id' => 'test-tenant',
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function token_in_query_string_is_rejected(): void
    {
        $response = $this->getJson('/api/categories?token=' . $this->validToken);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Unauthorized.']);
    }

    /** @test */
    public function missing_token_returns_403(): void
    {
        $response = $this->getJson('/api/categories');

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Unauthorized.']);
    }

    /** @test */
    public function wrong_token_returns_403(): void
    {
        $response = $this->getJson('/api/categories', [
            'X-Extension-Token' => 'wrong-token-value',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Unauthorized.']);
    }

    /** @test */
    public function empty_configured_token_blocks_all_requests(): void
    {
        config(['services.extension.token' => '']);

        $response = $this->getJson('/api/categories', [
            'X-Extension-Token' => 'any-token',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Extension token not configured.']);
    }
}
