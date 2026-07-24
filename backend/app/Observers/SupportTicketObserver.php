<?php

namespace App\Observers;

use App\Jobs\RelaySupportTicketToLocalLab;
use App\Jobs\SyncEntityToCloud;
use App\Models\SupportTicket;
use App\Services\CloudEntitySyncService;
use App\Services\SupportTicketNotificationService;
use App\Support\CloudSyncMode;
use App\Support\LaboratorySyncRelay;
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

        if (CloudSyncMode::isCloud()) {
            $ticket->loadMissing('laboratory');
            if (LaboratorySyncRelay::shouldRelayEntityFromCloud($ticket->laboratory)) {
                $ticketId = $ticket->id;
                DB::afterCommit(
                    fn () => RelaySupportTicketToLocalLab::dispatch($ticketId, $action)
                );
            }

            return;
        }

        if (!CloudSyncMode::canPushToCloud()) {
            return;
        }

        $payload = $action === 'deleted'
            ? ['id' => $ticket->id]
            : $ticket->toArray();

        DB::afterCommit(
            fn () => SyncEntityToCloud::dispatch('SupportTicket', $action, $payload)
        );
    }

    private function notifyIfLocalOrCloudCreate(SupportTicket $ticket): void
    {
        if (CloudEntitySyncService::$applying) {
            return;
        }

        app(SupportTicketNotificationService::class)->notifyStaffOfNewTicket($ticket);
    }
}
