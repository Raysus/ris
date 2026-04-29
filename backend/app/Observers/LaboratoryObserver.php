<?php

namespace App\Observers;

use App\Models\Laboratory;
use App\Jobs\SyncEntityToCloud;

class LaboratoryObserver
{
    public function created(Laboratory $laboratory)
    {
        SyncEntityToCloud::dispatch('Laboratory', $laboratory->toArray(), 'created');
    }

    public function updated(Laboratory $laboratory)
    {
        SyncEntityToCloud::dispatch('Laboratory', $laboratory->toArray(), 'updated');
    }

    public function deleted(Laboratory $laboratory)
    {
        SyncEntityToCloud::dispatch('Laboratory', ['id' => $laboratory->id], 'deleted');
    }
}