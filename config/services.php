<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'api_key'     => env('GEMINI_API_KEY'),
        'site_model'  => env('AGENT_SITE_MODEL', 'gemini-2.5-flash'),
        'admin_model' => env('AGENT_ADMIN_MODEL', 'gemini-2.5-pro'),
        'image_model' => env('AGENT_IMAGE_MODEL', 'gemini-2.5-flash-image'),

        // $/million tokens. Drives AiUsageService cost calculations — never
        // hardcode a dollar figure elsewhere. An unlisted model computes a null
        // cost rather than throwing (see spec 037 T1).
        'pricing' => [
            'gemini-2.5-pro'          => ['input' => 1.25, 'output' => 10.00],
            'gemini-2.5-flash'        => ['input' => 0.30, 'output' => 2.50],
            'gemini-2.5-flash-lite'   => ['input' => 0.10, 'output' => 0.40],
            'gemini-3.7-flash'        => ['input' => 0.75, 'output' => 3.75],
            'gemini-3.1-flash-lite'   => ['input' => 0.25, 'output' => 1.50],
            // Spec 038 B2 — production .env runs these two (unchanged since
            // 2026-07-21); the pricing map didn't know either, so every row
            // was recording estimated_cost_usd = NULL (spec 037 §1.1 prices).
            'gemini-3.1-pro-preview'  => ['input' => 2.00, 'output' => 12.00],
            'gemini-3.5-flash'        => ['input' => 1.50, 'output' => 9.00],
            // Spec 039 T4 — the operator-session overflow path
            // (`pw2d:products:apply-evaluations`). Not a metered API call: the
            // Claude Code subscription is a sunk cost, so every row prices to
            // exactly $0 (see AiUsageService::estimateCost()'s zero-priced-model
            // short-circuit) rather than NULL.
            'claude-code-session'     => ['input' => 0.0, 'output' => 0.0],
        ],
    ],

    'amazon' => [
        'affiliate_tag' => env('AMAZON_AFFILIATE_TAG'),
    ],

    // SSRF allowlist for product image downloads (all vendor CDNs)
    'allowed_image_hosts' => [
        'm.media-amazon.com',
        'images-na.ssl-images-amazon.com',
        'images-eu.ssl-images-amazon.com',
        'images-fe.ssl-images-amazon.com',
        'cdn.shopify.com',
    ],

    'extension' => [
        'token' => env('CHROME_EXTENSION_KEY'),
    ],
'posthog' => [
        'key' => env('POSTHOG_API_KEY'),
        'host' => env('POSTHOG_HOST', 'https://eu.posthog.com'),
    ],
    'google' => [
        'analytics_id' => env('GA_MEASUREMENT_ID'),
    ],

    'adsense' => [
        'publisher_id' => env('ADSENSE_PUBLISHER_ID'),
    ],
];
