<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Exam;
use App\Models\Insurance;
use App\Models\InsurancePlan;
use App\Models\Laboratory;
use App\Models\Machine;
use App\Models\Paciente;
use App\Models\Persona;
use Carbon\Carbon;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

/**
 * Flujo corregido: salas (admin), búsqueda de paciente y guardado de cita con horario.
 */
class AdminAgendaWorkflowTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
        $this->loginRis();
        $this->actingAsRis();
    }

    public function test_machines_can_be_created_and_updated(): void
    {
        $headers = $this->authHeaders();

        $create = $this->withHeaders($headers)->postJson('/api/machines', [
            'name' => 'Sala Test Automatizado',
            'group' => 'RX',
            'manufacturer' => 'TestCo',
            'model_name' => 'Model-X',
            'description' => 'Creada por PHPUnit',
            'ae_title' => 'TEST_AE',
            'ip_address' => '127.0.0.1',
            'port' => 104,
        ]);

        $create->assertOk()
            ->assertJsonPath('success', true);

        $machineId = $create->json('machine.id');
        $this->assertNotEmpty($machineId);

        $this->assertDatabaseHas('machines', [
            'id' => $machineId,
            'laboratory_id' => $this->risLab->id,
            'name' => 'Sala Test Automatizado',
            'group' => 'RX',
            // Regresión: estos campos DICOM se descartaban por faltar en $fillable.
            'ae_title' => 'TEST_AE',
            'ip_address' => '127.0.0.1',
            'port' => '104',
        ]);

        $update = $this->withHeaders($headers)->postJson('/api/machines', [
            'id' => $machineId,
            'name' => 'Sala Test Actualizada',
            'group' => 'CT',
            'manufacturer' => 'TestCo',
            'model_name' => 'Model-Y',
            'description' => 'Editada por PHPUnit',
        ]);

        $update->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('machines', [
            'id' => $machineId,
            'name' => 'Sala Test Actualizada',
            'group' => 'CT',
        ]);
    }

    public function test_machines_index_lists_lab_equipment(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/machines');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Scanner GE'));
    }

    public function test_patient_search_requires_lab_header(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->risToken,
            'Accept' => 'application/json',
        ])->getJson('/api/patients/search?rut=18.194.675-K')
            ->assertStatus(400)
            ->assertJsonPath('message', 'Seleccione un laboratorio en la barra superior.');
    }

    public function test_patient_search_finds_seeded_persona(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/patients/search?rut=' . urlencode('18.194.675-K'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.persona.names', 'Raúl Antonio')
            ->assertJsonPath('data.persona.last_name_1', 'Gutiérrez')
            ->assertJsonPath('data.names', 'Raúl Antonio');
    }

    public function test_patient_search_reads_persona_without_local_patients_row(): void
    {
        $persona = Persona::upsertByRut('22.222.222-2', [
            'names' => 'Solo',
            'last_name_1' => 'Persona',
            'gender' => 'F',
            'email' => 'solo.persona@test.example',
        ]);

        Paciente::where('persona_id', $persona->id)->delete();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/patients/search?rut=' . urlencode('22.222.222-2'))
            ->assertOk()
            ->assertJsonPath('data.persona.names', 'Solo')
            ->assertJsonPath('data.has_ficha_in_lab', false)
            ->assertJsonPath('data.patient_id', null);
    }

    public function test_patient_search_returns_404_for_unknown_rut(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/patients/search?rut=' . urlencode('99.999.999-9'))
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_patient_search_loads_prior_appointment_without_local_patient_row(): void
    {
        $persona = Persona::findByRut('18.194.675-K');
        $this->assertNotNull($persona);

        $patientHome = Paciente::firstOrCreate(
            [
                'persona_id' => $persona->id,
                'laboratory_id' => $this->risLab->id,
            ],
            [
                'persona_id' => $persona->id,
                'laboratory_id' => $this->risLab->id,
            ]
        );

        $insurance = Insurance::query()->where('is_active', true)->firstOrFail();
        $plan = InsurancePlan::query()->where('insurance_id', $insurance->id)->firstOrFail();
        $machine = Machine::where('laboratory_id', $this->risLab->id)->firstOrFail();

        Appointment::create([
            'laboratory_id' => $this->risLab->id,
            'patient_id' => $patientHome->id,
            'machine_id' => $machine->id,
            'start_time' => now()->subDay(),
            'end_time' => now()->subDay()->addMinutes(30),
            'status' => 'agendado',
            'insurance_id' => $insurance->id,
            'insurance_plan_id' => $plan->id,
        ]);

        $otherLab = Laboratory::create([
            'laboratory_type_id' => $this->risLab->laboratory_type_id,
            'name' => 'Sede B QA Agenda',
            'address' => 'Test',
            'phone' => '000',
            'is_active' => true,
        ]);

        Paciente::where('persona_id', $persona->id)
            ->where('laboratory_id', $otherLab->id)
            ->delete();

        $headers = array_merge($this->authHeaders(), ['X-Lab-Id' => $otherLab->id]);

        $response = $this->withHeaders($headers)
            ->getJson('/api/patients/search?rut=' . urlencode('18.194.675-K'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.persona.names', 'Raúl Antonio')
            ->assertJsonPath('data.insurance_id', $insurance->id)
            ->assertJsonPath('data.insurance_plan_id', $plan->id)
            ->assertJsonPath('data.has_ficha_in_lab', false);

        $this->assertDatabaseMissing('patients', [
            'persona_id' => $persona->id,
            'laboratory_id' => $otherLab->id,
        ]);
    }

    public function test_appointment_store_saves_selected_start_time(): void
    {
        $machine = Machine::where('laboratory_id', $this->risLab->id)->firstOrFail();
        $exam = Exam::where('laboratory_id', $this->risLab->id)->firstOrFail();

        $slotStart = now()->addDays(21)->setTime(11, 15, 0);
        $slotEnd = $slotStart->copy()->addMinutes(30);

        $payload = [
            'start_time' => $slotStart->format('Y-m-d\TH:i:s'),
            'end_time' => $slotEnd->format('Y-m-d\TH:i:s'),
            'machine_id' => $machine->id,
            'status' => 'pre-agendado',
            'patient' => [
                'rut' => '12.345.678-5',
                'names' => 'Juan',
                'last_name_1' => 'Prueba',
                'last_name_2' => null,
                'gender' => 'M',
                'birth_date' => '1990-01-01',
                'email' => 'juan.prueba@test.local',
                'phone' => '+56900000000',
                'insurance_id' => null,
                'insurance_plan_id' => null,
            ],
            'studies' => [
                [
                    'machine_id' => $machine->id,
                    'exam_id' => $exam->id,
                    'exam_name' => $exam->name,
                    'sub_exam_id' => null,
                    'sub_exam_name' => null,
                    'fonasa_code' => $exam->fonasa_code,
                    'quantity' => 1,
                    'price' => (float) $exam->price,
                ],
            ],
            'supplies' => [],
            'origin' => 'Ambulatorio',
            'priority' => 'Normal',
            'payment_method' => 'Efectivo',
            'payment_status' => 'Pendiente',
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/appointments', ['data' => json_encode($payload)]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $appointmentId = $response->json('appointment.id');
        $appointment = Appointment::findOrFail($appointmentId);

        $expectedStart = Carbon::parse($payload['start_time']);
        $this->assertTrue(
            $appointment->start_time->equalTo($expectedStart),
            sprintf(
                'Horario guardado (%s) no coincide con el bloque seleccionado (%s).',
                $appointment->start_time->toDateTimeString(),
                $expectedStart->toDateTimeString()
            )
        );
        $this->assertEquals($machine->id, $appointment->machine_id);
        $this->assertEquals($this->risLab->id, $appointment->laboratory_id);
    }

    public function test_appointment_store_treats_dash_insurance_plan_as_null(): void
    {
        $machine = Machine::where('laboratory_id', $this->risLab->id)->firstOrFail();
        $exam = Exam::where('laboratory_id', $this->risLab->id)->firstOrFail();
        $insurance = Insurance::where('laboratory_id', $this->risLab->id)->firstOrFail();

        $slotStart = now()->addDays(22)->setTime(9, 0, 0);
        $slotEnd = $slotStart->copy()->addMinutes(15);

        $payload = [
            'start_time' => $slotStart->format('Y-m-d\TH:i:s'),
            'end_time' => $slotEnd->format('Y-m-d\TH:i:s'),
            'machine_id' => $machine->id,
            'status' => 'confirmado',
            'patient' => [
                'rut' => '16.894.365-7',
                'names' => 'Plan',
                'last_name_1' => 'Dash',
                'insurance_id' => $insurance->id,
                'insurance_plan_id' => '-',
            ],
            'studies' => [
                [
                    'machine_id' => $machine->id,
                    'exam_id' => $exam->id,
                    'exam_name' => $exam->name,
                    'quantity' => 1,
                    'price' => (float) $exam->price,
                ],
            ],
            'supplies' => [],
            'origin' => 'Ambulatorio',
            'priority' => 'Normal',
            'payment_method' => 'Efectivo',
            'payment_status' => 'Pagado',
        ];

        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/appointments', ['data' => json_encode($payload)]);

        $response->assertCreated()->assertJsonPath('success', true);

        $appointment = Appointment::findOrFail($response->json('appointment.id'));
        $this->assertSame($insurance->id, $appointment->insurance_id);
        $this->assertNull($appointment->insurance_plan_id);
    }

    public function test_appointment_store_rejects_schedule_without_lab_header(): void
    {
        $machine = Machine::where('laboratory_id', $this->risLab->id)->firstOrFail();

        $this->postJson('/api/appointments', [
            'data' => json_encode([
                'start_time' => now()->addDay()->toIso8601String(),
                'end_time' => now()->addDay()->addMinutes(30)->toIso8601String(),
                'machine_id' => $machine->id,
                'status' => 'pre-agendado',
                'patient' => ['rut' => '1-9', 'names' => 'X', 'last_name_1' => 'Y'],
                'studies' => [],
            ]),
        ], [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->risToken,
        ])->assertStatus(400)
            ->assertJsonPath('success', false);
    }
}
