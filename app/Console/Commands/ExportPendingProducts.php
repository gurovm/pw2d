<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ExportPendingProducts as ExportPendingProductsAction;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Spec 039 T3 — read-only JSON export of the Bouncer overflow backlog
 * (`pending_ai`/`failed` products, or `--status=processed` for a blind
 * calibration export) for the operator-session evaluation path
 * (docs/specs/039-bouncer-in-session.md). Thin shell: all assembly happens in
 * {@see \App\Actions\ExportPendingProducts}.
 */
class ExportPendingProducts extends Command
{
    protected $signature = 'pw2d:products:export-pending
                            {tenant : The tenant ID}
                            {category-slug? : Slug of a single leaf category; omit to export every leaf category with matching products}
                            {--status=pending_ai,failed : Comma list; also accepts "processed" for a blind calibration export}
                            {--limit= : Max products exported per category}
                            {--out= : Output file path (default storage/app/bouncer/TENANT-CATEGORY_OR_ALL-TIMESTAMP.json)}
                            {--anchors=5 : Number of processed products included as scoring anchors, per category}';

    protected $description = 'Spec 039 T3 — export pending/failed (or processed, for calibration) products for operator-session evaluation';

    public function handle(ExportPendingProductsAction $action): int
    {
        $tenantId = $this->argument('tenant');
        $tenant   = Tenant::find($tenantId);

        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return self::FAILURE;
        }

        tenancy()->initialize($tenant);

        try {
            return $this->process($action, $tenant);
        } finally {
            tenancy()->end();
        }
    }

    private function process(ExportPendingProductsAction $action, Tenant $tenant): int
    {
        $categorySlug = $this->argument('category-slug');
        $statuses     = $this->parseStatuses((string) $this->option('status'));
        $limitOption  = $this->option('limit');
        $limit        = $limitOption !== null && $limitOption !== '' ? (int) $limitOption : null;
        $anchors      = (int) $this->option('anchors');

        try {
            $export = $action->execute($tenant, $categorySlug, $statuses, $limit, $anchors);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $path = $this->resolveOutPath($tenant->id, $categorySlug);
        File::ensureDirectoryExists(dirname($path));

        file_put_contents(
            $path,
            json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $count = $export['meta']['count'] ?? 0;
        $this->info("Exported {$count} products to {$path}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function parseStatuses(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== ''));
    }

    private function resolveOutPath(string $tenantId, ?string $categorySlug): string
    {
        $out = $this->option('out');

        if ($out) {
            return $out;
        }

        $categoryPart = $categorySlug ?? 'all';
        $timestamp    = now()->format('Ymd_His');

        return storage_path("app/bouncer/{$tenantId}-{$categoryPart}-{$timestamp}.json");
    }
}
