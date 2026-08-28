<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\Tenant;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Spec 037 T1 — end-to-end usage instrumentation through GeminiService::generate().
 *
 * These tests exercise the real HTTP → parse → record path (Http::fake() is the
 * only permitted mock); tests/Unit/AiUsageServiceTest.php covers AiUsageService
 * in isolation.
 */
class AiUsageInstrumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key'    => 'test-api-key',
            'services.gemini.site_model' => 'gemini-2.5-flash',
            'services.gemini.pricing'    => [
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    private function fakeGeminiWithUsage(?array $usageMetadata): void
    {
        $response = [
            'candidates' => [[
                'content'      => ['parts' => [['text' => '{"ok": true}']]],
                'finishReason' => 'STOP',
            ]],
        ];

        if ($usageMetadata !== null) {
            $response['usageMetadata'] = $usageMetadata;
        }

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($response),
        ]);
    }

    /** @test */
    public function usage_row_is_written_on_a_successful_call_with_correct_attribution_and_cost(): void
    {
        Tenant::create(['id' => 'usage-test', 'name' => 'Usage Test Tenant']);
        tenancy()->initialize(Tenant::find('usage-test'));

        $this->fakeGeminiWithUsage([
            'promptTokenCount'     => 1200,
            'candidatesTokenCount' => 400,
            'thoughtsTokenCount'   => 100,
            'totalTokenCount'      => 1700,
        ]);

        $service = new GeminiService();
        $result = $service->generate('Test prompt', [], 'gemini-2.5-flash', 'evaluate_product');

        // The AI call itself still succeeds and returns the parsed result.
        $this->assertSame(['ok' => true], $result['parsed']);

        $row = AiUsage::sole();
        $this->assertSame('usage-test', $row->tenant_id);
        $this->assertSame('evaluate_product', $row->purpose);
        $this->assertSame('gemini-2.5-flash', $row->model);
        $this->assertSame(1200, $row->input_tokens);
        $this->assertSame(400, $row->output_tokens);
        $this->assertSame(100, $row->thinking_tokens);

        // (1200 * 0.30 + (400 + 100) * 2.50) / 1_000_000 = 0.00161
        $this->assertEquals(0.00161, (float) $row->estimated_cost_usd);
    }

    /** @test */
    public function purpose_defaults_when_the_caller_does_not_pass_one(): void
    {
        $this->fakeGeminiWithUsage(['promptTokenCount' => 10, 'candidatesTokenCount' => 5]);

        (new GeminiService())->generate('Test prompt');

        $this->assertSame('unspecified', AiUsage::sole()->purpose);
    }

    /** @test */
    public function unknown_model_writes_a_row_with_null_cost_and_does_not_throw(): void
    {
        $this->fakeGeminiWithUsage(['promptTokenCount' => 1000, 'candidatesTokenCount' => 500]);

        $result = (new GeminiService())->generate('Test prompt', [], 'gemini-9-nonexistent', 'evaluate_product');

        $this->assertSame(['ok' => true], $result['parsed']);

        $row = AiUsage::sole();
        $this->assertSame('gemini-9-nonexistent', $row->model);
        $this->assertNull($row->estimated_cost_usd);
        $this->assertSame(1000, $row->input_tokens);
    }

    /** @test */
    public function missing_usage_metadata_logs_null_tokens_and_does_not_throw(): void
    {
        $this->fakeGeminiWithUsage(null);

        $result = (new GeminiService())->generate('Test prompt', [], 'gemini-2.5-flash', 'chat_response');

        $this->assertSame(['ok' => true], $result['parsed']);

        $row = AiUsage::sole();
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertNull($row->thinking_tokens);
        $this->assertNull($row->estimated_cost_usd);
    }

    /**
     * Spec 038 A2 — a null `services.gemini.site_model` config value (e.g. an
     * unset/misconfigured env var) must never reach record()'s `string $model`
     * parameter as a TypeError at the call boundary. GeminiService::generate()
     * casts to string, so a null config resolves to '' rather than throwing.
     */
    /** @test */
    public function a_null_site_model_config_does_not_throw_and_records_an_empty_string_model(): void
    {
        config(['services.gemini.site_model' => null]);

        $this->fakeGeminiWithUsage(['promptTokenCount' => 10, 'candidatesTokenCount' => 5]);

        $result = (new GeminiService())->generate('Test prompt', [], null, 'evaluate_product');

        $this->assertSame(['ok' => true], $result['parsed']);

        $row = AiUsage::sole();
        $this->assertSame('', $row->model);
        $this->assertNull($row->estimated_cost_usd);
        $this->assertSame(10, $row->input_tokens);
    }

    /** @test */
    public function a_usage_write_failure_does_not_fail_the_ai_call(): void
    {
        $this->fakeGeminiWithUsage(['promptTokenCount' => 1000, 'candidatesTokenCount' => 500]);

        // Simulate an accounting-layer failure (e.g. a migration not yet run in prod).
        Schema::drop('ai_usage');

        $result = (new GeminiService())->generate('Test prompt', [], 'gemini-2.5-flash', 'evaluate_product');

        // The AI result must still be returned even though the usage write failed.
        $this->assertSame(['ok' => true], $result['parsed']);
    }

    /**
     * Spec 038 audit A3 flagged this path as entirely untested: `GeminiService::
     * generate()` calls `AiUsageService::record()` immediately after decoding the
     * body — BEFORE throwing on `finishReason === 'MAX_TOKENS'` (see the comment
     * at GeminiService.php:92, "Record usage even on truncation below — tokens
     * were billed either way"). A truncated call still consumes real, billed
     * tokens; if the row were only written on the success path, the calls most
     * likely to be expensive (the ones hitting the maxOutputTokens ceiling) would
     * be silently under-reported. This asserts both halves: the exception still
     * propagates AND the row still lands with the real token counts.
     */
    /** @test */
    public function a_max_tokens_truncation_still_records_usage_before_the_exception_propagates(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content'      => ['parts' => [['text' => '{"partial": "data']]],
                    'finishReason' => 'MAX_TOKENS',
                ]],
                'usageMetadata' => [
                    'promptTokenCount'     => 2000,
                    'candidatesTokenCount' => 3000,
                ],
            ]),
        ]);

        try {
            (new GeminiService())->generate('Test prompt', [], 'gemini-2.5-flash', 'evaluate_product');
            $this->fail('Expected the MAX_TOKENS truncation exception to propagate.');
        } catch (\Exception $e) {
            $this->assertSame('Gemini response truncated (MAX_TOKENS).', $e->getMessage());
        }

        $row = AiUsage::sole();
        $this->assertSame('evaluate_product', $row->purpose);
        $this->assertSame(2000, $row->input_tokens);
        $this->assertSame(3000, $row->output_tokens);
        // (2000 * 0.30 + 3000 * 2.50) / 1_000_000 = 0.0006 + 0.0075 = 0.0081
        $this->assertEquals(0.0081, (float) $row->estimated_cost_usd);
    }

    /**
     * Spec 038 (audit A3): rewritten — the original version of this test
     * manually initialized tenancy before an `evaluate_product` call, a state
     * that NEVER holds in production (ProcessPendingProduct never calls
     * tenancy()->initialize(); see AiUsage's model docblock). That made the
     * test validate the working path while giving false confidence about the
     * broken one. `evaluate_product` is exclusively a queued-job purpose, so
     * this now exercises it the way it's actually called: no initialized
     * tenancy, tenant threaded explicitly through generate()'s $tenantId param
     * (spec 038 B1).
     */
    /** @test */
    public function usage_row_written_for_one_tenant_is_not_attributed_to_another(): void
    {
        Tenant::create(['id' => 'usage-tenant-a', 'name' => 'Tenant A']);
        Tenant::create(['id' => 'usage-tenant-b', 'name' => 'Tenant B']);

        $this->fakeGeminiWithUsage(['promptTokenCount' => 100, 'candidatesTokenCount' => 50]);

        $this->assertFalse(tenancy()->initialized, 'precondition: evaluate_product never runs with tenancy initialized in production');

        (new GeminiService())->generate('Test prompt', [], 'gemini-2.5-flash', 'evaluate_product', 'usage-tenant-a');
        (new GeminiService())->generate('Test prompt', [], 'gemini-2.5-flash', 'evaluate_product', 'usage-tenant-b');

        $this->assertSame(1, AiUsage::where('tenant_id', 'usage-tenant-a')->count());
        $this->assertSame(1, AiUsage::where('tenant_id', 'usage-tenant-b')->count());
        $this->assertSame(2, AiUsage::count());
    }
}
