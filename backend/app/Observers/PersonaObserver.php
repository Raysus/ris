<?php

namespace App\Observers;

use App\Models\Persona;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class PersonaObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(Persona $persona): void
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }
        $this->dispatchBidirectionalSync('Persona', 'created', $persona);
    }

    public function updated(Persona $persona): void
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }
        $this->dispatchBidirectionalSync('Persona', 'updated', $persona);
    }

    public function deleted(Persona $persona): void
    {
        $this->dispatchBidirectionalSync('Persona', 'deleted', $persona);
    }
}
