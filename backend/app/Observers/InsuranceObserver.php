<?php

namespace App\Observers;

use App\Models\Insurance;
use App\Jobs\SyncEntityToCloud;

class InsuranceObserver
{
    public function created(Insurance $insurance)
    {
        SyncEntityToCloud::dispatch('Insurance', $insurance->toArray(), 'created');
    }

    public function updated(Insurance $insurance)
    {
        SyncEntityToCloud::dispatch('Insurance', $insurance->toArray(), 'updated');
    }

    public function deleted(Insurance $insurance)
    {
        SyncEntityToCloud::dispatch('Insurance', ['id' => $insurance->id], 'deleted');
    }
}