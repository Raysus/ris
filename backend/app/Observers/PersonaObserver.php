<?php

namespace App\Observers;

use App\Models\Persona;
use App\Jobs\SyncEntityToCloud;

class PersonaObserver
{
    public function created(Persona $persona)
    {
        SyncEntityToCloud::dispatch('Persona', 'created', $persona->toArray());
    }

    public function updated(Persona $persona)
    {
        SyncEntityToCloud::dispatch('Persona', 'updated', $persona->toArray());
    }

    public function deleted(Persona $persona)
    {
        SyncEntityToCloud::dispatch('Persona', 'deleted', ['id' => $persona->id]);
    }
}
