<?php

declare(strict_types=1);

use App\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => null, // We assign IDs manually (e.g. 'best-mics', 'pro-keyboards')

    'domain_model' => Domain::class,

    /**
     * Central domains — the admin panel and API live here, unscoped.
     */
    'central_domains' => array_filter([
        'pw2d.com',
        'www.pw2d.com',
        '127.0.0.1',
        'localhost',
        env('APP_CENTRAL_DOMAIN'), // e.g. 'pw2d.lcl' for local dev
    ]),

    /**
     * Single-DB tenancy: we only need CacheTenancyBootstrapper.
     * DatabaseTenancyBootstrapper is disabled (no separate tenant DBs).
     * FilesystemTenancyBootstrapper is disabled (shared storage).
     */
    'bootstrappers' => [
        // CacheTenancyBootstrapper disabled — requires a tag-capable driver (Redis/Memcached).
        // Cache keys are already tenant-scoped manually where needed.
    ],

    /**
     * Database tenancy config — kept for reference but not used (no DatabaseTenancyBootstrapper).
     */
    'database' => [
        'central_connection' => env('DB_CONNECTION', 'mysql'),
        'template_tenant_connection' => null,
        'prefix' => 'tenant',
        'suffix' => '',
        'managers' => [
            'mysql' => Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager::class,
        ],
    ],

    'cache' => [
        'tag_base' => 'tenant',
    ],

    /**
     * Filesystem — disabled (all tenants share one storage disk).
     */
    'filesystem' => [
        'suffix_base' => 'tenant',
        'disks' => [],
        'root_override' => [],
        'suffix_storage_path' => false,
        'asset_helper_tenancy' => false,
    ],

    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],

    'features' => [
        // Stancl\Tenancy\Features\ViteBundler::class,
    ],

    'routes' => true,

    /**
     * Tenant migrations — disabled for single-DB. All migrations run centrally.
     */
    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder',
    ],
];
