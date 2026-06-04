<?php

namespace App\Observers;

use App\Jobs\SyncEntityToCloud;
use App\Models\Paciente;

class PacienteObserver
{
    public function created(Paciente $paciente)
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }
        SyncEntityToCloud::dispatch('Paciente', 'created', $paciente->toArray());
    }

    public function updated(Paciente $paciente)
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }
        SyncEntityToCloud::dispatch('Paciente', 'updated', $paciente->toArray());
    }

    public function deleted(Paciente $paciente)
    {
        SyncEntityToCloud::dispatch('Paciente', 'deleted', ['id' => $paciente->id]);
    }
}
