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

    'andy_core' => [
        'url' => env('ANDY_CORE_URL', ''),
        'connection_key' => env('ANDY_CORE_CONNECTION_KEY', 'beyourlover.com'),
        'key_id' => env('ANDY_CORE_KEY_ID', ''),
        'shared_secret' => env('ANDY_CORE_SHARED_SECRET', ''),
        'verify_tls' => (bool) env('ANDY_CORE_VERIFY_TLS', true),
        'timeout' => (int) env('ANDY_CORE_TIMEOUT_SECONDS', 15),
        'connect_timeout' => (int) env('ANDY_CORE_CONNECT_TIMEOUT_SECONDS', 5),
        'retry_times' => (int) env('ANDY_CORE_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('ANDY_CORE_RETRY_SLEEP_MS', 500),
        'uat_fake' => filter_var(env('ANDY_CORE_UAT_FAKE', false), FILTER_VALIDATE_BOOL),
    ],

    'woocommerce' => [
        'url' => env('WOO_BASE_URL', ''),
        'consumer_key' => env('WOO_CONSUMER_KEY', ''),
        'consumer_secret' => env('WOO_CONSUMER_SECRET', ''),
        'connection_key' => env('WOO_CONNECTION_KEY', 'beyourlover.com'),
        'timeout' => (int) env('WOO_TIMEOUT_SECONDS', 15),
        'product_sync_enabled' => (bool) env('WOO_PRODUCT_SYNC_ENABLED', false),
        'order_sync_enabled' => (bool) env('WOO_ORDER_SYNC_ENABLED', false),
        'media_allowed_hosts' => env('WOO_MEDIA_ALLOWED_HOSTS', ''),
        'media_max_bytes' => max(1, (int) env('WOO_MEDIA_MAX_BYTES', 10485760)),
    ],


    'google_ads' => [
        'client_id' => env('GOOGLE_ADS_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET', ''),
        'refresh_token' => env('GOOGLE_ADS_REFRESH_TOKEN', ''),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN', ''),
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID', ''),
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v25'),
        'base_url' => env('GOOGLE_ADS_BASE_URL', 'https://googleads.googleapis.com'),
        'oauth_url' => env('GOOGLE_ADS_OAUTH_URL', 'https://oauth2.googleapis.com/token'),
        'timeout' => (int) env('GOOGLE_ADS_TIMEOUT_SECONDS', 20),
    ],

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

];
