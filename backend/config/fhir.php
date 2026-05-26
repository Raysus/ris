<?php

return [
    'enabled' => env('FHIR_ENABLED', true),
    'base_url' => env('FHIR_BASE_URL', env('APP_URL') . '/api/fhir'),
    'inbound_secret' => env('FHIR_INBOUND_SECRET'),
    'version' => '4.0.1',
];
