<?php

namespace App\Observers;

use App\Models\AppointmentLog;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class AppointmentLogObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(AppointmentLog $log): void
    {
        $this->dispatchBidirectionalSync('AppointmentLog', 'created', $log);
    }
}
