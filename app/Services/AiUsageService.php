<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsage;
use Illuminate\Support\Facades\Log;

/**
 * Owns AI usage/cost accounting: turning a Gemini `usageMetadata` block into
 * a persisted `ai_usage` row, and pricing lookups derived from
 * config('services.gemini.pricing').
 *
 * Two hard rules, per spec 037 T1:
 *   - An unknown/unpriced model must never throw — it logs tokens with a null cost.
 *   - A failure writing the usage row must never fail the AI call that produced it.
 */
class AiUsageService
{
    /** Representative evaluateProduct() token shape (spec 037 §1), used only for
     *  operator-facing cost copy — not live telemetry. Once ai_usage has real
     *  evaluate_product rows, prefer AVG(...)-driven copy instead. */
    private const TYPICAL_EVALUATE_PRODUCT_INPUT_TOKENS = 1800;
    private const TYPICAL_EVALUATE_PRODUCT_OUTPUT_TOKENS = 800;

    /**
     * B2: emit the "model not in pricing map" warning once per model per
     * service instance, not once per call — an unpriced model used inside a
     * hot loop (e.g. a category import) would otherwise flood the log.
     *
     * @var array<int, string>
     */
    private array $warnedModels = [];

    /**
     * Record one Gemini call. Never throws — failures are logged and swallowed
     * so accounting can never break the AI pipeline.
     *
     * @param array<string, mixed> $usageMetadata Gemini's raw `usageMetadata` block (may be empty/absent)
     */
    public function record(string $purpose, string $model, array $usageMetadata, ?string $tenantId = null): void
    {
        // Review fix L1 (2026-08-28): the whole body is wrapped in one outer
        // guard. Without it, a Monolog handler failure (e.g. an unwritable
        // storage/logs stream) inside either Log:: call below would propagate
        // straight out of record() and into GeminiService::generate() — after
        // a successful, billed HTTP call — breaking the very guarantee this
        // class exists for. The inner structure (guarded cost step, guarded
        // write, warning, error log) is unchanged; this is a last-resort net.
        try {
            $inputTokens    = $usageMetadata['promptTokenCount'] ?? null;
            $outputTokens   = $usageMetadata['candidatesTokenCount'] ?? null;
            $thinkingTokens = $usageMetadata['thoughtsTokenCount'] ?? null;
            $resolvedTenantId = $tenantId ?? tenant('id');

            // A2: cost is computed in its own guarded step, separate from the write
            // below. Previously this was computed *inside* the write's try block,
            // so a malformed pricing entry (or any other cost-calculation failure)
            // discarded the whole row — including the token counts, the one thing
            // that can never be reconstructed. A pricing failure must yield a
            // null-cost row, never a missing row.
            try {
                $cost = $this->estimateCost($model, $inputTokens, $outputTokens, $thinkingTokens);
            } catch (\Throwable) {
                $cost = null;
            }

            // B2: null cost because the model genuinely isn't in the pricing map
            // (as opposed to null because there's simply no token data) is worth
            // a diagnosable log line — but only once per model per instance.
            if ($cost === null && !$this->isModelPriced($model) && !in_array($model, $this->warnedModels, true)) {
                $this->warnedModels[] = $model;
                Log::warning('AiUsageService: model not in pricing map', [
                    'model'   => $model,
                    'purpose' => $purpose,
                ]);
            }

            try {
                AiUsage::create([
                    'tenant_id'          => $resolvedTenantId,
                    'purpose'            => $purpose,
                    'model'              => $model,
                    'input_tokens'       => $inputTokens,
                    'output_tokens'      => $outputTokens,
                    'thinking_tokens'    => $thinkingTokens,
                    'estimated_cost_usd' => $cost,
                ]);
            } catch (\Throwable $e) {
                // A4: carries enough (tenant, all three token counts, exception
                // class + message) to reconstruct a lost row from the log alone —
                // the old line only had purpose/model/getMessage().
                Log::error('AiUsageService: failed to record AI usage', [
                    'tenant_id'       => $resolvedTenantId,
                    'purpose'         => $purpose,
                    'model'           => $model,
                    'input_tokens'    => $inputTokens,
                    'output_tokens'   => $outputTokens,
                    'thinking_tokens' => $thinkingTokens,
                    'exception'       => get_class($e),
                    'error'           => $e->getMessage(),
                ]);
            }
        } catch (\Throwable) {
            // Accounting must never break the AI call, and there is nothing
            // left to log with — a Log:: call is what just failed.
        }
    }

    /**
     * Compute cost in USD from config('services.gemini.pricing'), which is keyed
     * by model and expressed in $/million tokens. Thinking tokens bill at the
     * output rate. Returns null for an unpriced/unknown model or when there is
     * no token data at all — never throws.
     */
    public function estimateCost(
        string $model,
        ?int $inputTokens,
        ?int $outputTokens,
        ?int $thinkingTokens = null,
    ): ?float {
        $pricing = $this->getPricing($model);

        if ($pricing === null) {
            return null;
        }

        if ($inputTokens === null && $outputTokens === null && $thinkingTokens === null) {
            return null;
        }

        $billedInput  = $inputTokens ?? 0;
        $billedOutput = ($outputTokens ?? 0) + ($thinkingTokens ?? 0);

        $cost = ($billedInput * $pricing['input'] + $billedOutput * $pricing['output']) / 1_000_000;

        return round($cost, 6);
    }

    /**
     * Rough per-product cost estimate for evaluateProduct() on the current
     * admin_model, for operator-facing copy (e.g. the Filament "Retry Failed"
     * modal). Null if admin_model isn't in the pricing table.
     */
    public function estimateProductEvaluationCost(): ?float
    {
        return $this->estimateCost(
            // A2: config('services.gemini.admin_model') can resolve to null
            // (e.g. an env var literally set to the string "null") — cast so
            // estimateCost()'s `string $model` parameter never TypeErrors at
            // the call boundary. That TypeError previously 500'd the entire
            // Filament Products list page (ListProducts.php calls this method
            // at header-render time on every page load).
            (string) config('services.gemini.admin_model'),
            self::TYPICAL_EVALUATE_PRODUCT_INPUT_TOKENS,
            self::TYPICAL_EVALUATE_PRODUCT_OUTPUT_TOKENS,
        );
    }

    /**
     * Look up the pricing entry for a model, validating its shape.
     * Deliberately not config("services.gemini.pricing.{$model}") — Gemini model
     * names contain literal dots (e.g. "gemini-2.5-flash"), which Laravel's dot
     * notation would otherwise misparse as nested keys. Fetch the whole map and
     * index by the literal model string instead.
     *
     * @return array{input: float, output: float}|null
     */
    private function getPricing(string $model): ?array
    {
        $pricing = config('services.gemini.pricing')[$model] ?? null;

        return (is_array($pricing) && isset($pricing['input'], $pricing['output']))
            ? $pricing
            : null;
    }

    private function isModelPriced(string $model): bool
    {
        return $this->getPricing($model) !== null;
    }
}
