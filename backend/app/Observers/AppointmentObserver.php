<?php

namespace App\Observers;

use App\Jobs\RelayAppointmentToLocalLab;
use App\Jobs\SyncAppointmentBundleToCloud;
use App\Jobs\SyncEntityToCloud;
use App\Models\Appointment;
use App\Services\CloudEntitySyncService;
use App\Support\CloudSyncMode;
use App\Support\LaboratorySyncRelay;

class AppointmentObserver
{
    /** Evita jobs duplicados de Persona/Paciente durante el alta de una cita. */
    public static bool $suppressRelatedSync = false;

    public function created(Appointment $appointment): void
    {
        $this->dispatchSyncChain($appointment, 'created');
    }

    public function updated(Appointment $appointment): void
    {
        $this->dispatchSyncChain($appointment, 'updated');
    }

    public function deleted(Appointment $appointment): void
    {
        $this->dispatchSyncChain($appointment, 'deleted');
    }

    private function dispatchSyncChain(Appointment $appointment, string $action): void
    {
        if (CloudEntitySyncService::$applying) {
            return;
        }

        if (CloudSyncMode::isCloud()) {
            $appointment->loadMissing('laboratory');
            if (LaboratorySyncRelay::shouldRelayFromCloud($appointment->laboratory)) {
                $appointmentId = $appointment->id;
                \Illuminate\Support\Facades\DB::afterCommit(
                    fn () => RelayAppointmentToLocalLab::dispatchSync($appointmentId, $action)
                );
            }

            return;
        }

        if ($action === 'deleted') {
            SyncEntityToCloud::dispatch('App\Models\Appointment', 'deleted', ['id' => $appointment->id]);

            return;
        }

        if (CloudSyncMode::canPushToCloud()) {
            SyncAppointmentBundleToCloud::dispatch($appointment->id, $action)->afterCommit();
        }
    }
}
