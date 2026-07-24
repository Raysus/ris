<?php

namespace App\Observers;

use App\Models\Insurance;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class InsuranceObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(Insurance $insurance): void
    {
        $this->dispatchBidirectionalSync('Insurance', 'created', $insurance);
    }

    public function updated(Insurance $insurance): void
    {
        $this->dispatchBidirectionalSync('Insurance', 'updated', $insurance);
    }

    public function deleted(Insurance $insurance): void
    {
        $this->dispatchBidirectionalSync('Insurance', 'deleted', $insurance);
    }
}
