<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Paciente;
use App\Services\FhirResourceBuilder;
use App\Services\Hl7IntegrationService;
use Illuminate\Http\Request;

class FhirController extends Controller
{
    public function metadata(Request $request)
    {
        if ($denied = $this->assertFhirInboundAccess($request)) {
            return $denied;
        }

        return response()->json([
            'resourceType' => 'CapabilityStatement',
            'status' => 'active',
            'fhirVersion' => config('fhir.version'),
            'format' => ['json'],
            'rest' => [
                [
                    'mode' => 'server',
                    'resource' => [
                        ['type' => 'Patient', 'interaction' => [['code' => 'read']]],
                        ['type' => 'ServiceRequest', 'interaction' => [['code' => 'read'], ['code' => 'create']]],
                        ['type' => 'DiagnosticReport', 'interaction' => [['code' => 'read']]],
                    ],
                ],
            ],
        ]);
    }

    public function patient(string $id, FhirResourceBuilder $builder)
    {
        $patient = $this->securePatient($id);

        return response()->json($builder->patient($patient));
    }

    public function serviceRequest(Request $request, FhirResourceBuilder $builder)
    {
        if ($request->isMethod('post')) {
            return $this->createServiceRequest($request);
        }

        $appointmentId = $request->query('appointment') ?? $request->query('appointment_id');
        if (!$appointmentId) {
            return response()->json(['resourceType' => 'OperationOutcome', 'issue' => [
                ['severity' => 'error', 'diagnostics' => 'Query appointment requerido.'],
            ]], 400);
        }

        $appointment = $this->secureAppointment($appointmentId);

        return response()->json($builder->serviceRequest($appointment));
    }

    public function diagnosticReport(Request $request, FhirResourceBuilder $builder)
    {
        $appointmentId = $request->query('appointment') ?? $request->query('appointment_id');
        if (!$appointmentId) {
            return response()->json(['resourceType' => 'OperationOutcome', 'issue' => [
                ['severity' => 'error', 'diagnostics' => 'Query appointment requerido.'],
            ]], 400);
        }

        $appointment = $this->secureAppointment($appointmentId);

        return response()->json($builder->diagnosticReport($appointment));
    }

    public function appointmentBundle(string $appointmentId, FhirResourceBuilder $builder)
    {
        $appointment = $this->secureAppointment($appointmentId);
        $appointment->loadMissing('patient.persona');

        $entries = [
            $builder->patient($appointment->patient),
            $builder->serviceRequest($appointment),
        ];

        if (in_array($appointment->status, ['entregable', 'entregado'], true)) {
            $entries[] = $builder->diagnosticReport($appointment);
        }

        return response()->json($builder->bundle('collection', $entries));
    }

    protected function createServiceRequest(Request $request)
    {
        if ($denied = $this->assertFhirInboundAccess($request)) {
            return $denied;
        }

        $resource = $request->all();
        if (($resource['resourceType'] ?? '') !== 'ServiceRequest') {
            return response()->json(['resourceType' => 'OperationOutcome', 'issue' => [
                ['severity' => 'error', 'diagnostics' => 'Se esperaba ServiceRequest.'],
            ]], 422);
        }

        $labId = $request->header('X-Lab-Id') ?: config('app.current_lab_id');
        if (!$labId) {
            return response()->json(['resourceType' => 'OperationOutcome', 'issue' => [
                ['severity' => 'error', 'diagnostics' => 'Falta X-Lab-Id.'],
            ]], 400);
        }

        $patientRef = $resource['subject']['reference'] ?? '';
        $patientId = str_replace('Patient/', '', $patientRef);
        $patient = Paciente::where('laboratory_id', $labId)->find($patientId);

        if (!$patient) {
            return response()->json(['resourceType' => 'OperationOutcome', 'issue' => [
                ['severity' => 'error', 'diagnostics' => 'Paciente no encontrado.'],
            ]], 404);
        }

        $code = $resource['code']['coding'][0]['code'] ?? null;
        $display = $resource['code']['coding'][0]['display'] ?? 'Orden FHIR';
        $accession = $resource['identifier'][0]['value'] ?? ('FHIR-' . uniqid());

        $rawHl7 = implode("\r", [
            'MSH|^~\\&|FHIR|HIS|RIS|LAB|' . now()->format('YmdHis') . '||ORM^O01|' . $accession . '|P|2.5',
            'PID|1||' . ($patient->persona->rut ?? '') . '^^^RUT||' . ($patient->persona->last_name_1 ?? '') . '^' . ($patient->persona->names ?? ''),
            'ORC|NW|' . $accession . '|||||||' . now()->addHour()->format('YmdHis'),
            'OBR|1|' . $accession . '||' . ($code ?? '') . '^' . $display,
        ]);

        $result = app(Hl7IntegrationService::class)->processInbound($rawHl7, $labId);

        return response()->json([
            'resourceType' => 'ServiceRequest',
            'id' => $result['appointment_id'] ?? null,
            'status' => 'active',
            'meta' => ['source' => 'HealthTiCloud-RIS'],
        ], 201);
    }

    protected function securePatient(string $id): Paciente
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Paciente::with('persona');

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            $query->whereIn('laboratory_id', $allowedLabs ?: ['00000000-0000-0000-0000-000000000000']);
        }

        return $query->findOrFail($id);
    }

    protected function secureAppointment(string $id): Appointment
    {
        $allowedLabs = config('app.allowed_lab_ids');
        $query = Appointment::query();

        if (!\App\Models\Laboratory::allowsAllLabs($allowedLabs)) {
            $query->whereIn('laboratory_id', $allowedLabs ?: ['00000000-0000-0000-0000-000000000000']);
        }

        return $query->findOrFail($id);
    }

    protected function assertFhirInboundAccess(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (!config('fhir.enabled')) {
            return response()->json(['resourceType' => 'OperationOutcome', 'issue' => [
                ['severity' => 'error', 'diagnostics' => 'FHIR deshabilitado.'],
            ]], 503);
        }

        $secret = config('fhir.inbound_secret');
        if (!is_string($secret) || $secret === '') {
            return response()->json(['resourceType' => 'OperationOutcome', 'issue' => [
                ['severity' => 'error', 'diagnostics' => 'FHIR habilitado sin secreto de entrada configurado.'],
            ]], 503);
        }

        $provided = (string) $request->header('X-FHIR-Secret', '');
        if (!hash_equals($secret, $provided)) {
            return response()->json(['resourceType' => 'OperationOutcome', 'issue' => [
                ['severity' => 'error', 'diagnostics' => 'No autorizado.'],
            ]], 401);
        }

        return null;
    }
}
