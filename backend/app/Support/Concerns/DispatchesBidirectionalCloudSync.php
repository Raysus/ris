<?php

namespace App\Support\Concerns;

use App\Jobs\RelayEntityToLocalLab;
use App\Jobs\SyncEntityToCloud;
use App\Services\CloudEntitySyncService;
use App\Support\CloudSyncMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Local → nube (SyncEntityToCloud) y nube → lab (RelayEntityToLocalLab), sin bucles.
 */
trait DispatchesBidirectionalCloudSync
{
    protected function dispatchBidirectionalSync(
        string $entityType,
        string $action,
        Model $model,
        ?array $payload = null,
    ): void {
        if (CloudEntitySyncService::$applying) {
            return;
        }

        $payload ??= $action === 'deleted'
            ? ['id' => (string) $model->getKey()]
            : $model->toArray();

        $entityId = (string) ($payload['id'] ?? $model->getKey());

        if (CloudSyncMode::isCloud()) {
            DB::afterCommit(
                fn () => RelayEntityToLocalLab::dispatch($entityType, $action, $entityId, $payload)
            );

            return;
        }

        if (CloudSyncMode::canPushToCloud()) {
            DB::afterCommit(
                fn () => SyncEntityToCloud::dispatch($entityType, $action, $payload)
            );
        }
    }
}
