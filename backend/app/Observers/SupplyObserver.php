<?php

namespace App\Observers;

use App\Models\Supply;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class SupplyObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(Supply $supply): void
    {
        $this->dispatchBidirectionalSync('Supply', 'created', $supply);
    }

    public function updated(Supply $supply): void
    {
        $this->dispatchBidirectionalSync('Supply', 'updated', $supply);
    }

    public function deleted(Supply $supply): void
    {
        $this->dispatchBidirectionalSync('Supply', 'deleted', $supply);
    }
}
