<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Jobs\SyncEntityToCloud;

class AppointmentObserver
{
    public function created(Appointment $appointment)
    {
        // Cargamos las relaciones para que viajen a la nube en el mismo paquete
        $data = $appointment->load(['patient.persona', 'studies', 'supplies'])->toArray();
        SyncEntityToCloud::dispatch('Appointment', 'created', $data);
    }

    public function updated(Appointment $appointment)
    {
        $data = $appointment->load(['patient.persona', 'studies', 'supplies'])->toArray();
        SyncEntityToCloud::dispatch('Appointment', 'updated', $data);
    }

    public function deleted(Appointment $appointment)
    {
        SyncEntityToCloud::dispatch('Appointment', 'deleted', ['id' => $appointment->id]);
    }
}
