<?php

namespace App\Observers;

use App\Models\User;
use App\Jobs\SyncEntityToCloud;

class UserObserver
{
    public function created(User $user)
    {
        SyncEntityToCloud::dispatch('User', 'created', $user->toArray());
    }

    public function updated(User $user)
    {
        SyncEntityToCloud::dispatch('User', 'updated', $user->toArray());
    }

    public function deleted(User $user)
    {
        SyncEntityToCloud::dispatch('User', 'deleted', ['id' => $user->id]);
    }
}
