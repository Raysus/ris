<?php

namespace App\Observers;

use App\Models\User;
use App\Jobs\SyncEntityToCloud;

class UserObserver
{
    public function created(User $user)
    {
        SyncEntityToCloud::dispatch('User', $user->toArray(), 'created');
    }

    public function updated(User $user)
    {
        SyncEntityToCloud::dispatch('User', $user->toArray(), 'updated');
    }

    public function deleted(User $user)
    {
        SyncEntityToCloud::dispatch('User', ['id' => $user->id], 'deleted');
    }
}