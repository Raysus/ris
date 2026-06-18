<?php

namespace App\Observers;

use App\Models\User;
use App\Jobs\SyncEntityToCloud;

class UserObserver
{
    public function created(User $user)
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }

        SyncEntityToCloud::dispatch('User', 'created', $user->toArray());
    }

    public function updated(User $user)
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }

        SyncEntityToCloud::dispatch('User', 'updated', $user->toArray());
    }

    public function deleted(User $user)
    {
        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }

        SyncEntityToCloud::dispatch('User', 'deleted', ['id' => $user->id]);
    }
}
