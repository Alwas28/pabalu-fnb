<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
     * Pengiriman event ke Analytics Platform / Central Dashboard (outbox).
     * Kosongkan endpoint untuk mematikan: event tidak dicatat & tidak dikirim.
     */
    'analytics' => [
        'endpoint' => env('ANALYTICS_ENDPOINT'),
        'token' => env('ANALYTICS_TOKEN'),
        'signing_secret' => env('ANALYTICS_SIGNING_SECRET'),
        'batch_size' => (int) env('ANALYTICS_BATCH_SIZE', 100),
        'timeout' => (int) env('ANALYTICS_TIMEOUT', 15),
    ],

];
