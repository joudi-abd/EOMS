<?php

/*
 * Real-time updates for the tasks boards. The server is Laravel Reverb:
 *   composer require laravel/reverb && php artisan reverb:install
 * then set BROADCAST_CONNECTION=reverb and the REVERB_* values. Until then BROADCAST_CONNECTION=log (or null)
 * keeps every event harmless and clients fall back to polling GET /api/v1/tasks/boards/{id}/changes.
 */
return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
