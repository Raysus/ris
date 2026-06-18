<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CloudSyncLog;
use App\Models\User;

class CloudSyncRetryService
{
    public function payloadForRetry(CloudSyncLog $log): ?array
    {
        $payload = $log->payload;
        if (!is_array($payload)) {
            return null;
        }

        $type = $log->entity_type ?? '';
        $entityId = $payload['id'] ?? $log->entity_id ?? null;

        if ($entityId && str_contains($type, 'Appointment')) {
            $appointment = Appointment::with(['patient.persona', 'studies', 'supplies'])->find($entityId);
            if ($appointment) {
                return $appointment->toArray();
            }
        }

        if ($entityId && str_contains($type, 'User')) {
            $user = User::with(['persona', 'tipoUsuario', 'laboratories'])->find($entityId);
            if ($user?->persona) {
                $userData = $user->makeVisible(['password'])->toArray();
                $userData['persona'] = $user->persona->toArray();

                return $userData;
            }
        }

        return $payload;
    }
}
