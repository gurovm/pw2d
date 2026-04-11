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

if (!function_exists('tenant_seo_enabled')) {
    /**
     * Return the effective bool value of the tenant's seo_enabled data key.
     *
     * Filament's Toggle component may write the value as bool true/false, string
     * "true"/"false", or int 1/0 depending on the underlying tenant data storage.
     * This helper normalises all representations to a real PHP bool.
     *
     * Returns false when no tenant is active (central context) — the scheduler
     * should never pull in that state.
     */
    function tenant_seo_enabled(): bool
    {
        $raw = tenant('seo_enabled');

        if ($raw === null) {
            return false;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw)) {
            return $raw !== 0;
        }

        // String normalisation — covers "true", "1", "false", "0"
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}

if (!function_exists('tenant_seo')) {
    /**
     * Read a tenant SEO key with a computed fallback.
     *
     * Fallback chain:
     *   1. tenants.data[seo_<key>]   (explicit tenant value, if non-empty)
     *   2. computed default using tenant('brand_name')
     *   3. global fallback (logo asset for default_image)
     *
     * Safe when tenant() is null (central domain) — returns brand-based
     * defaults using 'Pw2D'. Empty strings are treated like null so a
     * misconfigured Filament field doesn't render a bare "|" or empty title.
     *
     * @param  string  $key  One of: title_suffix, default_title, default_description, default_image
     * @return string  Always non-empty for the four supported keys; empty string for unknown keys.
     */
    function tenant_seo(string $key): string
    {
        $brand = filled(tenant('brand_name')) ? tenant('brand_name') : 'Pw2D';

        $explicit = match ($key) {
            'title_suffix'        => tenant('seo_title_suffix'),
            'default_title'       => tenant('seo_default_title'),
            'default_description' => tenant('seo_default_description'),
            'default_image'       => tenant('seo_default_image') ?: tenant('logo'),
            default               => null,
        };

        if (filled($explicit)) {
            return (string) $explicit;
        }

        return match ($key) {
            'title_suffix'        => $brand,
            'default_title'       => "{$brand} — AI Product Recommendations",
            'default_description' => "Discover the best products tailored to your exact needs using {$brand}'s AI-powered recommendation engine.",
            'default_image'       => asset('images/logo.webp'),
            default               => '',
        };
    }
}
