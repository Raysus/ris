<?php

namespace App\Observers;

use App\Jobs\SyncAppointmentBundleToCloud;
use App\Jobs\SyncEntityToCloud;
use App\Models\Appointment;

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
        SyncEntityToCloud::dispatch('App\Models\Appointment', 'deleted', ['id' => $appointment->id]);
    }

    private function dispatchSyncChain(Appointment $appointment, string $action): void
    {
        if ($action === 'deleted') {
            SyncEntityToCloud::dispatch('App\Models\Appointment', 'deleted', ['id' => $appointment->id]);
            return;
        }

        SyncAppointmentBundleToCloud::dispatch($appointment->id, $action)->afterCommit();
    }
}
