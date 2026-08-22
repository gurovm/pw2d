<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AiUsage;
use App\Models\Tenant;
use App\Services\AiUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 037 T1 — AiUsageService cost arithmetic + safety rules.
 */
class AiUsageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.pricing' => [
            'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
        ]]);
    }

    // -------------------------------------------------------------------------
    // Cost arithmetic
    // -------------------------------------------------------------------------

    /** @test */
    public function cost_arithmetic_is_correct_for_a_known_model(): void
    {
        $service = new AiUsageService();

        // input: 1000 * 0.30/M = 0.0003
        // output+thinking: (500 + 100) * 2.50/M = 0.0015
        // total: 0.0018
        $cost = $service->estimateCost('gemini-2.5-flash', 1000, 500, 100);

        $this->assertSame(0.0018, $cost);
    }

    /** @test */
    public function thinking_tokens_bill_at_the_output_rate(): void
    {
        $service = new AiUsageService();

        $withThinking = $service->estimateCost('gemini-2.5-flash', 0, 0, 1000);
        $withOutput   = $service->estimateCost('gemini-2.5-flash', 0, 1000, 0);

        $this->assertSame($withOutput, $withThinking);
        $this->assertSame(0.0025, $withThinking);
    }

    /** @test */
    public function spec_worked_example_for_evaluate_product_on_admin_model(): void
    {
        config(['services.gemini.pricing' => [
            'gemini-2.5-pro' => ['input' => 1.25, 'output' => 10.00],
        ]]);

        $service = new AiUsageService();

        // Spec 037 §1: ~1,800 in / ~800 out ≈ $0.0103 per product.
        $cost = $service->estimateCost('gemini-2.5-pro', 1800, 800, null);

        $this->assertEquals(0.01025, $cost);
    }

    // -------------------------------------------------------------------------
    // Hard safety rule 1 — unknown model never throws
    // -------------------------------------------------------------------------

    /** @test */
    public function unknown_model_returns_null_cost_without_throwing(): void
    {
        $service = new AiUsageService();

        $cost = $service->estimateCost('some-future-model-nobody-priced-yet', 1000, 500, 0);

        $this->assertNull($cost);
    }

    /** @test */
    public function record_persists_a_row_with_null_cost_for_an_unknown_model(): void
    {
        $service = new AiUsageService();

        $service->record('evaluate_product', 'some-future-model-nobody-priced-yet', [
            'promptTokenCount'     => 1000,
            'candidatesTokenCount' => 500,
        ]);

        $row = AiUsage::sole();
        $this->assertSame('some-future-model-nobody-priced-yet', $row->model);
        $this->assertNull($row->estimated_cost_usd);
        $this->assertSame(1000, $row->input_tokens);
    }

    /** @test */
    public function missing_usage_metadata_logs_null_tokens_without_throwing(): void
    {
        $service = new AiUsageService();

        $service->record('evaluate_product', 'gemini-2.5-flash', []);

        $row = AiUsage::sole();
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->thinking_tokens);
        $this->assertNull($row->estimated_cost_usd);
    }

    // -------------------------------------------------------------------------
    // Hard safety rule 2 — a failed write never throws
    // -------------------------------------------------------------------------

    /** @test */
    public function a_failed_write_does_not_throw(): void
    {
        \Illuminate\Support\Facades\Schema::drop('ai_usage');

        $service = new AiUsageService();

        // Must not throw despite the table being gone.
        $service->record('evaluate_product', 'gemini-2.5-flash', [
            'promptTokenCount'     => 1000,
            'candidatesTokenCount' => 500,
        ]);

        $this->assertTrue(true); // Reaching this line means record() swallowed the failure.
    }

    // -------------------------------------------------------------------------
    // Tenant scoping
    // -------------------------------------------------------------------------

    /** @test */
    public function tenant_scoping_attributes_rows_to_the_active_tenant_only(): void
    {
        Tenant::create(['id' => 'tenant-a', 'name' => 'Tenant A']);
        Tenant::create(['id' => 'tenant-b', 'name' => 'Tenant B']);

        $service = new AiUsageService();

        tenancy()->initialize(Tenant::find('tenant-a'));
        $service->record('evaluate_product', 'gemini-2.5-flash', ['promptTokenCount' => 100, 'candidatesTokenCount' => 50]);
        tenancy()->end();

        tenancy()->initialize(Tenant::find('tenant-b'));
        $service->record('evaluate_product', 'gemini-2.5-flash', ['promptTokenCount' => 200, 'candidatesTokenCount' => 60]);
        tenancy()->end();

        $tenantARows = AiUsage::where('tenant_id', 'tenant-a')->get();
        $tenantBRows = AiUsage::where('tenant_id', 'tenant-b')->get();

        $this->assertCount(1, $tenantARows);
        $this->assertSame(100, $tenantARows->first()->input_tokens);

        $this->assertCount(1, $tenantBRows);
        $this->assertSame(200, $tenantBRows->first()->input_tokens);

        // Tenant A's row must never surface under Tenant B's attribution.
        $this->assertFalse($tenantBRows->contains('id', $tenantARows->first()->id));
    }

    /** @test */
    public function no_active_tenant_records_a_null_tenant_id(): void
    {
        $service = new AiUsageService();

        $service->record('sweep_category', 'gemini-2.5-flash', ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]);

        $row = AiUsage::sole();
        $this->assertNull($row->tenant_id);
    }
}
