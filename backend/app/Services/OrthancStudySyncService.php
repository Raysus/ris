<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrthancStudySyncService
{
    public function syncPendingAppointments(): int
    {
        $orthancBase = \App\Support\OrthancUrl::base();
        $updated = 0;

        $appointments = Appointment::query()
            ->where('status', 'dicom_enviado')
            ->whereNotNull('accession_number')
            ->whereNull('images_received_at')
            ->get();

        foreach ($appointments as $appointment) {
            if (!$this->hasInstancesForAccession($orthancBase, $appointment->accession_number)) {
                continue;
            }

            $appointment->status = 'en_atencion';
            $appointment->images_received_at = now();
            $appointment->save();

            AppointmentLog::create([
                'appointment_id' => $appointment->id,
                'user_id' => null,
                'action' => 'imagenes_recibidas_pacs',
                'details' => ['accession' => $appointment->accession_number],
                'ip_address' => null,
            ]);

            \App\Jobs\SyncEntityToCloud::dispatch(
                'App\Models\Appointment',
                'updated',
                $appointment->fresh(['patient.persona', 'studies'])->toArray()
            );

            $updated++;
        }

        return $updated;
    }

    public function hasInstancesForAccession(string $orthancBase, string $accessionNumber): bool
    {
        try {
            $response = Http::timeout(8)->post("{$orthancBase}/tools/find", [
                'Level' => 'Study',
                'Query' => [
                    'AccessionNumber' => $accessionNumber,
                ],
            ]);

            if (!$response->successful()) {
                return false;
            }

            $studyIds = $response->json();
            if (!is_array($studyIds) || count($studyIds) === 0) {
                return false;
            }

            foreach ($studyIds as $studyId) {
                $instances = Http::timeout(5)->get("{$orthancBase}/studies/{$studyId}/instances");
                if ($instances->successful() && count($instances->json() ?? []) > 0) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning('Orthanc sync: no se pudo consultar PACS', [
                'accession' => $accessionNumber,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
