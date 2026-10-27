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

    'workspace' => [
        'ai_driver' => env('WORKSPACE_AI_DRIVER', 'fake'),
    ],

    'zernio' => [
        'enabled' => env('ZERNIO_AGENT_API_ENABLED', false),
        'publish_enabled' => env('ZERNIO_AGENT_PUBLISH_ENABLED', false),
        'schedule_enabled' => env('ZERNIO_AGENT_SCHEDULE_ENABLED', false),
        'base_url' => env('ZERNIO_API_BASE_URL', 'https://zernio.com/api'),
        'webhook_secret' => env('ZERNIO_WEBHOOK_SECRET'),
        'connect_timeout' => 5,
        'timeout' => 15,
        'max_response_bytes' => 1_048_576,
    ],

    'adkit' => [
        'api_key' => env('ADKIT_API_KEY'),
        'project_id' => env('ADKIT_PROJECT_ID'),
        'meta_account_id' => env('ADKIT_META_ACCOUNT_ID'),
        'base_url' => 'https://app.adkit.so/api/v1',
        'connect_timeout' => 5,
        'timeout' => 10,
        'max_response_bytes' => 1_048_576,
    ],

];
