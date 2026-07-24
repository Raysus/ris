<?php

namespace App\Observers;

use App\Models\InsurancePlan;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class InsurancePlanObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(InsurancePlan $plan): void
    {
        $this->dispatchBidirectionalSync('InsurancePlan', 'created', $plan);
    }

    public function updated(InsurancePlan $plan): void
    {
        $this->dispatchBidirectionalSync('InsurancePlan', 'updated', $plan);
    }

    public function deleted(InsurancePlan $plan): void
    {
        $this->dispatchBidirectionalSync('InsurancePlan', 'deleted', $plan);
    }
}
