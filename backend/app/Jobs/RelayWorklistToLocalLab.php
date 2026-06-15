<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Support\LaboratoryMwlRelay;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nube → laboratorio LAN: replica la cita en el MWL local (p. ej. Fuji FCR en SIRESA).
 */
class RelayWorklistToLocalLab implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $appointmentId;

    public int $tries = 3;

    public int $backoff = 20;

    public function __construct(string $appointmentId)
    {
        $this->appointmentId = $appointmentId;
    }

    public function handle(): void
    {
        $appointment = Appointment::with(['patient.persona', 'studies', 'laboratory'])->find($this->appointmentId);
        if (!$appointment?->patient?->persona || !$appointment->laboratory) {
            return;
        }

        $relayUrl = LaboratoryMwlRelay::resolveUrl($appointment->laboratory);
        if ($relayUrl === null) {
            return;
        }

        $secret = config('cloud_sync.secret');
        if (!filled($secret)) {
            Log::warning('MWL relay: CLOUD_SYNC_SECRET no configurado.');

            return;
        }

        $persona = $appointment->patient->persona->toArray();
        $paciente = $appointment->patient->toArray();
        $paciente['persona'] = $persona;

        $http = Http::timeout(30)->withToken($secret)->acceptJson()->asJson();
        if (app()->environment('local', 'testing')) {
            $http = $http->withoutVerifying();
        }

        $response = $http->post($relayUrl, [
            'persona' => $persona,
            'paciente' => $paciente,
            'appointment' => $appointment->toArray(),
        ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'MWL relay falló (' . $response->status() . ') en ' . $relayUrl . ': ' . $response->body()
            );
        }

        Log::info('MWL relay OK', [
            'appointment_id' => $appointment->id,
            'laboratory' => $appointment->laboratory->name,
            'relay_url' => $relayUrl,
        ]);
    }
}
