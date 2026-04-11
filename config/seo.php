<?php

declare(strict_types=1);

return [
    'google' => [
        'service_account_path' => env('SEO_GOOGLE_SA_PATH', storage_path('app/seo/google-service-account.json')),
    ],
    'gsc' => [
        'rate_limit_per_minute' => 1200, // Google Search Console API default
    ],
    'ga4' => [
        'rate_limit_per_minute' => 1200, // Google Analytics Data API default
    ],
    'pull' => [
        'chunk_size' => 500, // URLs per API page request
    ],
];
