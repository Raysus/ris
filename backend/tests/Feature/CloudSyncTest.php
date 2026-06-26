<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentBundleToCloud;
use App\Jobs\SyncEntityToCloud;
use App\Models\CloudSyncLog;
use App\Services\CloudSyncLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class CloudSyncTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
        $this->loginRis();
    }

    public function test_sync_job_creates_log_with_skipped_when_not_configured(): void
    {
        Queue::fake();

        $before = CloudSyncLog::count();

        SyncEntityToCloud::dispatch('TestEntity', 'updated', ['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $this->assertEquals($before + 1, CloudSyncLog::count());

        $log = CloudSyncLog::where('entity_type', 'TestEntity')->latest()->first();
        $this->assertEquals('pending', $log->status);

        $job = new SyncEntityToCloud('TestEntity', 'updated', ['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'], $log->id);
        $job->handle();

        $log->refresh();
        $this->assertEquals('skipped', $log->status);
    }

    public function test_sync_stays_pending_on_connection_error(): void
    {
        config([
            'cloud_sync.role' => 'local',
            'cloud_sync.secret' => 'test-secret',
            'cloud_sync.inbound_url' => 'https://cloud.test/integrations/cloud-sync/inbound',
        ]);

        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect');
        });

        $log = CloudSyncLogger::startPending('TestEntity', 'updated', [
            'id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        ]);

        $job = new class('TestEntity', 'updated', ['id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'], $log->id) extends SyncEntityToCloud {
            public ?int $releasedAfter = null;

            public function release($delay = 0)
            {
                $this->releasedAfter = $delay;

                return $this;
            }
        };

        $job->handle();

        $log->refresh();
        $this->assertSame('pending', $log->status);
        $this->assertNotNull($log->last_error);
        $this->assertNotNull($job->releasedAfter);
    }

    public function test_admin_can_list_and_retry_cloud_sync(): void
    {
        $log = CloudSyncLog::create([
            'entity_type' => 'Appointment',
            'action' => 'updated',
            'status' => 'failed',
            'attempts' => 1,
            'payload' => ['id' => '00000000-0000-0000-0000-000000000099', 'status' => 'agendado'],
            'payload_hash' => 'abc',
            'last_error' => 'Timeout',
        ]);

        Queue::fake();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/integrations/cloud-sync')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stats.failed', 1);

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/integrations/cloud-sync/{$log->id}/retry")
            ->assertOk()
            ->assertJsonPath('success', true);

        Queue::assertPushed(SyncAppointmentBundleToCloud::class);
    }

    public function test_cash_close_report(): void
    {
        $appointment = $this->createTestAppointment(['payment_status' => 'Pendiente']);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/payments', [
                'appointment_id' => $appointment->id,
                'amount' => 25000,
                'payment_method' => 'Efectivo',
                'status' => 'Pagado',
            ])
            ->assertOk();

        $fecha = now()->format('Y-m-d');

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/payments/reports/cash-close?fecha={$fecha}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'resumen' => ['total_cobrado', 'transacciones', 'citas_pendientes'],
                    'por_metodo',
                    'pagos',
                    'citas_pendientes',
                ],
            ]);
    }
}
