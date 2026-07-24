<?php

namespace App\Observers;

use App\Models\Paciente;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class PacienteObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(Paciente $paciente): void
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }
        $this->dispatchBidirectionalSync('Paciente', 'created', $paciente);
    }

    public function updated(Paciente $paciente): void
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }
        $this->dispatchBidirectionalSync('Paciente', 'updated', $paciente);
    }

    public function deleted(Paciente $paciente): void
    {
        $this->dispatchBidirectionalSync('Paciente', 'deleted', $paciente);
    }
}
