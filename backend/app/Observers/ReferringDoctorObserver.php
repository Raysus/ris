<?php

namespace App\Observers;

use App\Models\ReferringDoctor;
use App\Jobs\SyncEntityToCloud;

class ReferringDoctorObserver
{
    public function created(ReferringDoctor $referringDoctor)
    {
        SyncEntityToCloud::dispatch('ReferringDoctor', $referringDoctor->toArray(), 'created');
    }

    public function updated(ReferringDoctor $referringDoctor)
    {
        SyncEntityToCloud::dispatch('ReferringDoctor', $referringDoctor->toArray(), 'updated');
    }

    public function deleted(ReferringDoctor $referringDoctor)
    {
        SyncEntityToCloud::dispatch('ReferringDoctor', ['id' => $referringDoctor->id], 'deleted');
    }
}