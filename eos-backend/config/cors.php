<?php

/*
 * The Angular frontend calls this API from another origin with a bearer token (no cookies), so credentials stay
 * off. Set CORS_ALLOWED_ORIGINS to a comma-separated list in production (e.g. https://eoms.example.com).
 * Download endpoints carry the file name in Content-Disposition and the Sham Cash document id in X-Doc-Id;
 * browsers hide response headers from scripts unless they are exposed here.
 */
return [

    'paths' => ['api/*', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition', 'X-Doc-Id'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
