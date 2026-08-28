<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CompareProductEvaluations;
use App\Models\Tenant;
use App\Support\EvaluationComparison;
use Illuminate\Console\Command;

/**
 * Spec 037 §2 T2 / Spec 039 §2 T5 — `pw2d:ai:eval-model`.
 *
 * This build ships ONLY the `--from-file` read-only diff path (Spec 039 T5):
 * it reads a `pw2d:products:apply-evaluations`-shaped file and diffs each row
 * against the product's stored evaluation via
 * {@see \App\Actions\CompareProductEvaluations}. `--model=`, `--category=`,
 * and `--limit=` (the live-candidate-model runner from Spec 037 T2) are
 * intentionally NOT implemented here — a future pass adds them, building the
 * candidate-evaluations array this class already knows how to diff, and wires
 * `--from-file` as an alternative to that runner rather than the only path.
 *
 * Writes nothing to any table. Makes no AI calls.
 */
class EvalModelCommand extends Command
{
    protected $signature = 'pw2d:ai:eval-model
                            {tenant : The tenant ID}
                            {--from-file= : Path to a pw2d:products:apply-evaluations-shaped evaluations file (required for now — see class docblock)}
                            {--json= : Write the full result (aggregates + per-product diffs) to this file path}';

    protected $description = 'Spec 039 T5 — diff an evaluations file against stored product evaluations (read-only)';

    public function handle(CompareProductEvaluations $action): int
    {
        $tenantId = $this->argument('tenant');
        $tenant   = Tenant::find($tenantId);

        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return self::FAILURE;
        }

        $fromFile = $this->option('from-file');

        if (!$fromFile) {
            $this->error('--from-file is required (no live-model runner is built yet — see Spec 037 T2 / Spec 039 T5).');
            return self::FAILURE;
        }

        if (!is_file($fromFile)) {
            $this->error("File not found: {$fromFile}");
            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($fromFile), true);

        if (!is_array($decoded) || !isset($decoded['evaluations']) || !is_array($decoded['evaluations'])) {
            $this->error('Invalid input file: expected a JSON object with an "evaluations" array.');
            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            $result = $action->execute($tenant, $decoded['evaluations']);
        } finally {
            tenancy()->end();
        }

        $this->render($result);

        $jsonPath = $this->option('json');
        if ($jsonPath) {
            file_put_contents(
                $jsonPath,
                json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            $this->line("Full result written to {$jsonPath}");
        }

        return $result->gate === 'pass' ? self::SUCCESS : self::FAILURE;
    }

    private function render(EvaluationComparison $result): void
    {
        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows (total / compared / unmatched)', "{$result->totalRows} / {$result->comparedRows} / {$result->unmatchedRows}"],
                ['is_ignored agreement', $this->rate($result->ignoreAgreementRate, $result->ignoreAgreementMatches, $result->comparedRows)],
                ['Brand raw exact-match', $this->rate($result->brandRawExactRate, $result->brandRawExactMatches, $result->brandComparisons)],
                ['Brand normalized exact-match', $this->rate($result->brandNormalizedExactRate, $result->brandNormalizedExactMatches, $result->brandComparisons)],
                ['Feature score MAD', number_format($result->featureMad, 2) . " ({$result->featurePairsCompared} pairs compared, {$result->featurePairsSkipped} skipped)"],
                ['ai_summary condition-word hits', (string) $result->conditionWordHits],
            ]
        );

        if ($result->featureMaxDelta !== null) {
            $this->line(sprintf(
                'Max feature delta: %.2f on "%s" (product #%d)',
                $result->featureMaxDelta['delta'],
                $result->featureMaxDelta['feature'],
                $result->featureMaxDelta['product_id'],
            ));
        }

        $verdict = strtoupper($result->gate);
        $reasons = $this->reasonsLine($result->gateReasons);

        if ($result->gate === 'pass') {
            $this->info("Gate: {$verdict}");
        } elseif ($result->gate === 'insufficient') {
            $this->warn("Gate: {$verdict} — {$reasons}");
        } else {
            $this->error("Gate: {$verdict} — {$reasons}");
        }
    }

    private function rate(float $rate, int $matches, int $denominator): string
    {
        if ($denominator === 0) {
            return 'n/a (0 comparable)';
        }

        return sprintf('%.1f%% (%d/%d)', $rate * 100, $matches, $denominator);
    }

    /**
     * @param list<string> $reasons
     */
    private function reasonsLine(array $reasons): string
    {
        return $reasons === [] ? '' : implode('; ', $reasons);
    }
}
