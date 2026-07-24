<?php

namespace App\Observers;

use App\Models\Machine;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class MachineObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(Machine $machine): void
    {
        $this->dispatchBidirectionalSync('Machine', 'created', $machine);
    }

    public function updated(Machine $machine): void
    {
        $this->dispatchBidirectionalSync('Machine', 'updated', $machine);
    }

    public function deleted(Machine $machine): void
    {
        $this->dispatchBidirectionalSync('Machine', 'deleted', $machine);
    }
}
