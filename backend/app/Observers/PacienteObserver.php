<?php

namespace App\Observers;

use App\Models\Paciente;
use App\Jobs\SyncEntityToCloud;

class PacienteObserver
{
    public function created(Paciente $paciente)
    {
        SyncEntityToCloud::dispatch('Paciente', 'created', $paciente->toArray());
    }

    public function updated(Paciente $paciente)
    {
        SyncEntityToCloud::dispatch('Paciente', 'updated', $paciente->toArray());
    }

    public function deleted(Paciente $paciente)
    {
        SyncEntityToCloud::dispatch('Paciente', 'deleted', ['id' => $paciente->id]);
    }
}
