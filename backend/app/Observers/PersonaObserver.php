<?php

namespace App\Observers;

use App\Jobs\SyncEntityToCloud;
use App\Models\Persona;

class PersonaObserver
{
    public function created(Persona $persona)
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }
        SyncEntityToCloud::dispatch('Persona', 'created', $persona->toArray());
    }

    public function updated(Persona $persona)
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }
        SyncEntityToCloud::dispatch('Persona', 'updated', $persona->toArray());
    }

    public function deleted(Persona $persona)
    {
        SyncEntityToCloud::dispatch('Persona', 'deleted', ['id' => $persona->id]);
    }
}
