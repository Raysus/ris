<?php

namespace App\Observers;

use App\Jobs\RelayEntityToLocalLab;
use App\Jobs\SyncEntityToCloud;
use App\Models\SupportTicket;
use App\Services\CloudEntitySyncService;
use App\Services\SupportTicketNotificationService;
use App\Support\CloudSyncMode;
use Illuminate\Support\Facades\DB;

class SupportTicketObserver
{
    public function created(SupportTicket $ticket): void
    {
        $this->dispatchSync($ticket, 'created');
        $this->notifyIfLocalOrCloudCreate($ticket);
    }

    public function updated(SupportTicket $ticket): void
    {
        $this->dispatchSync($ticket, 'updated');
    }

    public function deleted(SupportTicket $ticket): void
    {
        $this->dispatchSync($ticket, 'deleted');
    }

    private function dispatchSync(SupportTicket $ticket, string $action): void
    {
        if (CloudEntitySyncService::$applying) {
            return;
        }

        $payload = $action === 'deleted'
            ? ['id' => $ticket->id]
            : $ticket->toArray();

        if (CloudSyncMode::isCloud()) {
            DB::afterCommit(
                fn () => RelayEntityToLocalLab::dispatch('SupportTicket', $action, $ticket->id, $payload)
            );

            return;
        }

        if (CloudSyncMode::canPushToCloud()) {
            DB::afterCommit(
                fn () => SyncEntityToCloud::dispatch('SupportTicket', $action, $payload)
            );
        }
    }

    private function notifyIfLocalOrCloudCreate(SupportTicket $ticket): void
    {
        if (CloudEntitySyncService::$applying) {
            return;
        }

        app(SupportTicketNotificationService::class)->notifyStaffOfNewTicket($ticket);
    }
}
