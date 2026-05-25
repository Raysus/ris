<?php

namespace App\Observers;

use App\Models\Supply;
use App\Jobs\SyncEntityToCloud;

class SupplyObserver
{
    public function created(Supply $supply)
    {
        SyncEntityToCloud::dispatch('Supply', 'created', $supply->toArray());
    }

    public function updated(Supply $supply)
    {
        SyncEntityToCloud::dispatch('Supply', 'updated', $supply->toArray());
    }

    public function deleted(Supply $supply)
    {
        SyncEntityToCloud::dispatch('Supply', 'deleted', ['id' => $supply->id]);
    }
}
