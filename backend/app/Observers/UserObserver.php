<?php

namespace App\Observers;

use App\Jobs\RelayUserBundleToLocalLab;
use App\Jobs\SyncUserBundleToCloud;
use App\Models\User;
use App\Services\CloudEntitySyncService;
use App\Support\CloudSyncMode;
use Illuminate\Support\Facades\DB;

class UserObserver
{
    public function created(User $user): void
    {
        $this->dispatchUserSync($user, 'created');
    }

    public function updated(User $user): void
    {
        $this->dispatchUserSync($user, 'updated');
    }

    public function deleted(User $user): void
    {
        $this->dispatchUserSync($user, 'deleted');
    }

    private function dispatchUserSync(User $user, string $action): void
    {
        if (CloudEntitySyncService::$applying) {
            return;
        }

        if (AppointmentObserver::$suppressRelatedSync) {
            return;
        }

        if (CloudSyncMode::isCloud()) {
            DB::afterCommit(
                fn () => RelayUserBundleToLocalLab::dispatch($user->id, $action)
            );

            return;
        }

        if (CloudSyncMode::canPushToCloud()) {
            DB::afterCommit(
                fn () => SyncUserBundleToCloud::dispatch($user->id, $action)
            );
        }
    }
}
