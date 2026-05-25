<?php

namespace App\Observers;

use App\Models\Laboratory;
use App\Jobs\SyncEntityToCloud;

class LaboratoryObserver
{
    public function created(Laboratory $laboratory)
    {
        SyncEntityToCloud::dispatch('Laboratory', 'created', $laboratory->toArray());
    }

    public function updated(Laboratory $laboratory)
    {
        SyncEntityToCloud::dispatch('Laboratory', 'updated', $laboratory->toArray());
    }

    public function deleted(Laboratory $laboratory)
    {
        SyncEntityToCloud::dispatch('Laboratory', 'deleted', ['id' => $laboratory->id]);
    }
}
