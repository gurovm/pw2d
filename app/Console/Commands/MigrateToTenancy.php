<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateToTenancy extends Command
{
    protected $signature = 'app:migrate-to-tenancy
                            {--tenant=pw2d : The tenant ID to assign to existing data}
                            {--domain=pw2d.com : The production domain to attach}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Assign all existing records (tenant_id IS NULL) to the default tenant. Safe for production.';

    private const TABLES = [
        'categories',
        'brands',
        'products',
        'features',
        'presets',
        'search_logs',
        'settings',
    ];

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $domain   = $this->option('domain');
        $dryRun   = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
            $this->newLine();
        }

        // Step 1: Create tenant if it doesn't exist
        $exists = DB::table('tenants')->where('id', $tenantId)->exists();

        if ($exists) {
            $this->info("Tenant '{$tenantId}' already exists.");
        } else {
            $this->info("Creating tenant '{$tenantId}'...");
            if (!$dryRun) {
                DB::table('tenants')->insert([
                    'id'         => $tenantId,
                    'name'       => 'Power to Decide',
                    'data'       => json_encode([
                        'brand_name'     => 'pw2d',
                        'primary_color'  => '#FF9900',
                        'secondary_background_color' => '#F3F4F6',
                        'text_color'     => '#111827',
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->info('  Created.');
        }

        // Step 2: Attach domain
        $domainExists = DB::table('domains')
            ->where('domain', $domain)
            ->where('tenant_id', $tenantId)
            ->exists();

        if ($domainExists) {
            $this->info("Domain '{$domain}' already attached to '{$tenantId}'.");
        } else {
            $this->info("Attaching domain '{$domain}'...");
            if (!$dryRun) {
                DB::table('domains')->insert([
                    'domain'     => $domain,
                    'tenant_id'  => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->info('  Attached.');
        }

        // Step 3: Update all orphaned records (tenant_id IS NULL)
        $this->newLine();
        $this->info('Updating orphaned records...');

        $totalUpdated = 0;

        foreach (self::TABLES as $table) {
            $count = DB::table($table)->whereNull('tenant_id')->count();

            if ($count === 0) {
                $this->line("  {$table}: 0 rows (already assigned)");
                continue;
            }

            if (!$dryRun) {
                DB::table($table)
                    ->whereNull('tenant_id')
                    ->update(['tenant_id' => $tenantId]);
            }

            $this->line("  {$table}: {$count} rows" . ($dryRun ? ' (would update)' : ' updated'));
            $totalUpdated += $count;
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. Total: %d rows %s across %d tables.',
            $totalUpdated,
            $dryRun ? 'would be updated' : 'updated',
            count(self::TABLES),
        ));

        return self::SUCCESS;
    }
}
