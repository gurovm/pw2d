<?php

if (!function_exists('tenant_cache_key')) {
    /**
     * Prefix a cache key with the current tenant ID to prevent cross-tenant cache pollution.
     * Returns "tcentral:{$key}" when no tenant is active (central context).
     */
    function tenant_cache_key(string $key): string
    {
        $tenantId = tenant('id') ?? 'central';
        return "t{$tenantId}:{$key}";
    }
}
