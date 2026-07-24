<?php

namespace App\Jobs;

use App\Models\SupportTicket;
use App\Support\CloudSyncMode;
use App\Support\LaboratorySyncRelay;
use App\Support\RisHttp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Nube → laboratorio: replica tickets de soporte (altas y respuestas admin).
 */
class RelaySupportTicketToLocalLab implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $ticketId;

    public string $action;

    public int $tries = 3;

    public int $backoff = 20;

    public int $uniqueFor = 60;

    public function __construct(string $ticketId, string $action = 'updated')
    {
        $this->ticketId = $ticketId;
        $this->action = $action;
    }

    public function uniqueId(): string
    {
        return 'support-ticket-relay:' . $this->action . ':' . $this->ticketId;
    }

    public function handle(): void
    {
        if (!CloudSyncMode::isCloud()) {
            return;
        }

        $ticket = SupportTicket::query()->with('laboratory')->find($this->ticketId);
        if (!$ticket && $this->action !== 'deleted') {
            return;
        }

        $lab = $ticket?->laboratory;
        $relayUrl = LaboratorySyncRelay::resolveEntityUrl($lab);
        if (!$relayUrl) {
            return;
        }

        $secret = config('cloud_sync.secret');
        if (!$secret) {
            Log::warning('RelaySupportTicketToLocalLab: CLOUD_SYNC_SECRET ausente');

            return;
        }

        $payload = [
            'model' => 'SupportTicket',
            'action' => $this->action,
            'data' => $this->action === 'deleted'
                ? ['id' => $this->ticketId]
                : $ticket->toArray(),
        ];

        $response = RisHttp::client(15)
            ->withToken($secret)
            ->acceptJson()
            ->asJson()
            ->post($relayUrl, $payload);

        if (!$response->successful()) {
            Log::warning('RelaySupportTicketToLocalLab falló', [
                'ticket_id' => $this->ticketId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $response->throw();
        }
    }
}
