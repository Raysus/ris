<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://127.0.0.1:5500'),
        'http://127.0.0.1:5500',
        'http://localhost:5500',
        'http://127.0.0.1',
        'http://localhost',
        'http://127.0.0.1:8080',
        'http://localhost:8080',
        'http://127.0.0.1:8765',
        'http://localhost:8765',
        'https://ris.healthticloud.cl',
    ])),
    
    'allowed_origins_patterns' => [
        '#^https://([a-z0-9.-]+\.)*healthticloud\.cl$#',
        '#^http://([a-z0-9.-]+\.)*healthticloud\.cl$#',
        '#^http://(localhost|127\.0\.0\.1)(:\d+)?$#',
        '#^https://(localhost|127\.0\.0\.1)(:\d+)?$#',
        '#^http://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^http://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^http://172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^https://172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        // Tailscale (CGNAT 100.64.0.0/10)
        '#^https?://100\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
    ],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Lab-Id', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];

