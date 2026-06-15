<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Exam;
use App\Models\Machine;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\ReferringDoctor;
use App\Services\CloudCatalogPullService;
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

    public function test_inbound_machine_sync_is_idempotent_by_uuid(): void
    {
        $lab = $this->risLab;
        $machineId = '66666666-6666-6666-6666-666666666666';
        $payload = [
            'id' => $machineId,
            'laboratory_id' => $lab->id,
            'name' => 'Sala Sync Test',
            'group' => 'CR',
            'ae_title' => 'SYNC_TEST',
            'event_color' => '#3788d8',
            'is_active' => true,
        ];

        foreach ([1, 2] as $attempt) {
            $this->withToken('test-sync-secret')
                ->postJson('/api/integrations/cloud-sync/inbound', [
                    'model' => 'Machine',
                    'action' => 'updated',
                    'data' => array_merge($payload, ['name' => 'Sala Sync Test ' . $attempt]),
                ])
                ->assertOk();
        }

        $this->assertSame(1, Machine::query()->where('laboratory_id', $lab->id)->where('ae_title', 'SYNC_TEST')->count());
        $this->assertDatabaseHas('machines', [
            'id' => $machineId,
            'name' => 'Sala Sync Test 2',
        ]);
    }

    public function test_inbound_appointment_sync_is_idempotent_by_uuid_and_accession(): void
    {
        $lab = $this->risLab;
        $machine = Machine::where('laboratory_id', $lab->id)->firstOrFail();
        $personaId = '77777777-7777-7777-7777-777777777777';
        $pacienteId = '88888888-8888-8888-8888-888888888888';
        $appointmentId = '99999999-9999-9999-9999-999999999999';
        $studyId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $start = now()->addDays(7);
        $payload = [
            'id' => $appointmentId,
            'laboratory_id' => $lab->id,
            'patient_id' => $pacienteId,
            'machine_id' => $machine->id,
            'accession_number' => 'ACC-SYNC-IDEMPOTENT',
            'start_time' => $start->toIso8601String(),
            'end_time' => $start->copy()->addMinutes(30)->toIso8601String(),
            'status' => 'confirmado',
            'payment_status' => 'Pendiente',
            'origin' => 'Ambulatorio',
            'patient' => [
                'id' => $pacienteId,
                'laboratory_id' => $lab->id,
                'persona_id' => $personaId,
                'persona' => [
                    'id' => $personaId,
                    'rut' => '19.876.543-2',
                    'names' => 'Idempotent',
                    'last_name_1' => 'Sync',
                ],
            ],
            'studies' => [[
                'id' => $studyId,
                'appointment_id' => $appointmentId,
                'machine_id' => $machine->id,
                'exam_name' => 'Rx Test',
                'quantity' => 1,
                'price' => 1000,
                'status' => 'espera',
            ]],
        ];

        foreach (range(1, 2) as $attempt) {
            $this->withToken('test-sync-secret')
                ->postJson('/api/integrations/cloud-sync/inbound', [
                    'model' => 'App\Models\Appointment',
                    'action' => 'updated',
                    'data' => $payload,
                ])
                ->assertOk();
        }

        $this->assertSame(
            1,
            Appointment::query()
                ->where('laboratory_id', $lab->id)
                ->where('accession_number', 'ACC-SYNC-IDEMPOTENT')
                ->count()
        );
        $this->assertDatabaseHas('appointments', ['id' => $appointmentId]);
        $this->assertSame(1, \App\Models\AppointmentStudy::query()->where('appointment_id', $appointmentId)->count());
    }

    public function test_inbound_referring_doctor_updates_existing_row_by_rut_instead_of_duplicate(): void
    {
        $existingId = 'dddddddd-dddd-dddd-dddd-dddddddddddd';
        $incomingId = 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee';

        ReferringDoctor::create([
            'id' => $existingId,
            'rut' => '12345678-9',
            'names' => 'Medico',
            'last_name_1' => 'Local',
        ]);

        $this->withToken('test-sync-secret')
            ->postJson('/api/integrations/cloud-sync/inbound', [
                'model' => 'ReferringDoctor',
                'action' => 'updated',
                'data' => [
                    'id' => $incomingId,
                    'rut' => '12.345.678-9',
                    'names' => 'Medico',
                    'last_name_1' => 'Nube',
                    'phone' => '900000001',
                    'email' => 'nube@test.cl',
                ],
            ])
            ->assertOk();

        $this->assertSame(1, ReferringDoctor::query()->count());
        $this->assertDatabaseHas('referring_doctors', [
            'id' => $existingId,
            'rut' => '12345678-9',
            'last_name_1' => 'Nube',
            'email' => 'nube@test.cl',
        ]);
        $this->assertDatabaseMissing('referring_doctors', ['id' => $incomingId]);
    }

    public function test_pull_catalog_skips_unchanged_referring_doctors_with_different_rut_format(): void
    {
        $doctor = ReferringDoctor::create([
            'id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'rut' => '11111111-1',
            'names' => 'Doctor',
            'last_name_1' => 'Sync',
        ]);

        $payload = [
            'referring_doctors' => [[
                ...$doctor->fresh()->toArray(),
                'rut' => '11.111.111-1',
            ]],
        ];

        $pull = app(CloudCatalogPullService::class);
        $first = $pull->pull(null, false, $payload);
        $second = $pull->pull(null, false, $payload);

        $this->assertSame(0, $first['counts']['referring_doctors']);
        $this->assertSame(1, $first['counts']['referring_doctors_skipped']);
        $this->assertSame(0, $second['counts']['referring_doctors']);
        $this->assertSame(1, $second['counts']['referring_doctors_skipped']);
        $this->assertSame(1, ReferringDoctor::query()->count());
    }

    public function test_inbound_exam_updates_existing_row_by_business_key_instead_of_duplicate(): void
    {
        $lab = $this->risLab;
        $existingId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $incomingId = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

        Exam::create([
            'id' => $existingId,
            'laboratory_id' => $lab->id,
            'group_code' => 'US',
            'name' => 'Ecotomografía abdominal',
            'fonasa_code' => '0404003',
            'price' => 48000,
            'is_active' => true,
        ]);

        $this->withToken('test-sync-secret')
            ->postJson('/api/integrations/cloud-sync/inbound', [
                'model' => 'Exam',
                'action' => 'updated',
                'data' => [
                    'id' => $incomingId,
                    'laboratory_id' => $lab->id,
                    'group_code' => 'MAMO',
                    'name' => 'Ecotomografía abdominal',
                    'fonasa_code' => '0404003',
                    'price' => 48000,
                    'is_active' => true,
                ],
            ])
            ->assertOk();

        $this->assertSame(1, Exam::where('laboratory_id', $lab->id)
            ->whereRaw('LOWER(TRIM(name)) = ?', ['ecotomografía abdominal'])
            ->count());
        $this->assertDatabaseHas('exams', [
            'id' => $existingId,
            'group_code' => 'MAMO',
        ]);
        $this->assertDatabaseMissing('exams', ['id' => $incomingId]);
    }

    public function test_pull_catalog_skips_unchanged_exams(): void
    {
        $lab = $this->risLab;
        $exam = Exam::create([
            'id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'laboratory_id' => $lab->id,
            'group_code' => 'RX',
            'name' => 'Tórax simple',
            'fonasa_code' => '0401070',
            'price' => 28000,
            'is_active' => true,
        ]);

        $payload = [
            'exams' => [$exam->fresh()->toArray()],
        ];

        $pull = app(CloudCatalogPullService::class);
        $first = $pull->pull($lab->id, false, $payload);
        $second = $pull->pull($lab->id, false, $payload);

        $this->assertSame(1, $first['counts']['exams']);
        $this->assertSame(0, $second['counts']['exams']);
        $this->assertSame(1, $second['counts']['exams_skipped']);
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
