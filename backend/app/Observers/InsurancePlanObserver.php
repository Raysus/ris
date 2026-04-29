<?php

namespace App\Observers;

use App\Models\InsurancePlan;
use App\Jobs\SyncEntityToCloud;

class InsurancePlanObserver
{
    public function created(InsurancePlan $insurancePlan)
    {
        SyncEntityToCloud::dispatch('InsurancePlan', $insurancePlan->toArray(), 'created');
    }

    public function updated(InsurancePlan $insurancePlan)
    {
        SyncEntityToCloud::dispatch('InsurancePlan', $insurancePlan->toArray(), 'updated');
    }

    public function deleted(InsurancePlan $insurancePlan)
    {
        SyncEntityToCloud::dispatch('InsurancePlan', ['id' => $insurancePlan->id], 'deleted');
    }
}