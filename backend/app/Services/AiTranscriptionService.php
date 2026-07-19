<?php

namespace App\Services;

use App\Support\CloudSyncMode;
use App\Support\CloudSyncTransport;
use App\Support\RisHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AiTranscriptionService
{
    public function status(): array
    {
        $enabled = (bool) config('ai_transcription.enabled', true);
        $reachable = $this->cloudPathReachable();
        $configured = $this->isConfigured();
        $available = $enabled && $reachable && $configured;

        $message = match (true) {
            !$enabled => 'La transcripción con IA está desactivada en este servidor.',
            !$reachable => CloudSyncMode::isLocal()
                ? 'Sin conexión a la nube desde el servidor local. La IA no está disponible.'
                : 'No hay conectividad para el proveedor de IA.',
            !$configured => CloudSyncMode::isLocal()
                ? 'La nube no tiene configurada la transcripción IA (API key).'
                : 'Falta AI_TRANSCRIPTION_API_KEY / OPENAI_API_KEY en el servidor nube.',
            default => 'Transcripción con IA disponible.',
        };

        return [
            'enabled' => $enabled,
            'available' => $available,
            'cloud_reachable' => $reachable,
            'configured' => $configured,
            'role' => CloudSyncMode::role(),
            'message' => $message,
        ];
    }

    public function transcribe(UploadedFile $audio, ?string $language = null): string
    {
        $status = $this->status();
        if (!$status['enabled']) {
            throw new RuntimeException($status['message']);
        }
        if (!$status['cloud_reachable']) {
            throw new RuntimeException($status['message']);
        }

        $language = $language !== null && $language !== ''
            ? $language
            : (string) config('ai_transcription.language', 'es');

        if (CloudSyncMode::isLocal() && (bool) config('ai_transcription.proxy_to_cloud', true)) {
            return $this->proxyToCloud($audio, $language);
        }

        if (!$this->hasApiKey()) {
            throw new RuntimeException(
                'Falta AI_TRANSCRIPTION_API_KEY / OPENAI_API_KEY para transcribir con IA.'
            );
        }

        return $this->transcribeWithOpenAi($audio, $language);
    }

    /**
     * Endpoint nube: solo Whisper local (sin reenviar).
     */
    public function transcribeOnCloud(UploadedFile $audio, ?string $language = null): string
    {
        if (!(bool) config('ai_transcription.enabled', true)) {
            throw new RuntimeException('La transcripción con IA está desactivada.');
        }
        if (!$this->hasApiKey()) {
            throw new RuntimeException('Falta AI_TRANSCRIPTION_API_KEY en el servidor nube.');
        }

        $language = $language !== null && $language !== ''
            ? $language
            : (string) config('ai_transcription.language', 'es');

        return $this->transcribeWithOpenAi($audio, $language);
    }

    public function isConfigured(): bool
    {
        if (CloudSyncMode::isLocal() && (bool) config('ai_transcription.proxy_to_cloud', true)) {
            return filled(config('ai_transcription.cloud_transcribe_url'))
                && filled(config('cloud_sync.secret'));
        }

        return $this->hasApiKey();
    }

    private function hasApiKey(): bool
    {
        return filled(config('ai_transcription.api_key'));
    }

    /**
     * Local: debe alcanzar la nube. Nube: OK si hay API key (Internet hacia OpenAI).
     * Desarrollo local con allow_local_provider: no exige nube.
     */
    private function cloudPathReachable(): bool
    {
        if (CloudSyncMode::isCloud()) {
            return $this->hasApiKey();
        }

        if ((bool) config('ai_transcription.allow_local_provider', false) && $this->hasApiKey()) {
            return true;
        }

        // Lab local: la IA vive en la nube; hace falta internet hacia CLOUD_API_BASE.
        return CloudSyncTransport::cloudReachable();
    }

    private function proxyToCloud(UploadedFile $audio, string $language): string
    {
        $url = (string) config('ai_transcription.cloud_transcribe_url', '');
        $secret = (string) config('cloud_sync.secret', '');

        if ($url === '' || $secret === '') {
            throw new RuntimeException(
                'Transcripción IA no configurada: falta CLOUD_API_BASE / CLOUD_SYNC_SECRET.'
            );
        }

        if (!CloudSyncTransport::cloudReachable()) {
            throw new RuntimeException(
                'Sin conexión a la nube desde el servidor local. La IA no está disponible.'
            );
        }

        $timeout = (int) config('ai_transcription.http_timeout', 120);
        $filename = $audio->getClientOriginalName() ?: ('dictado.' . ($audio->getClientOriginalExtension() ?: 'webm'));

        try {
            $response = RisHttp::client($timeout)
                ->withToken($secret)
                ->acceptJson()
                ->attach(
                    'audio',
                    fopen($audio->getRealPath(), 'r'),
                    $filename
                )
                ->post($url, [
                    'language' => $language,
                ]);
        } catch (\Throwable $e) {
            Log::warning('AI transcription cloud proxy failed', ['error' => $e->getMessage()]);
            throw new RuntimeException(
                'No se pudo contactar la IA en la nube: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if (!$response->successful()) {
            $body = $response->json();
            $msg = is_array($body)
                ? ($body['message'] ?? $response->body())
                : $response->body();
            throw new RuntimeException(
                'La nube rechazó la transcripción IA (HTTP ' . $response->status() . '): ' . $msg
            );
        }

        $text = trim((string) ($response->json('text') ?? $response->json('data.text') ?? ''));
        if ($text === '') {
            throw new RuntimeException('La nube no devolvió texto de transcripción.');
        }

        return $text;
    }

    private function transcribeWithOpenAi(UploadedFile $audio, string $language): string
    {
        $apiKey = (string) config('ai_transcription.api_key', '');
        $url = (string) config('ai_transcription.openai_url');
        $model = (string) config('ai_transcription.model', 'whisper-1');
        $timeout = (int) config('ai_transcription.http_timeout', 120);
        $filename = $audio->getClientOriginalName() ?: ('dictado.' . ($audio->getClientOriginalExtension() ?: 'webm'));

        $payload = [
            'model' => $model,
            'response_format' => 'json',
        ];
        if ($language !== '') {
            $payload['language'] = $language;
        }

        try {
            $response = RisHttp::client($timeout)
                ->withToken($apiKey)
                ->acceptJson()
                ->attach(
                    'file',
                    fopen($audio->getRealPath(), 'r'),
                    $filename
                )
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('OpenAI Whisper request failed', ['error' => $e->getMessage()]);
            throw new RuntimeException(
                'Error al contactar el proveedor de IA: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if (!$response->successful()) {
            throw new RuntimeException(
                'Proveedor de IA (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        $text = trim((string) ($response->json('text') ?? ''));
        if ($text === '') {
            throw new RuntimeException('El proveedor de IA no devolvió texto.');
        }

        return $text;
    }
}
