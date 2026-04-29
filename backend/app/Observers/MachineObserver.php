<?php

namespace App\Observers;

use App\Models\Machine;
use App\Jobs\SyncEntityToCloud;

class MachineObserver
{
    public function created(Machine $machine)
    {
        SyncEntityToCloud::dispatch('Machine', $machine->toArray(), 'created');
    }

    public function updated(Machine $machine)
    {
        SyncEntityToCloud::dispatch('Machine', $machine->toArray(), 'updated');
    }

    public function deleted(Machine $machine)
    {
        SyncEntityToCloud::dispatch('Machine', ['id' => $machine->id], 'deleted');
    }
}