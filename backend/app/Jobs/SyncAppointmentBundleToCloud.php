<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\CloudSyncLog;
use App\Services\CloudSyncFilePackager;
use App\Services\CloudSyncLogger;
use App\Support\CloudSyncMode;
use App\Support\CloudSyncTransport;
use App\Support\RisHttp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envía persona → paciente → cita a la nube en un solo job (orden garantizado).
 *
 * Dos fases en la cita: (1) metadatos/estado sin base64, (2) archivos.
 * Así la nube no queda atrasada (p. ej. en_transcripcion) si los PDF/audio dan 413.
 */
class SyncAppointmentBundleToCloud implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $appointmentId;

    public string $action;

    public ?string $syncLogId = null;

    public int $tries = 3;

    public function __construct(string $appointmentId, string $action = 'created', ?string $syncLogId = null)
    {
        $this->appointmentId = $appointmentId;
        $this->action = $action;
        $this->syncLogId = $syncLogId;
    }

    public function handle(): void
    {
        if (!CloudSyncMode::canPushToCloud()) {
            return;
        }

        $appointment = $this->loadAppointment();
        if (!$appointment?->patient?->persona) {
            return;
        }

        $metaPayload = $this->buildAppointmentPayload($appointment, false);
        if (!$this->syncLogId) {
            $this->syncLogId = CloudSyncLogger::startPending('App\Models\Appointment', $this->action, $metaPayload)->id;
        } else {
            CloudSyncLogger::markAttempt($this->syncLogId);
        }

        $cloudUrl = config('cloud_sync.inbound_url');
        $secret = config('cloud_sync.secret');

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

        // Fase 1: estado/metadatos (siempre, liviano).
        $chunks[] = [
            'model' => 'App\Models\Appointment',
            'action' => $this->action,
            'data' => $metaPayload,
        ];

        try {
            foreach ($chunks as $chunk) {
                $timeout = CloudSyncTransport::timeoutForPayload($chunk['data'] ?? []);
                $http = RisHttp::client($timeout)
                    ->withToken($secret)
                    ->acceptJson()
                    ->asJson();

                $response = $http->withHeaders($headers)->post($cloudUrl, $chunk);
                if ($response->failed()) {
                    $error = CloudSyncTransport::exceptionFromResponse(
                        $response,
                        'Sync bundle falló en ' . $chunk['model']
                    );

                    if ($this->deferTransientFailure($error, $response->status())) {
                        return;
                    }

                    throw $error;
                }
            }

            // Recargar por si el estado cambió mientras corrían los chunks previos.
            $appointment = $this->loadAppointment();
            if (!$appointment) {
                if ($this->syncLogId) {
                    CloudSyncLogger::markSuccess($this->syncLogId);
                }

                return;
            }

            $filePayload = $this->buildAppointmentPayload($appointment, true);
            if (CloudSyncFilePackager::payloadHasBase64($filePayload)) {
                $timeout = CloudSyncTransport::timeoutForPayload($filePayload);
                $http = RisHttp::client($timeout)
                    ->withToken($secret)
                    ->acceptJson()
                    ->asJson();

                $response = $http->withHeaders($headers)->post($cloudUrl, [
                    'model' => 'App\Models\Appointment',
                    'action' => $this->action,
                    'data' => $filePayload,
                ]);

                if ($response->failed()) {
                    $status = $response->status();
                    // Estado ya quedó en la nube; no revertir el éxito de metadatos por 413 de archivos.
                    if ($status === 413) {
                        Log::warning('Cloud sync: metadatos OK; archivos rechazados por tamaño (413)', [
                            'appointment_id' => $this->appointmentId,
                            'sync_log_id' => $this->syncLogId,
                        ]);
                    } else {
                        $error = CloudSyncTransport::exceptionFromResponse(
                            $response,
                            'Sync bundle falló en archivos App\Models\Appointment'
                        );

                        if ($this->deferTransientFailure($error, $status)) {
                            return;
                        }

                        throw $error;
                    }
                }
            }

            if ($this->syncLogId) {
                CloudSyncLogger::markSuccess($this->syncLogId);
            }
        } catch (\Throwable $e) {
            if ($this->deferTransientFailure($e)) {
                return;
            }

            if ($this->syncLogId) {
                CloudSyncLogger::markFailed($this->syncLogId, $e);
            }
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->syncLogId && CloudSyncTransport::isTransient($exception)) {
            CloudSyncLogger::markDeferred($this->syncLogId, $exception);

            return;
        }

        if ($this->syncLogId) {
            CloudSyncLogger::markFailed($this->syncLogId, $exception);
        }
    }

    private function loadAppointment(): ?Appointment
    {
        return Appointment::with([
            'patient.persona',
            'studies.exam',
            'studies.subExam',
            'studies.machine',
            'supplies',
            'machine',
            'referringDoctor',
            'insurance',
            'insurancePlan',
        ])->find($this->appointmentId);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAppointmentPayload(Appointment $appointment, bool $includeFiles): array
    {
        $payload = $appointment->toArray();
        if ($appointment->relationLoaded('supplies')) {
            $payload['supplies'] = $appointment->supplies
                ->map(fn ($supply) => [
                    'id' => $supply->id,
                    'quantity' => (int) ($supply->pivot->quantity ?? 1),
                    'price' => (float) ($supply->pivot->price_charged ?? 0),
                    'price_charged' => (float) ($supply->pivot->price_charged ?? 0),
                ])
                ->values()
                ->all();
        }

        CloudSyncFilePackager::packAppointmentPayload($payload, $includeFiles);

        return $includeFiles ? $payload : CloudSyncFilePackager::withoutBase64($payload);
    }

    private function deferTransientFailure(\Throwable $e, ?int $httpStatus = null): bool
    {
        $transient = $httpStatus !== null
            ? CloudSyncTransport::isTransientHttpStatus($httpStatus)
            : CloudSyncTransport::isTransient($e);

        if (!$transient || CloudSyncTransport::isPermanentHttpStatus($httpStatus)) {
            return false;
        }

        if ($this->syncLogId) {
            CloudSyncLogger::markDeferred($this->syncLogId, $e);
        }

        $attempts = $this->syncLogId
            ? (int) CloudSyncLog::whereKey($this->syncLogId)->value('attempts')
            : $this->attempts();

        $delay = CloudSyncTransport::releaseDelaySeconds($attempts);

        Log::info('Cloud sync bundle diferido (sin conectividad o nube no disponible)', [
            'appointment_id' => $this->appointmentId,
            'sync_log_id' => $this->syncLogId,
            'delay_seconds' => $delay,
            'error' => $e->getMessage(),
        ]);

        $this->release($delay);

        return true;
    }
}
