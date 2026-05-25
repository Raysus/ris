<?php

namespace App\Observers;

use App\Models\InsurancePlan;
use App\Jobs\SyncEntityToCloud;

class InsurancePlanObserver
{
    public function created(InsurancePlan $insurancePlan)
    {
        SyncEntityToCloud::dispatch('InsurancePlan', 'created', $insurancePlan->toArray());
    }

    public function updated(InsurancePlan $insurancePlan)
    {
        SyncEntityToCloud::dispatch('InsurancePlan', 'updated', $insurancePlan->toArray());
    }

    public function deleted(InsurancePlan $insurancePlan)
    {
        SyncEntityToCloud::dispatch('InsurancePlan', 'deleted', ['id' => $insurancePlan->id]);
    }
}
