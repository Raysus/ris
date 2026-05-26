<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Paciente;
use Carbon\Carbon;

class FhirResourceBuilder
{
    public function patient(Paciente $patient): array
    {
        $patient->loadMissing('persona');
        $p = $patient->persona;

        return [
            'resourceType' => 'Patient',
            'id' => $patient->id,
            'identifier' => [
                [
                    'system' => 'http://registrocivil.cl/rut',
                    'value' => $p?->rut,
                ],
            ],
            'name' => [
                [
                    'family' => trim(($p?->last_name_1 ?? '') . ' ' . ($p?->last_name_2 ?? '')),
                    'given' => array_filter([$p?->names]),
                ],
            ],
            'telecom' => array_filter([
                $p?->email ? ['system' => 'email', 'value' => $p->email] : null,
                $p?->phone ? ['system' => 'phone', 'value' => $p->phone] : null,
            ]),
            'gender' => $this->mapGender($p?->gender),
            'birthDate' => $p?->birth_date?->format('Y-m-d'),
        ];
    }

    public function serviceRequest(Appointment $appointment): array
    {
        $appointment->loadMissing(['patient.persona', 'studies.exam', 'referringDoctor']);
        $study = $appointment->studies->first();

        return [
            'resourceType' => 'ServiceRequest',
            'id' => $appointment->id,
            'status' => $this->mapAppointmentStatus($appointment->status),
            'intent' => 'order',
            'priority' => strtolower($appointment->priority ?? 'routine'),
            'subject' => ['reference' => 'Patient/' . $appointment->patient_id],
            'authoredOn' => $appointment->created_at?->toIso8601String(),
            'occurrenceDateTime' => $appointment->start_time?->toIso8601String(),
            'code' => [
                'coding' => [
                    [
                        'system' => 'http://fonasa.cl/prestaciones',
                        'code' => $study?->fonasa_code ?? $study?->exam?->fonasa_code,
                        'display' => $study?->exam_name ?? $study?->exam?->name,
                    ],
                ],
            ],
            'identifier' => [
                [
                    'system' => 'http://healthticloud.cl/accession',
                    'value' => $appointment->accession_number ?? $appointment->id,
                ],
            ],
        ];
    }

    public function diagnosticReport(Appointment $appointment): array
    {
        $appointment->loadMissing(['patient.persona', 'studies']);
        $study = $appointment->studies->first();

        return [
            'resourceType' => 'DiagnosticReport',
            'id' => $appointment->id . '-report',
            'status' => in_array($appointment->status, ['entregable', 'entregado'], true) ? 'final' : 'partial',
            'code' => [
                'coding' => [
                    [
                        'code' => $study?->fonasa_code,
                        'display' => $study?->exam_name,
                    ],
                ],
            ],
            'subject' => ['reference' => 'Patient/' . $appointment->patient_id],
            'effectiveDateTime' => $appointment->updated_at?->toIso8601String(),
            'issued' => Carbon::now()->toIso8601String(),
            'conclusion' => $study?->report ?? '',
            'presentedForm' => [
                [
                    'contentType' => 'text/plain',
                    'data' => base64_encode($study?->report ?? ''),
                ],
            ],
        ];
    }

    public function bundle(string $type, array $entries): array
    {
        return [
            'resourceType' => 'Bundle',
            'type' => $type,
            'timestamp' => Carbon::now()->toIso8601String(),
            'entry' => array_map(fn ($resource) => [
                'fullUrl' => config('fhir.base_url') . '/' . $resource['resourceType'] . '/' . ($resource['id'] ?? ''),
                'resource' => $resource,
            ], $entries),
        ];
    }

    protected function mapGender(?string $gender): ?string
    {
        return match (strtolower((string) $gender)) {
            'masculino', 'm' => 'male',
            'femenino', 'f' => 'female',
            default => 'unknown',
        };
    }

    protected function mapAppointmentStatus(string $status): string
    {
        return match ($status) {
            'agendado', 'confirmado' => 'active',
            'anulado', 'cancelado' => 'revoked',
            'entregable', 'entregado' => 'completed',
            default => 'active',
        };
    }
}
