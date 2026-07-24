<?php

namespace App\Observers;

use App\Models\Laboratory;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class LaboratoryObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(Laboratory $laboratory): void
    {
        $this->dispatchBidirectionalSync('Laboratory', 'created', $laboratory);
    }

    public function updated(Laboratory $laboratory): void
    {
        $this->dispatchBidirectionalSync('Laboratory', 'updated', $laboratory);
    }

    public function deleted(Laboratory $laboratory): void
    {
        $this->dispatchBidirectionalSync('Laboratory', 'deleted', $laboratory);
    }
}
