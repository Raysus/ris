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
            !$configured => $this->notConfiguredMessage(),
            !$reachable => CloudSyncMode::isLocal()
                ? 'Sin conexión a la nube desde el servidor local. La IA no está disponible.'
                : 'El servicio faster-whisper no responde en este servidor.',
            default => 'Transcripción con IA disponible.',
        };

        return [
            'enabled' => $enabled,
            'available' => $available,
            'cloud_reachable' => $reachable,
            'configured' => $configured,
            'provider' => $this->provider(),
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
        if (!$status['configured']) {
            throw new RuntimeException($status['message']);
        }

        $language = $language !== null && $language !== ''
            ? $language
            : (string) config('ai_transcription.language', 'es');

        if (CloudSyncMode::isLocal() && (bool) config('ai_transcription.proxy_to_cloud', true)) {
            return $this->proxyToCloud($audio, $language);
        }

        return $this->transcribeWithProvider($audio, $language);
    }

    /**
     * Endpoint nube: ejecuta el proveedor local (faster-whisper u OpenAI).
     */
    public function transcribeOnCloud(UploadedFile $audio, ?string $language = null): string
    {
        if (!(bool) config('ai_transcription.enabled', true)) {
            throw new RuntimeException('La transcripción con IA está desactivada.');
        }
        if (!$this->isConfigured()) {
            throw new RuntimeException($this->notConfiguredMessage());
        }

        $language = $language !== null && $language !== ''
            ? $language
            : (string) config('ai_transcription.language', 'es');

        return $this->transcribeWithProvider($audio, $language);
    }

    public function isConfigured(): bool
    {
        if (CloudSyncMode::isLocal() && (bool) config('ai_transcription.proxy_to_cloud', true)) {
            return filled(config('ai_transcription.cloud_transcribe_url'))
                && filled(config('cloud_sync.secret'));
        }

        return match ($this->provider()) {
            'openai' => $this->hasApiKey(),
            default => filled(config('ai_transcription.whisper_url')),
        };
    }

    private function provider(): string
    {
        $provider = strtolower((string) config('ai_transcription.provider', 'faster_whisper'));

        return in_array($provider, ['openai', 'faster_whisper', 'whisper'], true)
            ? ($provider === 'whisper' ? 'faster_whisper' : $provider)
            : 'faster_whisper';
    }

    private function hasApiKey(): bool
    {
        return filled(config('ai_transcription.api_key'));
    }

    private function notConfiguredMessage(): string
    {
        if (CloudSyncMode::isLocal()) {
            return 'La nube no tiene configurada la transcripción IA (CLOUD_API_BASE / CLOUD_SYNC_SECRET).';
        }

        return match ($this->provider()) {
            'openai' => 'Falta configurar AI_TRANSCRIPTION_API_KEY u OPENAI_API_KEY en el servidor nube.',
            default => 'Falta el servicio faster-whisper (AI_TRANSCRIPTION_WHISPER_URL) en el servidor nube.',
        };
    }

    /**
     * Local: debe alcanzar la nube.
     * Nube + faster-whisper: comprobar health del servicio local.
     * Nube + openai: reachable si hay API key (se valida al llamar).
     */
    private function cloudPathReachable(): bool
    {
        if (CloudSyncMode::isCloud()) {
            if ($this->provider() === 'openai') {
                return $this->hasApiKey();
            }

            return $this->whisperServiceReachable();
        }

        if ((bool) config('ai_transcription.allow_local_provider', false)) {
            if ($this->provider() === 'openai') {
                return $this->hasApiKey();
            }

            return $this->whisperServiceReachable();
        }

        return CloudSyncTransport::cloudReachable();
    }

    private function whisperServiceReachable(): bool
    {
        $base = rtrim((string) config('ai_transcription.whisper_url', ''), '/');
        if ($base === '') {
            return false;
        }

        try {
            $response = RisHttp::client(3)->acceptJson()->get($base . '/health');

            return $response->successful() && ($response->json('ok') === true || $response->json('ok') === 1);
        } catch (\Throwable) {
            return false;
        }
    }

    private function transcribeWithProvider(UploadedFile $audio, string $language): string
    {
        return match ($this->provider()) {
            'openai' => $this->transcribeWithOpenAi($audio, $language),
            default => $this->transcribeWithFasterWhisper($audio, $language),
        };
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

        $timeout = (int) config('ai_transcription.http_timeout', 180);
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

    private function transcribeWithFasterWhisper(UploadedFile $audio, string $language): string
    {
        $base = rtrim((string) config('ai_transcription.whisper_url', ''), '/');
        if ($base === '') {
            throw new RuntimeException('Falta AI_TRANSCRIPTION_WHISPER_URL.');
        }

        $timeout = (int) config('ai_transcription.http_timeout', 180);
        $filename = $audio->getClientOriginalName() ?: ('dictado.' . ($audio->getClientOriginalExtension() ?: 'webm'));
        $url = $base . '/v1/audio/transcriptions';

        $payload = [];
        if ($language !== '') {
            $payload['language'] = $language;
        }

        try {
            $response = RisHttp::client($timeout)
                ->acceptJson()
                ->attach(
                    'file',
                    fopen($audio->getRealPath(), 'r'),
                    $filename
                )
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::warning('faster-whisper request failed', ['error' => $e->getMessage()]);
            throw new RuntimeException(
                'Error al contactar faster-whisper: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if (!$response->successful()) {
            $body = $response->json();
            $msg = is_array($body)
                ? ($body['detail'] ?? $body['message'] ?? $response->body())
                : $response->body();
            throw new RuntimeException(
                'faster-whisper (HTTP ' . $response->status() . '): ' . $msg
            );
        }

        $text = trim((string) ($response->json('text') ?? ''));
        if ($text === '') {
            throw new RuntimeException('faster-whisper no devolvió texto.');
        }

        return $text;
    }

    private function transcribeWithOpenAi(UploadedFile $audio, string $language): string
    {
        $apiKey = (string) config('ai_transcription.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('Falta AI_TRANSCRIPTION_API_KEY / OPENAI_API_KEY.');
        }

        $url = (string) config('ai_transcription.openai_url');
        $model = (string) config('ai_transcription.model', 'whisper-1');
        $timeout = (int) config('ai_transcription.http_timeout', 180);
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
