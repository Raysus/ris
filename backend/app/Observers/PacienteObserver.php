<?php

namespace App\Observers;

use App\Models\Paciente;
use App\Jobs\SyncEntityToCloud;

class PacienteObserver
{
    public function created(Paciente $paciente)
    {
        SyncEntityToCloud::dispatch('Paciente', $paciente->toArray(), 'created');
    }

    public function updated(Paciente $paciente)
    {
        SyncEntityToCloud::dispatch('Paciente', $paciente->toArray(), 'updated');
    }

    public function deleted(Paciente $paciente)
    {
        SyncEntityToCloud::dispatch('Paciente', ['id' => $paciente->id], 'deleted');
    }
}