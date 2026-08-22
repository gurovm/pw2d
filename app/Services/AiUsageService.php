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
     * Record one Gemini call. Never throws — failures are logged and swallowed
     * so accounting can never break the AI pipeline.
     *
     * @param array<string, mixed> $usageMetadata Gemini's raw `usageMetadata` block (may be empty/absent)
     */
    public function record(string $purpose, string $model, array $usageMetadata, ?string $tenantId = null): void
    {
        try {
            $inputTokens    = $usageMetadata['promptTokenCount'] ?? null;
            $outputTokens   = $usageMetadata['candidatesTokenCount'] ?? null;
            $thinkingTokens = $usageMetadata['thoughtsTokenCount'] ?? null;

            AiUsage::create([
                'tenant_id'          => $tenantId ?? tenant('id'),
                'purpose'            => $purpose,
                'model'              => $model,
                'input_tokens'       => $inputTokens,
                'output_tokens'      => $outputTokens,
                'thinking_tokens'    => $thinkingTokens,
                'estimated_cost_usd' => $this->estimateCost($model, $inputTokens, $outputTokens, $thinkingTokens),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AiUsageService: failed to record AI usage', [
                'purpose' => $purpose,
                'model'   => $model,
                'error'   => $e->getMessage(),
            ]);
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
        // Deliberately not config("services.gemini.pricing.{$model}") — Gemini model
        // names contain literal dots (e.g. "gemini-2.5-flash"), which Laravel's dot
        // notation would otherwise misparse as nested keys. Fetch the whole map and
        // index by the literal model string instead.
        $pricing = config('services.gemini.pricing')[$model] ?? null;

        if (!is_array($pricing) || !isset($pricing['input'], $pricing['output'])) {
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
            config('services.gemini.admin_model'),
            self::TYPICAL_EVALUATE_PRODUCT_INPUT_TOKENS,
            self::TYPICAL_EVALUATE_PRODUCT_OUTPUT_TOKENS,
        );
    }
}
