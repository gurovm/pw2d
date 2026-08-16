<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/extension/tenants — the tenant directory that populates the Chrome
 * extension's settings select box.
 *
 * Unlike every other extension route this one is registered under
 * VerifyExtensionToken ONLY. The regression that matters most here is that it
 * keeps working with NO X-Tenant-Id header at all — see
 * it_succeeds_with_no_tenant_id_header_present().
 */
class ExtensionTenantListTest extends TestCase
{
    use RefreshDatabase;

    private string $validToken = 'test-extension-token-for-testing';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.extension.token' => $this->validToken]);
    }

    /** @param array<string, string> $extra */
    private function tokenHeaders(array $extra = []): array
    {
        return array_merge(['X-Extension-Token' => $this->validToken], $extra);
    }

    /** @test */
    public function it_returns_all_tenants_ordered_by_name(): void
    {
        // Created out of alphabetical order to prove the ordering is applied.
        Tenant::create(['id' => 'zed-tenant', 'name' => 'Coffee Decide']);
        Tenant::create(['id' => 'aaa-tenant', 'name' => 'Best Mics']);
        Tenant::create(['id' => 'mid-tenant', 'name' => 'Audio Gear']);

        $response = $this->getJson('/api/extension/tenants', $this->tokenHeaders());

        $response->assertOk();
        $response->assertJson(['success' => true]);

        // Ordered by name, NOT by id or insertion order.
        $response->assertExactJson([
            'success' => true,
            'tenants' => [
                ['id' => 'mid-tenant', 'name' => 'Audio Gear'],
                ['id' => 'aaa-tenant', 'name' => 'Best Mics'],
                ['id' => 'zed-tenant', 'name' => 'Coffee Decide'],
            ],
        ]);
    }

    /** @test */
    public function every_row_carries_exactly_an_id_and_a_name(): void
    {
        Tenant::create(['id' => 'shape-tenant', 'name' => 'Shape Tenant']);

        $tenants = $this->getJson('/api/extension/tenants', $this->tokenHeaders())
            ->assertOk()
            ->json('tenants');

        $this->assertCount(1, $tenants);
        // Exact key set — the `data` JSON column (branding/settings) must not leak.
        $this->assertSame(['id', 'name'], array_keys($tenants[0]));
    }

    /** @test */
    public function it_returns_an_empty_list_rather_than_erroring_when_no_tenants_exist(): void
    {
        $this->getJson('/api/extension/tenants', $this->tokenHeaders())
            ->assertOk()
            ->assertExactJson(['success' => true, 'tenants' => []]);
    }

    /** @test */
    public function it_falls_back_to_the_id_as_the_label_when_the_name_is_blank(): void
    {
        Tenant::create(['id' => 'nameless-tenant', 'name' => '']);

        $tenants = $this->getJson('/api/extension/tenants', $this->tokenHeaders())
            ->assertOk()
            ->json('tenants');

        $this->assertSame('nameless-tenant', $tenants[0]['id']);
        $this->assertSame('nameless-tenant', $tenants[0]['name']);
    }

    // -------------------------------------------------------------------------
    // The regression that matters: NOT behind InitializeTenancyFromPayload
    // -------------------------------------------------------------------------

    /** @test */
    public function it_succeeds_with_no_tenant_id_header_present(): void
    {
        // The entire point of the endpoint: discover ids BEFORE one is chosen.
        // Behind InitializeTenancyFromPayload this would 422 "Tenant ID required."
        Tenant::create(['id' => 'discoverable', 'name' => 'Discoverable']);

        $response = $this->getJson('/api/extension/tenants', $this->tokenHeaders());

        $response->assertOk();
        $response->assertJsonPath('tenants.0.id', 'discoverable');
        $response->assertJsonMissing(['error' => 'Tenant ID required.']);
    }

    /** @test */
    public function an_unknown_tenant_id_header_does_not_break_the_lookup(): void
    {
        // A stale saved id in chrome.storage.local would 404 "Invalid tenant."
        // behind the tenancy middleware — locking the owner out of the very list
        // that would let them fix it. The header must simply be ignored here.
        Tenant::create(['id' => 'real-tenant', 'name' => 'Real Tenant']);

        $this->getJson('/api/extension/tenants', $this->tokenHeaders([
            'X-Tenant-Id' => 'a-tenant-that-does-not-exist',
        ]))
            ->assertOk()
            ->assertJsonPath('tenants.0.id', 'real-tenant');
    }

    /** @test */
    public function it_lists_every_tenant_regardless_of_which_tenant_id_header_is_sent(): void
    {
        Tenant::create(['id' => 'tenant-a', 'name' => 'Tenant A']);
        Tenant::create(['id' => 'tenant-b', 'name' => 'Tenant B']);

        $ids = collect($this->getJson('/api/extension/tenants', $this->tokenHeaders([
            'X-Tenant-Id' => 'tenant-a',
        ]))->json('tenants'))->pluck('id')->all();

        // Not scoped to the requesting tenant — a directory, by design.
        $this->assertSame(['tenant-a', 'tenant-b'], $ids);
    }

    // -------------------------------------------------------------------------
    // Token gate
    // -------------------------------------------------------------------------

    /** @test */
    public function it_returns_403_without_an_extension_token(): void
    {
        Tenant::create(['id' => 'secret-tenant', 'name' => 'Secret Tenant']);

        $response = $this->getJson('/api/extension/tenants');

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Unauthorized.']);
        $response->assertDontSee('secret-tenant');
    }

    /** @test */
    public function it_returns_403_with_a_wrong_extension_token(): void
    {
        Tenant::create(['id' => 'secret-tenant', 'name' => 'Secret Tenant']);

        $response = $this->getJson('/api/extension/tenants', [
            'X-Extension-Token' => 'wrong-token-value',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Unauthorized.']);
        $response->assertDontSee('secret-tenant');
    }

    /** @test */
    public function it_returns_403_when_no_token_is_configured_server_side(): void
    {
        config(['services.extension.token' => '']);

        $this->getJson('/api/extension/tenants', ['X-Extension-Token' => 'any-token'])
            ->assertStatus(403)
            ->assertJson(['error' => 'Extension token not configured.']);
    }
}
