<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Laboratory;
use App\Models\User;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

/**
 * QA API — laboratorios demo (sin SIRESA).
 */
class DemoLabsQaTest extends TestCase
{
    use InteractsWithRis;

    private const DEMO_LAB_NAMES = [
        'Centro de Diagnóstico RIS PRO',
        'Sucursal Sur',
        'Dental Demo — CBCT Temuco',
        'Dental Demo — Sucursal Centro',
        'Veterinaria Demo Sur',
        'Veterinaria Demo — Urgencias 24h',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis(true);
    }

    private function demoLabs()
    {
        return Laboratory::query()
            ->whereIn('name', self::DEMO_LAB_NAMES)
            ->orderBy('name')
            ->get();
    }

    private function authAs(string $username): array
    {
        $this->loginRis($username, $username === 'friquelme' ? 'friquelme' : $username);

        return $this->authHeaders();
    }

    private function headersForLab(string $labName, string $username = 'rgutierrez'): array
    {
        $lab = Laboratory::where('name', $labName)->firstOrFail();
        $headers = $this->authAs($username);
        $headers['X-Lab-Id'] = $lab->id;

        return $headers;
    }

    public function test_demo_laboratories_exist_and_exclude_siresa_matrix(): void
    {
        $demos = $this->demoLabs();
        $this->assertGreaterThanOrEqual(4, $demos->count());

        foreach ($demos as $lab) {
            $this->assertStringNotContainsStringIgnoringCase('siresa', $lab->name);
        }
    }

    public function test_clinical_demo_all_api_modules_respond(): void
    {
        $headers = $this->headersForLab('Centro de Diagnóstico RIS PRO');

        $endpoints = [
            '/api/lab-profile',
            '/api/agenda-catalogs',
            '/api/exams',
            '/api/machines',
            '/api/appointments',
            '/api/worklist',
            '/api/dashboard/metrics',
            '/api/radiologist/studies',
            '/api/transcription/appointments',
            '/api/radiologist/validations',
            '/api/delivery/studies',
            '/api/templates',
            '/api/supplies',
            '/api/settings',
            '/api/users',
            '/api/payments/insurance-plans',
            '/api/patients',
        ];

        foreach ($endpoints as $path) {
            $this->withHeaders($headers)->getJson($path)->assertOk();
        }
    }

    public function test_clinical_demo_has_demo_scenarios_in_workflow_modules(): void
    {
        $lab = Laboratory::where('name', 'Centro de Diagnóstico RIS PRO')->firstOrFail();
        $headers = $this->headersForLab('Centro de Diagnóstico RIS PRO');

        $this->assertGreaterThan(
            0,
            Appointment::where('laboratory_id', $lab->id)->where('accession_number', 'like', 'DEMO-%')->count()
        );

        $worklist = $this->withHeaders($headers)->getJson('/api/worklist')->json('data');
        $this->assertNotEmpty($worklist);

        $radiologist = $this->withHeaders($headers)->getJson('/api/radiologist/studies')->json('data');
        $this->assertNotEmpty($radiologist);

        $transcription = $this->withHeaders($headers)->getJson('/api/transcription/appointments')->json('data');
        $this->assertNotEmpty($transcription);

        $validations = $this->withHeaders($headers)->getJson('/api/radiologist/validations')->json('data');
        $this->assertNotEmpty($validations);

        $delivery = $this->withHeaders($headers)->getJson('/api/delivery/studies')->json('data');
        $this->assertNotEmpty($delivery);
    }

    public function test_dental_demo_lab_profile_and_modules(): void
    {
        $headers = $this->headersForLab('Dental Demo — CBCT Temuco');

        $this->withHeaders($headers)->getJson('/api/lab-profile')
            ->assertOk()
            ->assertJsonPath('data.code', 'dental')
            ->assertJsonPath('data.uses_dicom_worklist', false)
            ->assertJsonPath('data.show_fonasa_panel', false);

        $this->withHeaders($headers)->getJson('/api/machines')->assertOk();
        $this->withHeaders($headers)->getJson('/api/appointments')->assertOk();
        $this->withHeaders($headers)->getJson('/api/worklist')->assertOk();
        $this->withHeaders($headers)->getJson('/api/agenda-catalogs')->assertOk();
    }

    public function test_veterinary_demo_lab_profile(): void
    {
        $headers = $this->headersForLab('Veterinaria Demo Sur');

        $this->withHeaders($headers)->getJson('/api/lab-profile')
            ->assertOk()
            ->assertJsonPath('data.code', 'veterinary')
            ->assertJsonPath('data.uses_dicom_worklist', false)
            ->assertJsonPath('data.patient_label', 'Mascota');
    }

    public function test_friquelme_technologist_worklist_on_clinical_lab(): void
    {
        $headers = $this->headersForLab('Centro de Diagnóstico RIS PRO', 'friquelme');
        $this->withHeaders($headers)->getJson('/api/worklist')->assertOk();
    }

    public function test_machine_create_on_dental_demo_lab(): void
    {
        $lab = Laboratory::where('name', 'Dental Demo — CBCT Temuco')->firstOrFail();
        $user = User::where('username', 'rgutierrez')->firstOrFail();
        $this->actingAsRis($user);
        $headers = $this->authHeaders($lab->id);

        $response = $this->withHeaders($headers)->postJson('/api/machines', [
            'name' => 'Sala QA Dental',
            'group' => 'CBCT',
            'description' => 'Creada en test demo',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('machines', [
            'laboratory_id' => $lab->id,
            'name' => 'Sala QA Dental',
        ]);
    }

    public function test_demo_appointments_exist_per_lab(): void
    {
        $lab = Laboratory::where('name', 'Centro de Diagnóstico RIS PRO')->firstOrFail();
        $headers = $this->headersForLab('Centro de Diagnóstico RIS PRO');

        $response = $this->withHeaders($headers)->getJson('/api/appointments');
        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_patient_search_requires_concrete_lab_header(): void
    {
        $this->actingAsRis(User::where('username', 'rgutierrez')->firstOrFail());

        $this->getJson('/api/patients/search?rut=18.194.675-K', [
            'Accept' => 'application/json',
        ])->assertStatus(400);
    }

    public function test_payment_breakdown_on_demo_appointment(): void
    {
        $lab = Laboratory::where('name', 'Centro de Diagnóstico RIS PRO')->firstOrFail();
        $appointment = Appointment::where('laboratory_id', $lab->id)
            ->where('accession_number', 'like', 'DEMO-%')
            ->firstOrFail();

        $headers = $this->headersForLab('Centro de Diagnóstico RIS PRO');

        $this->withHeaders($headers)->getJson("/api/payments/breakdown/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_sucursal_sur_demo_lab_accessible(): void
    {
        $headers = $this->headersForLab('Sucursal Sur');
        $this->withHeaders($headers)->getJson('/api/appointments')->assertOk();
        $this->withHeaders($headers)->getJson('/api/machines')->assertOk();
    }

    public function test_siresa_labs_not_in_demo_qa_scope(): void
    {
        $siresa = Laboratory::where('name', 'like', 'Siresa%')->count();
        $this->assertGreaterThan(0, $siresa);

        $demos = $this->demoLabs()->pluck('name')->all();
        foreach ($demos as $name) {
            $this->assertStringNotContainsStringIgnoringCase('siresa', $name);
        }
    }
}
