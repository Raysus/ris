<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Machine;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\ReferringDoctor;
use App\Support\CloudSyncMode;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class CloudSyncInboundTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cloud_sync.secret' => 'test-sync-secret',
            'cloud_sync.inbound_enabled' => true,
            'cloud_sync.role' => 'cloud',
        ]);
        $this->seedRis();
    }

    public function test_inbound_rejects_invalid_token(): void
    {
        $this->postJson('/api/integrations/cloud-sync/inbound', [
            'model' => 'ReferringDoctor',
            'action' => 'updated',
            'data' => ['id' => '00000000-0000-0000-0000-000000000099', 'names' => 'Test'],
        ])->assertStatus(401);
    }

    public function test_inbound_upserts_referring_doctor(): void
    {
        $id = '11111111-1111-1111-1111-111111111111';

        $this->withToken('test-sync-secret')
            ->postJson('/api/integrations/cloud-sync/inbound', [
                'model' => 'ReferringDoctor',
                'action' => 'updated',
                'data' => [
                    'id' => $id,
                    'rut' => '11.111.111-1',
                    'names' => 'Medico',
                    'last_name_1' => 'Sync',
                    'phone' => '900000000',
                    'email' => 'sync@test.cl',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('referring_doctors', [
            'id' => $id,
            'names' => 'Medico',
        ]);
    }

    public function test_export_returns_catalog(): void
    {
        ReferringDoctor::create([
            'id' => '22222222-2222-2222-2222-222222222222',
            'rut' => '22.222.222-2',
            'names' => 'Export',
            'last_name_1' => 'Doc',
        ]);

        $this->withToken('test-sync-secret')
            ->getJson('/api/integrations/cloud-sync/export')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['referring_doctors', 'exams', 'machines']]);
    }

    public function test_inbound_appointment_creates_patient_then_appointment(): void
    {
        $lab = $this->risLab;
        $machine = Machine::where('laboratory_id', $lab->id)->firstOrFail();
        $personaId = '33333333-3333-3333-3333-333333333333';
        $pacienteId = '44444444-4444-4444-4444-444444444444';
        $appointmentId = '55555555-5555-5555-5555-555555555555';
        $start = now()->addDays(5);

        $this->withToken('test-sync-secret')
            ->postJson('/api/integrations/cloud-sync/inbound', [
                'model' => 'App\Models\Appointment',
                'action' => 'created',
                'data' => [
                    'id' => $appointmentId,
                    'laboratory_id' => $lab->id,
                    'patient_id' => $pacienteId,
                    'machine_id' => $machine->id,
                    'start_time' => $start->toIso8601String(),
                    'end_time' => $start->copy()->addMinutes(30)->toIso8601String(),
                    'status' => 'agendado',
                    'payment_status' => 'Pendiente',
                    'origin' => 'Ambulatorio',
                    'patient' => [
                        'id' => $pacienteId,
                        'laboratory_id' => $lab->id,
                        'persona_id' => $personaId,
                        'persona' => [
                            'id' => $personaId,
                            'rut' => '18.765.432-1',
                            'names' => 'Inbound',
                            'last_name_1' => 'Paciente',
                        ],
                    ],
                    'studies' => [],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('patients', ['id' => $pacienteId, 'persona_id' => $personaId]);
        $this->assertDatabaseHas('appointments', ['id' => $appointmentId, 'patient_id' => $pacienteId]);
    }

    public function test_admin_can_pull_catalog_on_cloud(): void
    {
        $this->loginRis();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/integrations/cloud-sync/pull-catalog', [
                'include_patients' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(CloudSyncMode::acceptsInbound());
    }
}
