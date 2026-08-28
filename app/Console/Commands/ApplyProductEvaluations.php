<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ApplyProductEvaluations as ApplyProductEvaluationsAction;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Spec 039 T4 — applies an operator-session evaluations file
 * (`pw2d:products:export-pending`'s counterpart) via the shared
 * {@see \App\Actions\FinalizeProductEvaluation} path. Thin shell: all
 * validation/apply logic lives in {@see \App\Actions\ApplyProductEvaluations}.
 */
class ApplyProductEvaluations extends Command
{
    protected $signature = 'pw2d:products:apply-evaluations
                            {tenant : The tenant ID}
                            {file : Path to the evaluations JSON file}
                            {--source=claude-code-session : Recorded as ai_usage.model}
                            {--dry-run : Validate everything, write nothing, print the full outcome table}
                            {--force : Apply even while queue jobs are pending (risk: a queued Gemini job can overwrite session results)}';

    protected $description = 'Spec 039 T4 — apply an operator-session evaluations file to pending/failed products';

    public function handle(ApplyProductEvaluationsAction $action): int
    {
        // Review M4: a still-queued ProcessPendingProduct job processes a
        // product regardless of its status and can overwrite (or re-fail)
        // whatever this apply just finalized. Refuse before doing anything —
        // including --dry-run, so the operator sees the same refusal every
        // time — unless the operator explicitly accepts the risk.
        $queuedJobs = DB::table('jobs')->count();

        if ($queuedJobs > 0 && !$this->option('force')) {
            $this->error("Refusing to apply: {$queuedJobs} job(s) still queued. Wait for the queue to drain (SELECT COUNT(*) FROM jobs) or pass --force.");
            return 2;
        }

        $tenantId = $this->argument('tenant');
        $tenant   = Tenant::find($tenantId);

        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return self::FAILURE;
        }

        $filePath = $this->argument('file');

        if (!is_file($filePath)) {
            $this->error("File not found: {$filePath}");
            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($filePath), true);

        if (!is_array($decoded) || !isset($decoded['evaluations']) || !is_array($decoded['evaluations'])) {
            $this->error('Invalid input file: expected a JSON object with an "evaluations" array.');
            return self::FAILURE;
        }

        $source = (string) $this->option('source');
        $dryRun = (bool) $this->option('dry-run');

        tenancy()->initialize($tenant);

        try {
            $result = $action->execute($tenant, $decoded['evaluations'], $source, $dryRun);
        } finally {
            tenancy()->end();
        }

        $this->render($result, $dryRun);

        return $result['summary']['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array{rows: array<int, array{product_id: mixed, outcome: string, detail: string}>, summary: array<string, mixed>} $result
     */
    private function render(array $result, bool $dryRun): void
    {
        if ($dryRun) {
            $this->warn('DRY RUN — nothing was written.');
        }

        $this->table(
            ['Product ID', 'Outcome', 'Detail'],
            array_map(
                fn (array $row) => [$row['product_id'] ?? '?', $row['outcome'], $row['detail']],
                $result['rows']
            )
        );

        $summary = $result['summary'];
        $ignoredBreakdown = $this->formatIgnoredBreakdown($summary['ignored_reasons']);
        $ignoredPart = $summary['ignored'] > 0 && $ignoredBreakdown !== ''
            ? "ignored {$summary['ignored']} ({$ignoredBreakdown})"
            : "ignored {$summary['ignored']}";

        $this->line(sprintf(
            'scored %d · %s · merged %d · rejected %d · skipped %d · errors %d',
            $summary['scored'],
            $ignoredPart,
            $summary['merged'],
            $summary['rejected'],
            $summary['skipped'],
            $summary['errors'],
        ));
    }

    /**
     * @param array<string, int> $reasons
     */
    private function formatIgnoredBreakdown(array $reasons): string
    {
        if (empty($reasons)) {
            return '';
        }

        $parts = [];
        foreach ($reasons as $reason => $count) {
            $parts[] = "{$reason}: {$count}";
        }

        return implode(', ', $parts);
    }
}
