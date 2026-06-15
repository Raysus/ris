<?php

$cloudBase = rtrim((string) env('CLOUD_API_BASE', env('CLOUD_SERVER_URL', '')), '/');

$inboundUrl = (string) env('CLOUD_INBOUND_URL', '');
if ($inboundUrl === '' && $cloudBase !== '') {
    $inboundUrl = str_ends_with($cloudBase, '/integrations/cloud-sync/inbound')
        ? $cloudBase
        : $cloudBase . '/integrations/cloud-sync/inbound';
}

$exportUrl = (string) env('CLOUD_EXPORT_URL', '');
if ($exportUrl === '' && $cloudBase !== '') {
    $exportUrl = preg_replace('#/inbound$#', '/export', $inboundUrl)
        ?: $cloudBase . '/integrations/cloud-sync/export';
}

return [
    /**
     * cloud = solo recibe datos (nube). local = envía y puede importar catálogo.
     * auto = inbound si APP_URL contiene healthticloud o CLOUD_INBOUND_ENABLED=true
     */
    'role' => env('RIS_CLOUD_ROLE', 'auto'),

    'secret' => env('CLOUD_SYNC_SECRET'),

    'inbound_url' => $inboundUrl ?: null,
    'export_url' => $exportUrl ?: null,

    'inbound_enabled' => filter_var(env('CLOUD_INBOUND_ENABLED', true), FILTER_VALIDATE_BOOL),

    /**
     * Nube → MWL local por laboratorio (JSON). Clave = laboratory_id UUID.
     * Ej.: {"ef633609-...":"http://100.103.135.42/api/integrations/local-mwl/relay"}
     * Alternativa: laboratories.settings.local_mwl_relay_url por sede.
     */
    'lab_mwl_relay_urls' => array_filter(
        is_array($decoded = json_decode((string) env('LAB_MWL_RELAY_URLS', '{}'), true))
            ? $decoded
            : []
    ),

    'catalog_entities' => [
        'laboratories',
        'referring_doctors',
        'exams',
        'machines',
        'supplies',
        'report_templates',
        'insurances',
        'insurance_plans',
        'services',
    ],

    'models' => [
        'Persona' => \App\Models\Persona::class,
        'App\Models\Persona' => \App\Models\Persona::class,
        'Paciente' => \App\Models\Paciente::class,
        'App\Models\Paciente' => \App\Models\Paciente::class,
        'User' => \App\Models\User::class,
        'App\Models\User' => \App\Models\User::class,
        'Appointment' => \App\Models\Appointment::class,
        'App\Models\Appointment' => \App\Models\Appointment::class,
        'AppointmentStudy' => \App\Models\AppointmentStudy::class,
        'Machine' => \App\Models\Machine::class,
        'App\Models\Machine' => \App\Models\Machine::class,
        'Exam' => \App\Models\Exam::class,
        'App\Models\Exam' => \App\Models\Exam::class,
        'Laboratory' => \App\Models\Laboratory::class,
        'App\Models\Laboratory' => \App\Models\Laboratory::class,
        'Insurance' => \App\Models\Insurance::class,
        'App\Models\Insurance' => \App\Models\Insurance::class,
        'InsurancePlan' => \App\Models\InsurancePlan::class,
        'App\Models\InsurancePlan' => \App\Models\InsurancePlan::class,
        'ReportTemplate' => \App\Models\ReportTemplate::class,
        'App\Models\ReportTemplate' => \App\Models\ReportTemplate::class,
        'Supply' => \App\Models\Supply::class,
        'App\Models\Supply' => \App\Models\Supply::class,
        'Service' => \App\Models\Service::class,
        'App\Models\Service' => \App\Models\Service::class,
        'ReferringDoctor' => \App\Models\ReferringDoctor::class,
        'App\Models\ReferringDoctor' => \App\Models\ReferringDoctor::class,
        'AppointmentLog' => \App\Models\AppointmentLog::class,
    ],
];
