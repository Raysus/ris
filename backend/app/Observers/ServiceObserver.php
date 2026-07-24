<?php

namespace App\Observers;

use App\Models\Service;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class ServiceObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(Service $service): void
    {
        $this->dispatchBidirectionalSync('Service', 'created', $service);
    }

    public function updated(Service $service): void
    {
        $this->dispatchBidirectionalSync('Service', 'updated', $service);
    }

    public function deleted(Service $service): void
    {
        $this->dispatchBidirectionalSync('Service', 'deleted', $service);
    }
}
