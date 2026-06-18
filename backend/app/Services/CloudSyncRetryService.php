<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CloudSyncLog;

class CloudSyncRetryService
{
    public function payloadForRetry(CloudSyncLog $log): ?array
    {
        $payload = $log->payload;
        if (!is_array($payload)) {
            return null;
        }

        $type = $log->entity_type ?? '';
        $appointmentId = $payload['id'] ?? $log->entity_id ?? null;

        if ($appointmentId && str_contains($type, 'Appointment')) {
            $appointment = Appointment::with(['patient.persona', 'studies', 'supplies'])->find($appointmentId);
            if ($appointment) {
                return $appointment->toArray();
            }
        }

        return $payload;
    }
}
