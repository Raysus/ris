<?php

namespace App\Observers;

use App\Models\Service;
use App\Jobs\SyncEntityToCloud;

class ServiceObserver
{
    public function created(Service $service)
    {
        SyncEntityToCloud::dispatch('Service', $service->toArray(), 'created');
    }

    public function updated(Service $service)
    {
        SyncEntityToCloud::dispatch('Service', $service->toArray(), 'updated');
    }

    public function deleted(Service $service)
    {
        SyncEntityToCloud::dispatch('Service', ['id' => $service->id], 'deleted');
    }
}