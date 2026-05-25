<?php

namespace App\Observers;

use App\Models\Service;
use App\Jobs\SyncEntityToCloud;

class ServiceObserver
{
    public function created(Service $service)
    {
        SyncEntityToCloud::dispatch('Service', 'created', $service->toArray());
    }

    public function updated(Service $service)
    {
        SyncEntityToCloud::dispatch('Service', 'updated', $service->toArray());
    }

    public function deleted(Service $service)
    {
        SyncEntityToCloud::dispatch('Service', 'deleted', ['id' => $service->id]);
    }
}
