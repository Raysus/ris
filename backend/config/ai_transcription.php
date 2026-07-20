<?php

/**
 * Transcripción de audio con IA en la nube.
 * Proveedor por defecto: faster-whisper local (AI_TRANSCRIPTION_WHISPER_URL).
 * Labs locales reenvían audio a CLOUD_API_BASE si hay internet.
 */
$cloudBase = rtrim((string) env('CLOUD_API_BASE', env('CLOUD_SERVER_URL', '')), '/');

$cloudTranscribeUrl = (string) env('AI_TRANSCRIPTION_CLOUD_URL', '');
if ($cloudTranscribeUrl === '' && $cloudBase !== '') {
    $cloudTranscribeUrl = $cloudBase . '/integrations/ai/transcribe';
}

return [
    'enabled' => filter_var(env('AI_TRANSCRIPTION_ENABLED', true), FILTER_VALIDATE_BOOL),

    /**
     * faster_whisper = servicio local (deploy/whisper).
     * openai = API OpenAI Whisper (requiere API key).
     */
    'provider' => env('AI_TRANSCRIPTION_PROVIDER', 'faster_whisper'),

    'api_key' => trim((string) env('AI_TRANSCRIPTION_API_KEY', env('OPENAI_API_KEY', ''))),

    'model' => env('AI_TRANSCRIPTION_MODEL', 'small'),

    'openai_url' => env('AI_TRANSCRIPTION_OPENAI_URL', 'https://api.openai.com/v1/audio/transcriptions'),

    /** URL del servicio faster-whisper en la nube (loopback). */
    'whisper_url' => rtrim((string) env('AI_TRANSCRIPTION_WHISPER_URL', 'http://127.0.0.1:8765'), '/'),

    /** Idioma por defecto (ISO-639-1). Vacío = detección automática. */
    'language' => env('AI_TRANSCRIPTION_LANGUAGE', 'es'),

    'http_timeout' => (int) env('AI_TRANSCRIPTION_HTTP_TIMEOUT', 180),

    /**
     * En laboratorio local: reenviar el audio a la nube.
     */
    'proxy_to_cloud' => filter_var(env('AI_TRANSCRIPTION_PROXY_TO_CLOUD', true), FILTER_VALIDATE_BOOL),

    'cloud_transcribe_url' => $cloudTranscribeUrl ?: null,

    /**
     * Solo desarrollo: permitir proveedor directo desde el lab sin pasar por la nube.
     */
    'allow_local_provider' => filter_var(env('AI_TRANSCRIPTION_ALLOW_LOCAL', false), FILTER_VALIDATE_BOOL),
];
