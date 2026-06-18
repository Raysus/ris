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

        $catalog = $this->buildCatalogChunks($appointment);

        $this->postToLab($appointment, $appointment->toArray(), $persona, $paciente, $catalog);
    }

    /**
     * @return list<array{model: string, data: array<string, mixed>}>
     */
    private function buildCatalogChunks(Appointment $appointment): array
    {
        $chunks = [];
        $seen = [];

        $push = function (string $model, ?array $data) use (&$chunks, &$seen): void {
            if (!$data) {
                return;
            }
            $id = (string) ($data['id'] ?? '');
            if ($id === '' || isset($seen[$model . ':' . $id])) {
                return;
            }
            $seen[$model . ':' . $id] = true;
            $chunks[] = ['model' => $model, 'data' => $data];
        };

        if ($appointment->insurance) {
            $push('Insurance', $appointment->insurance->toArray());
        }
        if ($appointment->insurancePlan) {
            $push('InsurancePlan', $appointment->insurancePlan->toArray());
        }
        if ($appointment->referringDoctor) {
            $push('ReferringDoctor', $appointment->referringDoctor->toArray());
        }
        if ($appointment->machine) {
            $push('Machine', $appointment->machine->toArray());
        }
        foreach ($appointment->studies as $study) {
            if ($study->exam) {
                $push('Exam', $study->exam->toArray());
            }
            if ($study->machine) {
                $push('Machine', $study->machine->toArray());
            }
        }

        return $chunks;
    }

    private function postToLab(
        Appointment $appointment,
        array $appointmentPayload,
        ?array $persona = null,
        ?array $paciente = null,
        array $catalog = [],
    ): void
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

        if ($catalog !== []) {
            $body['catalog'] = $catalog;
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
