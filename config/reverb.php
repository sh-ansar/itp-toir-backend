<?php

return [
    'default' => env('REVERB_SERVER', 'reverb'),
    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
            'port' => env('REVERB_SERVER_PORT', 18123),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST', '127.0.0.1'),
            'options' => ['tls' => []],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10000),
            'scaling' => [
                'enabled' => false,
                'channel' => 'toir-chat',
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', 6379),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],
    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 18123),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'allowed_origins' => array_values(array_filter(array_map('trim',
                explode(',', (string) env('REVERB_ALLOWED_ORIGINS', 'platform.itpmining.kz'))))),
            'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
            'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
            'max_connections' => env('REVERB_APP_MAX_CONNECTIONS', 300),
            'max_message_size' => 10000,
            'accept_client_events_from' => 'members',
            'rate_limiting' => [
                'enabled' => true, 'max_attempts' => 120, 'decay_seconds' => 60,
                'terminate_on_limit' => false,
            ],
        ]],
    ],
];
