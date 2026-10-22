<?php

return [
    'zernio' => [
        'enabled' => (bool) env('AGENT_ZERNIO_ENABLED', false),
        'publishing_enabled' => (bool) env('AGENT_ZERNIO_PUBLISHING_ENABLED', false),
        'instagram_scheduling_enabled' => (bool) env('AGENT_ZERNIO_INSTAGRAM_SCHEDULING_ENABLED', false),
        'api_key' => env('ZERNIO_API_KEY'),
        'webhook_secret' => env('ZERNIO_WEBHOOK_SECRET'),
    ],
    'reads_per_minute' => 60,
    'writes_per_minute' => 6,
    'webhook_payload_retention_days' => 7,
    'audit_retention_days' => 90,
];
