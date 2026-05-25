<?php

namespace App\Observers;

use App\Models\ReferringDoctor;
use App\Jobs\SyncEntityToCloud;

class ReferringDoctorObserver
{
    public function created(ReferringDoctor $referringDoctor)
    {
        SyncEntityToCloud::dispatch('ReferringDoctor', 'created', $referringDoctor->toArray());
    }

    public function updated(ReferringDoctor $referringDoctor)
    {
        SyncEntityToCloud::dispatch('ReferringDoctor', 'updated', $referringDoctor->toArray());
    }

    public function deleted(ReferringDoctor $referringDoctor)
    {
        SyncEntityToCloud::dispatch('ReferringDoctor', 'deleted', ['id' => $referringDoctor->id]);
    }
}
