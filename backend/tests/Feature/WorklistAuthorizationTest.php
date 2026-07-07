<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Machine;
use App\Models\Paciente;
use App\Models\User;
use App\Models\AppointmentStudy;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class WorklistAuthorizationTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
    }

    public function test_worklist_denies_user_without_operational_role(): void
    {
        Sanctum::actingAs($this->createRecepcionTestUser());

        $this->withHeaders(['X-Lab-Id' => $this->risLab->id, 'Accept' => 'application/json'])
            ->getJson('/api/worklist')
            ->assertForbidden();
    }

    public function test_worklist_allows_tecnologo(): void
    {
        Sanctum::actingAs(User::where('username', 'friquelme')->firstOrFail());

        $this->withHeaders(['X-Lab-Id' => $this->risLab->id, 'Accept' => 'application/json'])
            ->getJson('/api/worklist')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_worklist_filters_by_machine_id(): void
    {
        Sanctum::actingAs(User::where('username', 'friquelme')->firstOrFail());

        $machineA = Machine::where('laboratory_id', $this->risLab->id)->firstOrFail();
        $machineB = Machine::create([
            'laboratory_id' => $this->risLab->id,
            'name' => 'Sala Filtro Test B',
            'group' => 'CT',
            'is_active' => true,
        ]);

        $patient = Paciente::where('laboratory_id', $this->risLab->id)->firstOrFail();
        $start = Carbon::now()->addHour();

        $appointmentA = Appointment::create([
            'laboratory_id' => $this->risLab->id,
            'patient_id' => $patient->id,
            'machine_id' => $machineA->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes(30),
            'status' => 'confirmado',
        ]);
        AppointmentStudy::create([
            'appointment_id' => $appointmentA->id,
            'exam_name' => 'RX Filtro A',
            'machine_id' => null,
            'quantity' => 1,
            'price' => 1000,
        ]);

        $appointmentB = Appointment::create([
            'laboratory_id' => $this->risLab->id,
            'patient_id' => $patient->id,
            'machine_id' => $machineB->id,
            'start_time' => $start->copy()->addHour(),
            'end_time' => $start->copy()->addMinutes(90),
            'status' => 'confirmado',
        ]);
        AppointmentStudy::create([
            'appointment_id' => $appointmentB->id,
            'exam_name' => 'TC Filtro B',
            'machine_id' => null,
            'quantity' => 1,
            'price' => 2000,
        ]);

        $headers = ['X-Lab-Id' => $this->risLab->id, 'Accept' => 'application/json'];

        $filtered = $this->withHeaders($headers)
            ->getJson('/api/worklist?machine_id=' . $machineA->id)
            ->assertOk()
            ->json('data');

        $examNames = collect($filtered)->pluck('exam_name');
        $this->assertTrue($examNames->contains('RX Filtro A'));
        $this->assertFalse($examNames->contains('TC Filtro B'));
        $this->assertSame($machineA->id, $filtered[0]['machine_id'] ?? null);
    }
}
