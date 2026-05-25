<?php

namespace App\Observers;

use App\Models\Machine;
use App\Jobs\SyncEntityToCloud;

class MachineObserver
{
    public function created(Machine $machine)
    {
        SyncEntityToCloud::dispatch('Machine', 'created', $machine->toArray());
    }

    public function updated(Machine $machine)
    {
        SyncEntityToCloud::dispatch('Machine', 'updated', $machine->toArray());
    }

    public function deleted(Machine $machine)
    {
        SyncEntityToCloud::dispatch('Machine', 'deleted', ['id' => $machine->id]);
    }
}
