<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\CloudSyncLogger;
use App\Support\CloudSyncMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Envía persona → paciente → cita a la nube en un solo job (orden garantizado).
 */
class SyncAppointmentBundleToCloud implements ShouldQueue, ShouldQueueAfterCommit, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $appointmentId;

    public string $action;

    public ?string $syncLogId = null;

    public int $uniqueFor = 120;

    public function __construct(string $appointmentId, string $action = 'created', ?string $syncLogId = null)
    {
        $this->appointmentId = $appointmentId;
        $this->action = $action;
        $this->syncLogId = $syncLogId;
    }

    public function uniqueId(): string
    {
        return 'appointment-bundle:' . $this->action . ':' . $this->appointmentId;
    }

    public function handle(): void
    {
        if (!CloudSyncMode::canPushToCloud()) {
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
        ])->find($this->appointmentId);
        if (!$appointment?->patient?->persona) {
            return;
        }

        $payload = $appointment->toArray();
        if (!$this->syncLogId) {
            $this->syncLogId = CloudSyncLogger::startPending('App\Models\Appointment', $this->action, $payload)->id;
        } else {
            CloudSyncLogger::markAttempt($this->syncLogId);
        }

        $cloudUrl = config('cloud_sync.inbound_url');
        $secret = config('cloud_sync.secret');

        $http = Http::timeout(20)->withToken($secret)->acceptJson()->asJson();
        if (app()->environment('local', 'testing')) {
            $http = $http->withoutVerifying();
        }

        $headers = [
            'User-Agent' => 'HealthTiCloud-RIS/1.0',
            'X-Requested-With' => 'XMLHttpRequest',
        ];

        $persona = $appointment->patient->persona->toArray();
        $paciente = $appointment->patient->toArray();
        $paciente['persona'] = $persona;

        $chunks = [
            ['model' => 'App\Models\Persona', 'action' => 'updated', 'data' => $persona],
            ['model' => 'App\Models\Paciente', 'action' => 'updated', 'data' => $paciente],
        ];

        $seen = [];
        $pushCatalog = function (string $model, array $data) use (&$chunks, &$seen): void {
            $id = (string) ($data['id'] ?? '');
            if ($id === '' || isset($seen[$model . ':' . $id])) {
                return;
            }
            $seen[$model . ':' . $id] = true;
            $chunks[] = ['model' => $model, 'action' => 'updated', 'data' => $data];
        };

        if ($appointment->insurance) {
            $pushCatalog('Insurance', $appointment->insurance->toArray());
        }
        if ($appointment->insurancePlan) {
            $pushCatalog('InsurancePlan', $appointment->insurancePlan->toArray());
        }
        if ($appointment->referringDoctor) {
            $pushCatalog('ReferringDoctor', $appointment->referringDoctor->toArray());
        }
        if ($appointment->machine) {
            $pushCatalog('Machine', $appointment->machine->toArray());
        }
        foreach ($appointment->studies as $study) {
            if ($study->exam) {
                $pushCatalog('Exam', $study->exam->toArray());
            }
            if ($study->machine) {
                $pushCatalog('Machine', $study->machine->toArray());
            }
        }

        $chunks[] = [
            'model' => 'App\Models\Appointment',
            'action' => $this->action,
            'data' => $payload,
        ];

        try {
            foreach ($chunks as $chunk) {
                $response = $http->withHeaders($headers)->post($cloudUrl, $chunk);
                if ($response->failed()) {
                    throw new \RuntimeException(
                        'Sync bundle falló en ' . $chunk['model'] . ': ' . $response->body()
                    );
                }
            }

            if ($this->syncLogId) {
                CloudSyncLogger::markSuccess($this->syncLogId);
            }
        } catch (\Throwable $e) {
            if ($this->syncLogId) {
                CloudSyncLogger::markFailed($this->syncLogId, $e);
            }
            throw $e;
        }
    }
}
