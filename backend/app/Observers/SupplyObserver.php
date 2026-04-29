<?php

namespace App\Observers;

use App\Models\Supply;
use App\Jobs\SyncEntityToCloud;

class SupplyObserver
{
    public function created(Supply $supply)
    {
        SyncEntityToCloud::dispatch('Supply', $supply->toArray(), 'created');
    }

    public function updated(Supply $supply)
    {
        SyncEntityToCloud::dispatch('Supply', $supply->toArray(), 'updated');
    }

    public function deleted(Supply $supply)
    {
        SyncEntityToCloud::dispatch('Supply', ['id' => $supply->id], 'deleted');
    }
}