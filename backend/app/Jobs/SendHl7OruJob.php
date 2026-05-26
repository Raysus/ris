<?php

namespace App\Jobs;

use App\Services\Hl7IntegrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendHl7OruJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $appointmentId)
    {
    }

    public function handle(Hl7IntegrationService $hl7): void
    {
        if (!config('hl7.enabled')) {
            return;
        }

        $hl7->sendOruForAppointment($this->appointmentId);
    }
}
