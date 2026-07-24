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

    // Sin comillas en .env; trim evita espacios al copiar/pegar desde Docker o editores.
    'secret' => ($s = trim((string) env('CLOUD_SYNC_SECRET', ''))) !== ''
        ? trim($s, " \t\n\r\0\x0B\"'")
        : null,

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

    /** Nube → lab: estado clínico de citas (opcional; si falta, se deriva de LAB_MWL_RELAY_URLS). */
    'lab_sync_relay_urls' => array_filter(
        is_array($decoded = json_decode((string) env('LAB_SYNC_RELAY_URLS', '{}'), true))
            ? $decoded
            : []
    ),

  /** Segundos entre reintentos cuando no hay internet (laboratorios). */
    'pending_release_seconds' => (int) env('CLOUD_SYNC_PENDING_RELEASE', 60),

    /** Tope de espera entre reintentos automáticos. */
    'max_release_backoff' => (int) env('CLOUD_SYNC_MAX_BACKOFF', 900),

    /** Timeout HTTP hacia la nube. */
    'http_timeout' => (int) env('CLOUD_SYNC_HTTP_TIMEOUT', 15),

    /** Timeout cuando el payload lleva audio/PDF (base64). */
    'http_timeout_audio' => (int) env('CLOUD_SYNC_HTTP_TIMEOUT_AUDIO', 300),

    /** URL opcional para comprobar conectividad (por defecto /health del CLOUD_API_BASE). */
    'health_url' => env('CLOUD_SYNC_HEALTH_URL'),

    /** Minutos sin actualizar antes de reencolar un pending (comando programado). */
    'flush_stale_minutes' => (int) env('CLOUD_SYNC_FLUSH_STALE_MINUTES', 2),

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
        'TipoUsuario' => \App\Models\TipoUsuario::class,
        'App\Models\TipoUsuario' => \App\Models\TipoUsuario::class,
        'LaboratoryUser' => \App\Models\LaboratoryUser::class,
        'App\Models\LaboratoryUser' => \App\Models\LaboratoryUser::class,
        'Appointment' => \App\Models\Appointment::class,
        'App\Models\Appointment' => \App\Models\Appointment::class,
        'AppointmentStudy' => \App\Models\AppointmentStudy::class,
        'Machine' => \App\Models\Machine::class,
        'App\Models\Machine' => \App\Models\Machine::class,
        'Exam' => \App\Models\Exam::class,
        'App\Models\Exam' => \App\Models\Exam::class,
        'SubExam' => \App\Models\SubExam::class,
        'App\Models\SubExam' => \App\Models\SubExam::class,
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
        'SupportTicket' => \App\Models\SupportTicket::class,
        'App\Models\SupportTicket' => \App\Models\SupportTicket::class,
        'Payment' => \App\Models\Payment::class,
        'App\Models\Payment' => \App\Models\Payment::class,
    ],
];
