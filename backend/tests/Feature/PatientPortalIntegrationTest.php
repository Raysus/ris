<?php

namespace Tests\Feature;

use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class PatientPortalIntegrationTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal_integration.secret' => 'test-portal-secret',
            'portal_integration.enabled' => true,
        ]);
        $this->seedRis();
    }

    public function test_portal_reports_rejects_invalid_token(): void
    {
        $this->getJson('/api/integrations/portal/reports?rut=12345678-9')
            ->assertStatus(401);
    }

    public function test_portal_reports_returns_signed_studies_for_patient_rut(): void
    {
        $appointment = $this->createTestAppointment([
            'status' => 'entregable',
        ]);

        $persona = $appointment->patient->persona;
        $study = $appointment->studies->first();
        $study->update(['report' => 'Informe de prueba portal']);

        $response = $this->withToken('test-portal-secret')
            ->withHeader('X-Lab-Id', $this->risLab->id)
            ->getJson('/api/integrations/portal/reports?rut=' . urlencode($persona->rut));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $appointment->id)
            ->assertJsonPath('data.0.studies.0.report_text', 'Informe de prueba portal');
    }

    public function test_portal_reports_hides_other_patients(): void
    {
        $appointment = $this->createTestAppointment(['status' => 'entregable']);
        $appointment->studies->first()->update(['report' => 'Secreto']);

        $this->withToken('test-portal-secret')
            ->getJson('/api/integrations/portal/reports?rut=11111111-1')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_portal_report_detail_requires_matching_rut(): void
    {
        $appointment = $this->createTestAppointment(['status' => 'entregable']);
        $persona = $appointment->patient->persona;
        $appointment->studies->first()->update(['report' => 'Detalle informe']);

        $this->withToken('test-portal-secret')
            ->getJson('/api/integrations/portal/reports/' . $appointment->id . '?rut=' . urlencode($persona->rut))
            ->assertOk()
            ->assertJsonPath('data.studies.0.report_text', 'Detalle informe');

        $this->withToken('test-portal-secret')
            ->getJson('/api/integrations/portal/reports/' . $appointment->id . '?rut=11111111-1')
            ->assertStatus(404);
    }
}
