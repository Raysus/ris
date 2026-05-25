<?php

namespace App\Observers;

use App\Models\Insurance;
use App\Jobs\SyncEntityToCloud;

class InsuranceObserver
{
    public function created(Insurance $insurance)
    {
        SyncEntityToCloud::dispatch('Insurance', 'created', $insurance->toArray());
    }

    public function updated(Insurance $insurance)
    {
        SyncEntityToCloud::dispatch('Insurance', 'updated', $insurance->toArray());
    }

    public function deleted(Insurance $insurance)
    {
        SyncEntityToCloud::dispatch('Insurance', 'deleted', ['id' => $insurance->id]);
    }
}
