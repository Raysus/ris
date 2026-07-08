<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cloud_sync' => [
        'secret' => env('CLOUD_SYNC_SECRET'),
    ],

    /*
     * Epson térmica ESC/POS en la LAN del laboratorio (sin driver: RAW TCP :9100).
     * Ejemplo: THERMAL_PRINTER_INTERFACE=tcp://192.168.0.240:9100
     */
    'thermal_printer' => [
        'enabled' => filter_var(env('THERMAL_PRINTER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'interface' => env('THERMAL_PRINTER_INTERFACE', ''),
        'width_chars' => (int) env('THERMAL_PRINTER_WIDTH', 42),
        'copies' => max(1, (int) env('THERMAL_PRINTER_COPIES', 1)),
    ],

    'portal_integration' => [
        'secret' => env('PORTAL_INTEGRATION_SECRET'),
    ],

    'orthanc' => [
        'url' => env('ORTHANC_URL'),
        'host' => env('ORTHANC_HOST'),
        /** Host/IP DICOM para equipos en LAN (MWL C-FIND). Suele ser IP pública, no el hostname HTTPS. */
        'dicom_host' => env('PACS_DICOM_HOST'),
        'port' => env('ORTHANC_PORT', 4242),
        'aet' => env('ORTHANC_AET', 'HEALTHTICLOUD'),
        /** Token Bearer para plugin Authorization de Orthanc (mismo JWT que OHIF si aplica). */
        'http_bearer' => env('ORTHANC_HTTP_BEARER'),
    ],

    /** MWL local en LAN (Orthanc solo worklist). Si MWL_ORTHANC_URL está definido, las órdenes van aquí. */
    'mwl' => [
        /** cloud | orthanc | wlmscpfs */
        'provider' => env('MWL_PROVIDER', 'cloud'),
        'url' => env('MWL_ORTHANC_URL'),
        'dicom_host' => env('MWL_DICOM_HOST'),
        /** Host DICOM para probe C-FIND desde contenedores (ej. servicio compose «mwl»). */
        'internal_host' => env('MWL_INTERNAL_HOST', 'mwl'),
        'port' => (int) env('MWL_PORT', 4242),
        'aet' => env('MWL_AET', 'SIRESA_MWL'),
        'files_path' => env('MWL_FILES_PATH', storage_path('app/mwl-worklists')),
        /** orthanc local: files (.wl + ModalityWorklists) | rest (/worklists/create, plugin nuevo). */
        'orthanc_mode' => env('MWL_ORTHANC_MODE', 'files'),
        /** Alias ORTHANC en .wl local: solo si el FCR consulta con estación legacy ORTHANC (desactivado por defecto). */
        'fuji_orthanc_alias' => filter_var(env('MWL_FUJI_ORTHANC_ALIAS', false), FILTER_VALIDATE_BOOL),
    ],

];
