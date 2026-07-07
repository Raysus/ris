<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\TipoUsuario;
use App\Models\User;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

/**
 * Smoke test del flujo crítico de la API (post-seed).
 */
class SmokeTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
        $this->loginRis();
    }

    public function test_login_returns_token_and_lab_context(): void
    {
        $response = $this->postJson('/api/login', [
            'login_field' => 'rgutierrez',
            'password' => 'rgutierrez',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'access_token',
                'user' => ['id', 'username', 'role'],
                'contexto_laboratorio' => ['laboratorio_id', 'laboratorios_permitidos'],
            ]);
    }

    public function test_dashboard_metrics_responds(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/dashboard/metrics');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'kpis' => ['pacientes', 'examenes', 'ingresos', 'tat_promedio'],
                    'charts' => ['modalidades', 'flujo'],
                ],
            ]);
    }

    public function test_agenda_catalogs_and_exams_load(): void
    {
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->getJson('/api/agenda-catalogs')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders($headers)->getJson('/api/exams')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_worklist_and_appointments_index(): void
    {
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->getJson('/api/worklist')
            ->assertOk();

        $this->withHeaders($headers)->getJson('/api/appointments')
            ->assertOk();
    }

    public function test_payments_insurance_plans_and_breakdown(): void
    {
        $appointment = $this->createTestAppointment();
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->getJson('/api/payments/insurance-plans')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'name', 'percentage']]]);

        $this->withHeaders($headers)->getJson("/api/payments/breakdown/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'appointment_id',
                    'estudios',
                    'resumen' => ['total_arancel', 'copago', 'total_a_pagar'],
                ],
            ]);
    }

    public function test_users_list_hides_sis_admin(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/users');

        $response->assertOk()->assertJsonPath('success', true);

        $usernames = collect($response->json('data'))->pluck('username');
        $this->assertFalse($usernames->contains('admin'));
        $this->assertTrue($usernames->contains('rgutierrez'));
    }

    public function test_patients_list_hides_sis_admin_users(): void
    {
        $persona = Persona::create([
            'rut' => '88.888.888-8',
            'names' => 'Sys',
            'last_name_1' => 'Admin',
        ]);

        $sisAdminType = TipoUsuario::where('name', 'sis_admin')->firstOrFail();

        User::create([
            'persona_id' => $persona->id,
            'tipo_usuario_id' => $sisAdminType->id,
            'username' => 'sis_admin_patient_test',
            'password' => 'secret1234',
            'settings' => ['roles' => ['sis_admin']],
            'is_active' => true,
        ]);

        $hiddenPatient = Paciente::create([
            'persona_id' => $persona->id,
            'laboratory_id' => $this->risLab->id,
        ]);

        $visiblePersona = Persona::create([
            'rut' => '77.777.777-7',
            'names' => 'Paciente',
            'last_name_1' => 'Visible',
        ]);

        $visiblePatient = Paciente::create([
            'persona_id' => $visiblePersona->id,
            'laboratory_id' => $this->risLab->id,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/patients?per_page=100');

        $response->assertOk()->assertJsonPath('success', true);

        $ids = collect($response->json('data.data'))->pluck('id');
        $this->assertFalse($ids->contains($hiddenPatient->id));
        $this->assertTrue($ids->contains($visiblePatient->id));
    }

    public function test_exam_instructions_can_be_created(): void
    {
        $exam = Exam::where('laboratory_id', $this->risLab->id)->firstOrFail();
        $headers = $this->authHeaders();

        $response = $this->withHeaders($headers)->postJson('/api/exams', [
            'name' => 'Examen smoke test',
            'price' => 15000,
            'fonasa_code' => 'SMK001',
            'group_code' => 'SMK',
            'instruction' => [
                'subject' => 'Preparación examen',
                'body' => 'Ayuno de 6 horas. Llevar orden médica.',
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $createdId = $response->json('exam.id');
        $this->assertNotEmpty($createdId);
        $this->assertDatabaseHas('exam_instructions', [
            'exam_id' => $createdId,
            'body' => 'Ayuno de 6 horas. Llevar orden médica.',
        ]);
    }

    public function test_radiologist_and_delivery_modules_respond(): void
    {
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->getJson('/api/radiologist/studies')->assertOk();
        $this->withHeaders($headers)->getJson('/api/transcription/studies')->assertOk();
        $this->withHeaders($headers)->getJson('/api/radiologist/validations')->assertOk();
        $this->withHeaders($headers)->getJson('/api/delivery/studies')->assertOk();
    }

    public function test_unauthenticated_routes_return_401(): void
    {
        $this->getJson('/api/dashboard/metrics')->assertUnauthorized();
        $this->getJson('/api/appointments')->assertUnauthorized();
        $this->postJson('/api/appointments')->assertUnauthorized();
    }
}
