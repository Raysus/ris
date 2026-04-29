<?php

namespace App\Observers;

use App\Models\AppointmentLog;
use App\Jobs\SyncEntityToCloud;

class AppointmentLogObserver
{
    public function created(AppointmentLog $log)
    {
        // Enviamos solo este log específico a la nube
        SyncEntityToCloud::dispatch('AppointmentLog', $log->toArray(), 'created');
    }
}