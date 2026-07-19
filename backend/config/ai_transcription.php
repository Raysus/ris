<?php

/**
 * Transcripción de audio con IA (Whisper) en la nube.
 * Los laboratorios locales solo pueden usarla si alcanzan CLOUD_API_BASE.
 */
$cloudBase = rtrim((string) env('CLOUD_API_BASE', env('CLOUD_SERVER_URL', '')), '/');

$cloudTranscribeUrl = (string) env('AI_TRANSCRIPTION_CLOUD_URL', '');
if ($cloudTranscribeUrl === '' && $cloudBase !== '') {
    $cloudTranscribeUrl = $cloudBase . '/integrations/ai/transcribe';
}

return [
    'enabled' => filter_var(env('AI_TRANSCRIPTION_ENABLED', true), FILTER_VALIDATE_BOOL),

    /**
     * openai = API Whisper en el servidor nube (o local si allow_local_provider).
     */
    'provider' => env('AI_TRANSCRIPTION_PROVIDER', 'openai'),

    'api_key' => trim((string) env('AI_TRANSCRIPTION_API_KEY', env('OPENAI_API_KEY', ''))),

    'model' => env('AI_TRANSCRIPTION_MODEL', 'whisper-1'),

    'openai_url' => env('AI_TRANSCRIPTION_OPENAI_URL', 'https://api.openai.com/v1/audio/transcriptions'),

    /** Idioma por defecto (ISO-639-1). Vacío = detección automática. */
    'language' => env('AI_TRANSCRIPTION_LANGUAGE', 'es'),

    'http_timeout' => (int) env('AI_TRANSCRIPTION_HTTP_TIMEOUT', 120),

    /**
     * En laboratorio local: reenviar el audio a la nube (recomendado; la API key vive en la nube).
     */
    'proxy_to_cloud' => filter_var(env('AI_TRANSCRIPTION_PROXY_TO_CLOUD', true), FILTER_VALIDATE_BOOL),

    'cloud_transcribe_url' => $cloudTranscribeUrl ?: null,

    /**
     * Solo para desarrollo: permitir Whisper directo desde el lab sin pasar por la nube.
     * En producción debe ser false: la IA corre en la nube y el lab necesita internet hacia ella.
     */
    'allow_local_provider' => filter_var(env('AI_TRANSCRIPTION_ALLOW_LOCAL', false), FILTER_VALIDATE_BOOL),
];
