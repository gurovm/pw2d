<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FinalizeOutcome;
use App\Exceptions\InvalidProductEvaluation;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\AiUsageService;
use App\Support\ProductEvaluation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Spec 039 T4 — the per-row apply loop behind `pw2d:products:apply-evaluations`.
 * Every product is validated (steps 1–3), then — outside `--dry-run` — applied
 * (steps 4–5) inside its OWN `DB::transaction()`, so one bad row can never
 * roll back another product's already-committed write.
 */
class ApplyProductEvaluations
{
    public function __construct(
        private readonly FinalizeProductEvaluation $finalize,
        private readonly AiUsageService $usage,
    ) {}

    /**
     * @param array<int, mixed> $evaluations Raw decoded `evaluations` array from the input file.
     * @return array{rows: array<int, array{product_id: mixed, outcome: string, detail: string}>, summary: array<string, mixed>}
     */
    public function execute(Tenant $tenant, array $evaluations, string $source, bool $dryRun): array
    {
        $rows = [];

        foreach ($evaluations as $rawRow) {
            $rows[] = $this->applyRow($tenant, $rawRow, $source, $dryRun);
        }

        return [
            'rows'    => $rows,
            'summary' => $this->summarize($rows),
        ];
    }

    /**
     * @return array{product_id: mixed, outcome: string, detail: string, reason: ?string}
     */
    private function applyRow(Tenant $tenant, mixed $rawRow, string $source, bool $dryRun): array
    {
        if (!is_array($rawRow)) {
            return $this->row(null, 'error', 'row is not a JSON object');
        }

        $rawProductId = $rawRow['product_id'] ?? null;

        // Step 1 — ProductEvaluation::fromArray. Invalid → error, nothing written for it.
        try {
            $eval = ProductEvaluation::fromArray($rawRow);
        } catch (InvalidProductEvaluation $e) {
            return $this->row($rawProductId, 'error', $e->getMessage());
        }

        // ProductEvaluation itself accepts ANY non-empty ignore reason (Spec
        // 039 §2 T1 strictness rule — the Gemini path may drift off the
        // four-value vocabulary and must still be usable). File rows are
        // under our control, so THIS is where the enum is enforced — an
        // off-list reason here is an authoring mistake, not model drift.
        if ($eval->isIgnored() && !in_array($eval->reason(), ProductEvaluation::VALID_REASONS, true)) {
            return $this->row($rawProductId, 'error', 'unknown reason "' . $eval->reason() . '"');
        }

        $productId = $this->resolveProductId($rawProductId);

        if ($productId === null) {
            return $this->row($rawProductId, 'error', 'product_id is missing or not an integer');
        }

        // Step 2 — must exist, belong to {tenant} (explicit tenant_id check,
        // not just the global scope), and be pending_ai/failed. Anything
        // else: skipped — this is what makes a re-run of the same file
        // idempotent.
        $product = Product::withoutGlobalScopes()
            ->with('offers.store')
            ->where('id', $productId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$product) {
            return $this->row($productId, 'skipped', 'product not found for this tenant');
        }

        if (!in_array($product->status, ['pending_ai', 'failed'], true)) {
            $status = $product->status ?? 'null (already applied)';
            return $this->row($productId, 'skipped', "status is \"{$status}\", not pending_ai/failed");
        }

        // Step 3 — every feature name the evaluation scored must exist on the
        // product's category.
        $category = Category::withoutGlobalScopes()->with('features')->find($product->category_id);

        if (!$category) {
            return $this->row($productId, 'error', 'product has no category');
        }

        $knownFeatures = $category->features->pluck('name')->all();
        foreach (array_keys($eval->features()) as $featureName) {
            if (!in_array($featureName, $knownFeatures, true)) {
                return $this->row($productId, 'error', "unknown feature \"{$featureName}\" for category \"{$category->name}\"");
            }
        }

        // "Every category feature present" (spec §2 T1/T4, review M2): for a
        // scored row every feature name the category defines must be a KEY in
        // the raw row's features map — an explicit `null` counts as present
        // ("not applicable"), so this checks $rawRow['features'] (guaranteed
        // array here — buildScored() already required it), not
        // $eval->features(), which silently drops null entries.
        if (!$eval->isIgnored()) {
            foreach ($knownFeatures as $featureName) {
                if (!array_key_exists($featureName, $rawRow['features'])) {
                    return $this->row($productId, 'error', "missing feature \"{$featureName}\" for category \"{$category->name}\"");
                }
            }
        }

        if ($dryRun) {
            return $this->predictedRow($productId, $eval);
        }

        // Steps 4 + 5 — atomic per product. A throw here (a matchProduct()
        // HTTP failure, a QueryException, ...) must roll back only THIS
        // product's writes and become an `error` row, never abort the batch
        // (review M1) — DB::transaction() has already rolled the row back by
        // the time the catch below runs.
        try {
            return DB::transaction(function () use ($product, $category, $eval, $source, $tenant, $productId) {
                $outcome = $this->finalize->execute($product, $category, $eval, $source);

                $this->usage->record('evaluate_product', $source, [], $tenant->id);

                Log::info('ApplyProductEvaluations: applied', [
                    'product_id' => $product->id,
                    'outcome'    => $outcome->value,
                    'source'     => $source,
                ]);

                return $this->outcomeRow($productId, $outcome, $eval);
            });
        } catch (\Throwable $e) {
            Log::error('ApplyProductEvaluations: row failed', [
                'product_id' => $product->id,
                'source'     => $source,
                'exception'  => get_class($e),
                'error'      => $e->getMessage(),
            ]);

            return $this->row($productId, 'error', get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * @return array{product_id: mixed, outcome: string, detail: string, reason: ?string}
     */
    private function predictedRow(int $productId, ProductEvaluation $eval): array
    {
        if ($eval->isIgnored()) {
            if ($eval->reason() === 'wrong_category') {
                return $this->row($productId, 'rejected_from_category', 'DRY RUN: would reject from category (wrong_category)');
            }

            return $this->row($productId, 'ignored', "DRY RUN: would ignore ({$eval->reason()})", $eval->reason());
        }

        // Whether this becomes `scored` or `merged` depends on
        // AiService::matchProduct() (an AI call), which --dry-run never
        // invokes — so the best a dry run can report is "would score",
        // flagging that the dedup outcome is only known on a live run.
        return $this->row($productId, 'scored', 'DRY RUN: would score (duplicate-merge check requires a live run)');
    }

    /**
     * @return array{product_id: mixed, outcome: string, detail: string, reason: ?string}
     */
    private function outcomeRow(int $productId, FinalizeOutcome $outcome, ProductEvaluation $eval): array
    {
        return match ($outcome) {
            FinalizeOutcome::Scored               => $this->row($productId, 'scored', 'scored'),
            FinalizeOutcome::Merged                => $this->row($productId, 'merged', 'merged into an existing product'),
            FinalizeOutcome::RejectedFromCategory  => $this->row($productId, 'rejected_from_category', 'rejected from category (wrong_category)'),
            FinalizeOutcome::Ignored               => $this->row($productId, 'ignored', 'ignored (' . ($eval->reason() ?? '') . ')', $eval->reason()),
        };
    }

    /**
     * @return array{product_id: mixed, outcome: string, detail: string, reason: ?string}
     */
    private function row(mixed $productId, string $outcome, string $detail, ?string $reason = null): array
    {
        return ['product_id' => $productId, 'outcome' => $outcome, 'detail' => $detail, 'reason' => $reason];
    }

    private function resolveProductId(mixed $rawProductId): ?int
    {
        if (is_int($rawProductId)) {
            return $rawProductId;
        }

        if (is_string($rawProductId) && ctype_digit($rawProductId)) {
            return (int) $rawProductId;
        }

        return null;
    }

    /**
     * @param array<int, array{product_id: mixed, outcome: string, detail: string, reason: ?string}> $rows
     * @return array{scored: int, ignored: int, ignored_reasons: array<string, int>, merged: int, rejected: int, skipped: int, errors: int}
     */
    private function summarize(array $rows): array
    {
        $counts = ['scored' => 0, 'ignored' => 0, 'merged' => 0, 'rejected' => 0, 'skipped' => 0, 'errors' => 0];
        $ignoredReasons = [];

        foreach ($rows as $row) {
            $bucket = match ($row['outcome']) {
                'scored'                 => 'scored',
                'merged'                 => 'merged',
                'rejected_from_category' => 'rejected',
                'skipped'                => 'skipped',
                'error'                  => 'errors',
                'ignored'                => 'ignored',
                default                  => null,
            };

            if ($bucket === null) {
                continue;
            }

            $counts[$bucket]++;

            if ($bucket === 'ignored') {
                $reason = $row['reason'] ?? 'unknown';
                $ignoredReasons[$reason] = ($ignoredReasons[$reason] ?? 0) + 1;
            }
        }

        $counts['ignored_reasons'] = $ignoredReasons;

        return $counts;
    }
}
