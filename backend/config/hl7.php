<?php

return [
    'enabled' => env('HL7_ENABLED', false),
    'inbound_secret' => env('HL7_INBOUND_SECRET'),
    'outbound_url' => env('HL7_OUTBOUND_URL'),
    'outbound_secret' => env('HL7_OUTBOUND_SECRET'),
    'send_oru_on_sign' => env('HL7_SEND_ORU_ON_SIGN', false),
    'sending_application' => env('HL7_SENDING_APP', 'HealthTiCloud_RIS'),
    'sending_facility' => env('HL7_SENDING_FACILITY', 'RIS'),
];
