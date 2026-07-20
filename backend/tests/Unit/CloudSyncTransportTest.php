<?php

namespace Tests\Unit;

use App\Support\CloudSyncTransport;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

class CloudSyncTransportTest extends TestCase
{
    public function test_detects_connection_errors_as_transient(): void
    {
        $this->assertTrue(CloudSyncTransport::isTransient(
            new ConnectionException('cURL error 28: Connection timed out')
        ));
        $this->assertTrue(CloudSyncTransport::isTransientMessage('Failed to connect to host'));
    }

    public function test_detects_5xx_as_transient_http(): void
    {
        $this->assertTrue(CloudSyncTransport::isTransientHttpStatus(503));
        $this->assertFalse(CloudSyncTransport::isTransientHttpStatus(422));
        $this->assertTrue(CloudSyncTransport::isPermanentHttpStatus(422));
    }

    public function test_detects_413_as_oversized_payload(): void
    {
        $this->assertTrue(CloudSyncTransport::isOversizedPayloadMessage(
            'Sync bundle falló en App\Models\Appointment: HTTP 413 — Request Entity Too Large'
        ));
        $this->assertFalse(CloudSyncTransport::isOversizedPayloadMessage('HTTP 422 Unprocessable'));
    }

    public function test_release_delay_grows_with_attempts(): void
    {
        config(['cloud_sync.pending_release_seconds' => 60, 'cloud_sync.max_release_backoff' => 900]);

        $this->assertSame(60, CloudSyncTransport::releaseDelaySeconds(1));
        $this->assertSame(180, CloudSyncTransport::releaseDelaySeconds(3));
        $this->assertSame(900, CloudSyncTransport::releaseDelaySeconds(50));
    }
}
