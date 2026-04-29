<?php

namespace App\Observers;

use App\Models\Persona;
use App\Jobs\SyncEntityToCloud;

class PersonaObserver
{
    public function created(Persona $persona)
    {
        SyncEntityToCloud::dispatch('Persona', $persona->toArray(), 'created');
    }

    public function updated(Persona $persona)
    {
        SyncEntityToCloud::dispatch('Persona', $persona->toArray(), 'updated');
    }

    public function deleted(Persona $persona)
    {
        SyncEntityToCloud::dispatch('Persona', ['id' => $persona->id], 'deleted');
    }
}