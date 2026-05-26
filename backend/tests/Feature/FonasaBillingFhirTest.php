<?php

namespace Tests\Feature;

use App\Models\FonasaBono;
use App\Models\Insurance;
use App\Models\InsurancePlan;
use App\Models\ElectronicDocument;
use Tests\Concerns\InteractsWithRis;
use Tests\TestCase;

class FonasaBillingFhirTest extends TestCase
{
    use InteractsWithRis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRis();
        $this->loginRis();
    }

    public function test_fonasa_register_and_validate_simulated(): void
    {
        $appointment = $this->createTestAppointment();

        $insurance = Insurance::where('laboratory_id', $this->risLab->id)
            ->where('name', 'like', '%Fonasa%')
            ->first();

        if ($insurance) {
            $plan = InsurancePlan::where('insurance_id', $insurance->id)->first();
            $appointment->update([
                'insurance_id' => $insurance->id,
                'insurance_plan_id' => $plan?->id,
            ]);
        }

        $store = $this->withHeaders($this->authHeaders())
            ->postJson("/api/fonasa/appointments/{$appointment->id}/bono", [
                'folio' => 'BONO123456',
                'tipo' => 'Electronico',
                'rut_beneficiario' => '11111111-1',
            ]);

        $store->assertCreated()->assertJsonPath('success', true);
        $bonoId = $store->json('data.id');

        $validate = $this->withHeaders($this->authHeaders())
            ->postJson("/api/fonasa/appointments/{$appointment->id}/bono/{$bonoId}/validate");

        $validate->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('fonasa_bonos', ['id' => $bonoId, 'estado' => 'validado']);
    }

    public function test_dte_emit_simulated(): void
    {
        $appointment = $this->createTestAppointment();

        $response = $this->withHeaders($this->authHeaders())
            ->postJson("/api/billing/appointments/{$appointment->id}/dte", [
                'document_type' => 'boleta',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'emitido');

        $this->assertDatabaseHas('electronic_documents', [
            'appointment_id' => $appointment->id,
            'status' => 'emitido',
        ]);
    }

    public function test_fhir_patient_and_diagnostic_report(): void
    {
        $appointment = $this->createTestAppointment([
            'status' => 'entregable',
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/fhir/Patient/' . $appointment->patient_id)
            ->assertOk()
            ->assertJsonPath('resourceType', 'Patient');

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/fhir/DiagnosticReport?appointment=' . $appointment->id)
            ->assertOk()
            ->assertJsonPath('resourceType', 'DiagnosticReport');
    }

    public function test_consolidated_matrix_report(): void
    {
        $mes = now()->format('Y-m');

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/reports/consolidated-matrix?month={$mes}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['filas', 'totales', 'mes']]);
    }

    public function test_fhir_metadata_public(): void
    {
        $this->getJson('/api/fhir/metadata')
            ->assertOk()
            ->assertJsonPath('resourceType', 'CapabilityStatement');
    }
}
