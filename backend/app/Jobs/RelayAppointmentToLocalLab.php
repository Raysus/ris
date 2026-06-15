<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Support\LaboratorySyncRelay;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nube → laboratorio LAN: replica cambios de cita (estado clínico, estudios, etc.).
 */
class RelayAppointmentToLocalLab implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $appointmentId;

    public string $action;

    public int $tries = 3;

    public int $backoff = 20;

    public int $uniqueFor = 60;

    public function __construct(string $appointmentId, string $action = 'updated')
    {
        $this->appointmentId = $appointmentId;
        $this->action = $action;
    }

    public function uniqueId(): string
    {
        return 'appointment-relay:' . $this->action . ':' . $this->appointmentId;
    }

    public function handle(): void
    {
        if ($this->action === 'deleted') {
            $appointment = Appointment::withTrashed()->with('laboratory')->find($this->appointmentId);
            if (!$appointment) {
                return;
            }

            $this->postToLab($appointment, ['id' => $appointment->id]);

            return;
        }

        $appointment = Appointment::with([
            'patient.persona',
            'studies.exam',
            'studies.machine',
            'supplies',
            'machine',
            'referringDoctor',
            'insurance',
            'insurancePlan',
            'laboratory',
        ])->find($this->appointmentId);

        if (!$appointment?->patient?->persona || !$appointment->laboratory) {
            return;
        }

        $persona = $appointment->patient->persona->toArray();
        $paciente = $appointment->patient->toArray();
        $paciente['persona'] = $persona;

        $this->postToLab($appointment, $appointment->toArray(), $persona, $paciente);
    }

    private function postToLab(Appointment $appointment, array $appointmentPayload, ?array $persona = null, ?array $paciente = null): void
    {
        $relayUrl = LaboratorySyncRelay::resolveUrl($appointment->laboratory);
        if ($relayUrl === null) {
            return;
        }

        $secret = config('cloud_sync.secret');
        if (!filled($secret)) {
            Log::warning('Appointment relay: CLOUD_SYNC_SECRET no configurado.');

            return;
        }

        $http = Http::timeout(30)->withToken($secret)->acceptJson()->asJson();
        if (app()->environment('local', 'testing')) {
            $http = $http->withoutVerifying();
        }

        $body = [
            'action' => $this->action,
            'appointment' => $appointmentPayload,
        ];

        if ($persona !== null && $paciente !== null) {
            $body['persona'] = $persona;
            $body['paciente'] = $paciente;
        }

        $response = $http->post($relayUrl, $body);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Appointment relay falló (' . $response->status() . ') en ' . $relayUrl . ': ' . $response->body()
            );
        }

        Log::info('Appointment relay OK', [
            'appointment_id' => $appointment->id,
            'action' => $this->action,
            'laboratory' => $appointment->laboratory?->name,
            'relay_url' => $relayUrl,
        ]);
    }
}
