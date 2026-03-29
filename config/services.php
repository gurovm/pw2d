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
];
