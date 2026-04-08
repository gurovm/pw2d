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

if (!function_exists('tenant_seo')) {
    /**
     * Read a tenant SEO key with a computed fallback.
     *
     * Fallback chain:
     *   1. tenants.data[seo_<key>]        (explicit tenant value)
     *   2. computed default using tenant('brand_name')
     *   3. global fallback (empty string or logo asset)
     *
     * Safe when tenant() is null (central domain) — returns brand-based
     * defaults using 'Pw2D' as the brand name.
     *
     * @param  string  $key  One of: title_suffix, default_title, default_description, default_image
     * @return string|null
     */
    function tenant_seo(string $key): ?string
    {
        $brand = tenant('brand_name') ?? 'Pw2D';

        return match ($key) {
            'title_suffix'        => tenant('seo_title_suffix') ?? $brand,
            'default_title'       => tenant('seo_default_title') ?? "{$brand} — AI Product Recommendations",
            'default_description' => tenant('seo_default_description')
                ?? "Discover the best products tailored to your exact needs using {$brand}'s AI-powered recommendation engine.",
            'default_image'       => tenant('seo_default_image') ?? tenant('logo') ?? asset('images/logo.webp'),
            default               => null,
        };
    }
}
