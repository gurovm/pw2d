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

    /**
     * Spec 038 B2 — production `.env` runs these two models (unchanged since
     * 2026-07-21) and neither was in the pricing map, so every real row on
     * prod recorded estimated_cost_usd = NULL. Exact-value assertions per the
     * Spec 037 lesson (docs/lessons.md) — "not null" proves nothing about
     * whether the arithmetic is actually right.
     */
    /** @test */
    public function gemini_3_1_pro_preview_produces_the_exact_expected_cost(): void
    {
        config(['services.gemini.pricing' => [
            'gemini-3.1-pro-preview' => ['input' => 2.00, 'output' => 12.00],
        ]]);

        $service = new AiUsageService();

        // (1800 * 2.00 + 800 * 12.00) / 1_000_000 = (3600 + 9600) / 1_000_000 = 0.0132
        // Matches the 2026-08-28 log-check finding's real per-product figure exactly.
        $cost = $service->estimateCost('gemini-3.1-pro-preview', 1800, 800, null);

        $this->assertSame(0.0132, $cost);
    }

    /** @test */
    public function gemini_3_5_flash_produces_the_exact_expected_cost(): void
    {
        config(['services.gemini.pricing' => [
            'gemini-3.5-flash' => ['input' => 1.50, 'output' => 9.00],
        ]]);

        $service = new AiUsageService();

        // (1000 * 1.50 + 500 * 9.00) / 1_000_000 = (1500 + 4500) / 1_000_000 = 0.006
        $cost = $service->estimateCost('gemini-3.5-flash', 1000, 500, 0);

        $this->assertSame(0.006, $cost);
    }

    // -------------------------------------------------------------------------
    // Spec 039 T4 — a zero-priced model always costs $0, even with no tokens
    // -------------------------------------------------------------------------

    /** @test */
    public function a_zero_priced_model_prices_to_exact_zero_with_no_token_data_at_all(): void
    {
        config(['services.gemini.pricing' => [
            'claude-code-session' => ['input' => 0.0, 'output' => 0.0],
        ]]);

        $service = new AiUsageService();

        $cost = $service->estimateCost('claude-code-session', null, null, null);

        $this->assertSame(0.0, $cost);
    }

    /** @test */
    public function record_writes_a_zero_cost_row_with_null_tokens_for_a_zero_priced_model(): void
    {
        config(['services.gemini.pricing' => [
            'claude-code-session' => ['input' => 0.0, 'output' => 0.0],
        ]]);

        $service = new AiUsageService();

        $service->record('evaluate_product', 'claude-code-session', []);

        $row = AiUsage::sole();
        $this->assertSame('claude-code-session', $row->model);
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->thinking_tokens);
        $this->assertNotNull($row->estimated_cost_usd, 'cost must be 0.0, not NULL');
        $this->assertEquals(0.0, (float) $row->estimated_cost_usd);
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

    /**
     * Spec 038 A2 — config('services.gemini.admin_model') can resolve to null
     * (e.g. an unset env var, or one literally holding the string "null").
     * estimateProductEvaluationCost() feeds that straight into estimateCost()'s
     * `string $model` parameter; without the (string) cast at the call site,
     * that's a TypeError at the argument boundary — which previously 500'd the
     * entire Filament Products list page (ListProducts.php calls this at
     * header-render time on every page load).
     */
    /** @test */
    public function estimate_product_evaluation_cost_with_a_null_admin_model_returns_null_without_throwing(): void
    {
        config(['services.gemini.admin_model' => null]);

        $service = new AiUsageService();

        $this->assertNull($service->estimateProductEvaluationCost());
    }

    /**
     * Spec 038 A2 — cost is now computed in its own guarded step, separate
     * from the AiUsage::create() write. Previously estimateCost() ran *inside*
     * the write's try block, so a malformed pricing VALUE (a non-numeric
     * string, which throws a TypeError on arithmetic under strict_types) took
     * the whole row down with it — discarding the token counts, the one thing
     * that can never be reconstructed after the fact.
     */
    /** @test */
    public function a_malformed_string_pricing_value_writes_the_row_with_a_null_cost_instead_of_losing_it(): void
    {
        config(['services.gemini.pricing' => [
            'gemini-malformed' => ['input' => 'oops', 'output' => 10.00],
        ]]);

        $service = new AiUsageService();

        $service->record('evaluate_product', 'gemini-malformed', [
            'promptTokenCount'     => 1000,
            'candidatesTokenCount' => 500,
        ]);

        $row = AiUsage::sole();
        $this->assertSame('gemini-malformed', $row->model);
        $this->assertNull($row->estimated_cost_usd);
        // The token counts — the irreplaceable data — must survive even though
        // the cost computation blew up.
        $this->assertSame(1000, $row->input_tokens);
        $this->assertSame(500, $row->output_tokens);
    }

    // -------------------------------------------------------------------------
    // B2 — diagnosable "model not priced" log line
    // -------------------------------------------------------------------------

    /** @test */
    public function an_unpriced_model_logs_exactly_one_warning_per_model_per_service_instance(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $service = new AiUsageService();

        $service->record('evaluate_product', 'gemini-9-unpriced', ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]);
        $service->record('evaluate_product', 'gemini-9-unpriced', ['promptTokenCount' => 20, 'candidatesTokenCount' => 8]);

        // Both calls still write a row — the warning is diagnostics, not a gate.
        $this->assertSame(2, AiUsage::where('model', 'gemini-9-unpriced')->count());
        $this->assertNull(AiUsage::where('model', 'gemini-9-unpriced')->first()->estimated_cost_usd);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->once()
            ->with('AiUsageService: model not in pricing map', \Mockery::on(
                fn (array $context) => $context['model'] === 'gemini-9-unpriced' && $context['purpose'] === 'evaluate_product'
            ));
    }

    /** @test */
    public function a_new_service_instance_warns_again_for_a_previously_warned_model(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        (new AiUsageService())->record('evaluate_product', 'gemini-9-unpriced', ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]);
        (new AiUsageService())->record('evaluate_product', 'gemini-9-unpriced', ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]);

        // The dedupe is instance-scoped ($warnedModels lives on the object), not
        // global — a fresh instance (e.g. the next request) must warn again.
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->twice()
            ->with('AiUsageService: model not in pricing map', \Mockery::any());
    }

    /**
     * A missing-token-data null cost (a genuinely priced model, just no
     * usageMetadata) must NOT trigger the "not in pricing map" warning — that
     * would misdiagnose a perfectly normal empty-metadata response as a
     * pricing gap.
     */
    /** @test */
    public function a_priced_model_with_no_token_data_does_not_log_the_unpriced_warning(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        (new AiUsageService())->record('evaluate_product', 'gemini-2.5-flash', []);

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
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

    /**
     * Review fix L1 (2026-08-28): record() wraps its whole body in an outer
     * try/catch so that even a failure inside the Log:: calls themselves
     * (e.g. a Monolog handler that can't open its stream) can't escape and
     * break the AI call that produced the usage data.
     */
    /** @test */
    public function a_log_failure_while_handling_a_failed_write_does_not_throw(): void
    {
        \Illuminate\Support\Facades\Schema::drop('ai_usage');

        \Illuminate\Support\Facades\Log::shouldReceive('error')
            ->once()
            ->andThrow(new \RuntimeException('log down'));

        $service = new AiUsageService();

        // Must not throw despite both the write AND the resulting error-log
        // call failing — there is nothing left to log with at that point.
        $service->record('evaluate_product', 'gemini-2.5-flash', [
            'promptTokenCount'     => 1000,
            'candidatesTokenCount' => 500,
        ]);

        $this->assertTrue(true); // Reaching this line means record() swallowed both failures.
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

    /**
     * Spec 038 (audit A3): rewritten — 'sweep_category' is a poor choice here.
     * Every real caller of sweepCategoryPollution() is the `pw2d:ai-sweep-category`
     * console command, which explicitly calls tenancy()->initialize($tenant)
     * before making the AI call — so 'sweep_category' DOES have a tenant in
     * production, and labeling it here as the null-tenant case was inverted
     * and misleading. 'unspecified' is the one purpose value that never maps to
     * a real, tenant-bound production call site (every actual caller passes an
     * explicit purpose) — it's GeminiService::generate()'s own default, used
     * only by ad hoc/console calls with no tenant context.
     */
    /** @test */
    public function no_active_tenant_records_a_null_tenant_id(): void
    {
        $service = new AiUsageService();

        $service->record('unspecified', 'gemini-2.5-flash', ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]);

        $row = AiUsage::sole();
        $this->assertNull($row->tenant_id);
    }
}
