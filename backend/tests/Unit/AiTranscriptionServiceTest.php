<?php

namespace Tests\Unit;

use App\Services\AiTranscriptionService;
use Tests\TestCase;

class AiTranscriptionServiceTest extends TestCase
{
    public function test_status_reports_disabled_when_flag_off(): void
    {
        config([
            'ai_transcription.enabled' => false,
            'ai_transcription.provider' => 'faster_whisper',
            'ai_transcription.whisper_url' => 'http://127.0.0.1:8765',
            'ai_transcription.proxy_to_cloud' => false,
            'cloud_sync.role' => 'cloud',
        ]);

        $status = app(AiTranscriptionService::class)->status();

        $this->assertFalse($status['enabled']);
        $this->assertFalse($status['available']);
        $this->assertStringContainsString('desactivada', $status['message']);
    }

    public function test_cloud_without_whisper_url_reports_not_configured(): void
    {
        config([
            'ai_transcription.enabled' => true,
            'ai_transcription.provider' => 'faster_whisper',
            'ai_transcription.whisper_url' => '',
            'ai_transcription.api_key' => '',
            'ai_transcription.proxy_to_cloud' => false,
            'cloud_sync.role' => 'cloud',
        ]);

        $status = app(AiTranscriptionService::class)->status();

        $this->assertFalse($status['configured']);
        $this->assertFalse($status['available']);
        $this->assertStringContainsString('faster-whisper', $status['message']);
    }

    public function test_local_without_cloud_url_is_not_configured(): void
    {
        config([
            'ai_transcription.enabled' => true,
            'ai_transcription.proxy_to_cloud' => true,
            'ai_transcription.cloud_transcribe_url' => null,
            'ai_transcription.allow_local_provider' => false,
            'cloud_sync.role' => 'local',
            'cloud_sync.secret' => 'secret',
            'cloud_sync.inbound_url' => null,
        ]);

        $status = app(AiTranscriptionService::class)->status();

        $this->assertFalse($status['configured']);
        $this->assertFalse($status['available']);
    }
}
