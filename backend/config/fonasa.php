<?php

return [
    'enabled' => env('FONASA_ENABLED', true),
    'api_url' => env('FONASA_API_URL'),
    'api_token' => env('FONASA_API_TOKEN'),
    'simulate_when_no_api' => env('FONASA_SIMULATE', true),
    'default_bonificacion_pct' => (int) env('FONASA_DEFAULT_BONIFICACION_PCT', 80),
];
