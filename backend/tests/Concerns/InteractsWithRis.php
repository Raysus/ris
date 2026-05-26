<?php

namespace Tests\Concerns;

use App\Models\Appointment;
use App\Models\AppointmentStudy;
use App\Models\Exam;
use App\Models\Laboratory;
use App\Models\Machine;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SyncEntityToCloud;
use Laravel\Sanctum\Sanctum;

trait InteractsWithRis
{
    use RefreshDatabase;

    protected User $risUser;
    protected Laboratory $risLab;
    protected string $risToken;

    protected function fakeCloudSync(): void
    {
        Queue::fake([SyncEntityToCloud::class]);
    }

    protected function seedRis(): void
    {
        $this->fakeCloudSync();
        $this->seed(DatabaseSeeder::class);

        $this->risUser = User::where('username', 'rgutierrez')->firstOrFail();
        $this->risLab = Laboratory::where('name', 'Centro de Diagnóstico RIS PRO')->firstOrFail();
    }

    protected function loginRis(string $username = 'rgutierrez', string $password = 'rgutierrez'): string
    {
        $response = $this->postJson('/api/login', [
            'login_field' => $username,
            'password' => $password,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->risToken = $response->json('access_token');

        return $this->risToken;
    }

    protected function authHeaders(?string $labId = null): array
    {
        return [
            'Authorization' => 'Bearer ' . ($this->risToken ?? $this->loginRis()),
            'Accept' => 'application/json',
            'X-Lab-Id' => $labId ?? $this->risLab->id,
        ];
    }

    protected function actingAsRis(?User $user = null): User
    {
        $user ??= $this->risUser ?? User::where('username', 'rgutierrez')->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function createTestAppointment(array $overrides = []): Appointment
    {
        $this->fakeCloudSync();

        $lab = $this->risLab ?? Laboratory::firstOrFail();
        $machine = Machine::where('laboratory_id', $lab->id)->firstOrFail();
        $exam = Exam::where('laboratory_id', $lab->id)->first();

        $persona = Persona::create([
            'rut' => '99999999-' . random_int(1, 9),
            'names' => 'Paciente',
            'last_name_1' => 'Prueba',
            'email' => 'paciente.test@example.com',
        ]);

        $patient = Paciente::create([
            'laboratory_id' => $lab->id,
            'persona_id' => $persona->id,
        ]);

        $start = now()->addHours(2);

        $appointment = Appointment::create(array_merge([
            'laboratory_id' => $lab->id,
            'patient_id' => $patient->id,
            'machine_id' => $machine->id,
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes(30),
            'status' => 'agendado',
            'payment_status' => 'Pendiente',
            'origin' => 'Hospitalizado',
        ], $overrides));

        if ($exam) {
            AppointmentStudy::create([
                'appointment_id' => $appointment->id,
                'machine_id' => $machine->id,
                'exam_id' => $exam->id,
                'exam_name' => $exam->name,
                'fonasa_code' => $exam->fonasa_code,
                'price' => $exam->price,
                'quantity' => 1,
                'status' => 'espera',
            ]);
        }

        return $appointment->fresh(['studies.exam', 'patient.persona']);
    }
}
