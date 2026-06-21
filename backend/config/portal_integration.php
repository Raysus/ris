<?php

return [
    'secret' => env('PORTAL_INTEGRATION_SECRET'),
    'enabled' => filter_var(env('PORTAL_INTEGRATION_ENABLED', true), FILTER_VALIDATE_BOOL),
];
