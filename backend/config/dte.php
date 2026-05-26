<?php

return [
    'enabled' => env('DTE_ENABLED', true),
    'provider_url' => env('DTE_PROVIDER_URL'),
    'provider_token' => env('DTE_PROVIDER_TOKEN'),
    'emisor_rut' => env('DTE_EMISOR_RUT'),
    'emisor_razon_social' => env('DTE_EMISOR_RAZON_SOCIAL', 'HealthTiCloud RIS'),
    'acteco' => env('DTE_ACTECO', '869000'),
    'simulate_when_no_provider' => env('DTE_SIMULATE', true),
];
