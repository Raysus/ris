<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Persona;
use Illuminate\Support\Collection;

class PatientPortalReportService
{
    /** @return Collection<int, Appointment> */
    public function appointmentsForRut(string $rut, ?string $labId = null): Collection
    {
        $persona = Persona::findByRut($rut);
        if (!$persona) {
            return collect();
        }

        $patientIds = $persona->patients()->pluck('id');
        if ($patientIds->isEmpty()) {
            return collect();
        }

        $query = Appointment::query()
            ->with([
                'patient.persona',
                'laboratory',
                'studies',
                'destinationDoctor.persona',
            ])
            ->whereIn('patient_id', $patientIds)
            ->whereIn('status', ['entregable', 'entregado'])
            ->orderByDesc('start_time');

        if ($labId) {
            $query->where('laboratory_id', $labId);
        }

        return $query->get()->filter(function (Appointment $appointment) {
            return $appointment->studies->contains(function ($study) {
                return trim($study->getStoredReportText()) !== '';
            });
        })->values();
    }

    public function appointmentForRut(string $appointmentId, string $rut): ?Appointment
    {
        return $this->appointmentsForRut($rut)
            ->first(fn (Appointment $appointment) => (string) $appointment->id === (string) $appointmentId);
    }

    public function formatAppointment(Appointment $appointment): array
    {
        $persona = $appointment->patient?->persona;

        $destDoctorName = 'Médico radiólogo';
        $firmaUrl = null;
        $docPersona = $appointment->destinationDoctor?->persona;
        if ($docPersona) {
            $destDoctorName = trim("{$docPersona->names} {$docPersona->last_name_1}");
            if ($docPersona->signature_path) {
                $firmaUrl = asset('storage/' . $docPersona->signature_path);
            }
        }

        $studies = $appointment->studies
            ->map(function ($study) {
                $reportText = trim($study->getStoredReportText());

                return [
                    'study_id' => $study->id,
                    'exam' => $study->exam_name,
                    'fonasa_code' => $study->fonasa_code,
                    'quantity' => (int) ($study->quantity ?? 1),
                    'report_text' => $reportText,
                    'has_report' => $reportText !== '',
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $appointment->id,
            'accession_number' => $appointment->accession_number ?? ('ACC-' . $appointment->id),
            'status' => $appointment->status,
            'study_date' => $appointment->start_time?->format('Y-m-d'),
            'study_datetime' => $appointment->start_time?->toIso8601String(),
            'signed_at' => $appointment->updated_at?->format('d/m/Y H:i'),
            'doctor_name' => $destDoctorName,
            'signature_url' => $firmaUrl,
            'laboratory' => [
                'id' => $appointment->laboratory_id,
                'name' => $appointment->laboratory?->name,
            ],
            'patient' => [
                'rut' => $persona?->rut,
                'name' => $persona?->names,
                'last_name' => $persona?->last_name_1,
            ],
            'studies' => $studies,
        ];
    }
}
