<?php

namespace App\Observers;

use App\Models\ReferringDoctor;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class ReferringDoctorObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(ReferringDoctor $doctor): void
    {
        $this->dispatchBidirectionalSync('ReferringDoctor', 'created', $doctor);
    }

    public function updated(ReferringDoctor $doctor): void
    {
        $this->dispatchBidirectionalSync('ReferringDoctor', 'updated', $doctor);
    }

    public function deleted(ReferringDoctor $doctor): void
    {
        $this->dispatchBidirectionalSync('ReferringDoctor', 'deleted', $doctor);
    }
}
