<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\CloudSyncFilePackager;
use App\Support\CloudSyncTransport;
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
 * Nube → laboratorio LAN: replica citas con metadatos y, en segunda fase, audios/PDFs completos.
 */
class RelayAppointmentToLocalLab implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $appointmentId;

    public string $action;

    public int $tries = 5;

    public int $backoff = 30;

    public int $uniqueFor = 180;

    public int $timeout = 600;

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

        $appointment = $this->loadAppointment();
        if (!$appointment?->patient?->persona || !$appointment->laboratory) {
            return;
        }

        $persona = $appointment->patient->persona->toArray();
        $paciente = $appointment->patient->toArray();
        $paciente['persona'] = $persona;
        $catalog = $this->buildCatalogChunks($appointment);

        // Fase 1: estado clínico sin binarios (rápido).
        $metaPayload = $this->buildAppointmentPayload($appointment, false);
        $this->postToLab($appointment, $metaPayload, $persona, $paciente, $catalog);

        // Fase 2: audios, PDFs de informe, órdenes, etc.
        $appointment = $this->loadAppointment();
        if (!$appointment) {
            return;
        }

        $filePayload = $this->buildAppointmentPayload($appointment, true);
        if (!CloudSyncFilePackager::payloadHasBase64($filePayload)) {
            return;
        }

        $this->postToLab($appointment, $filePayload, null, null, []);
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
            'laboratory',
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
    ): void {
        $relayUrl = LaboratorySyncRelay::resolveUrl($appointment->laboratory);
        if ($relayUrl === null) {
            return;
        }

        $secret = config('cloud_sync.secret');
        if (!filled($secret)) {
            Log::warning('Appointment relay: CLOUD_SYNC_SECRET no configurado.');

            return;
        }

        $timeout = max(
            CloudSyncTransport::timeoutForPayload($appointmentPayload),
            CloudSyncFilePackager::payloadHasBase64($appointmentPayload) ? 300 : 30
        );

        $http = RisHttp::client($timeout)->withToken($secret)->acceptJson()->asJson();

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
            $status = $response->status();
            if ($status === 413 && CloudSyncFilePackager::payloadHasBase64($appointmentPayload)) {
                Log::error('Appointment relay: lab rechazó archivos por tamaño (413). Aumente client_max_body_size / nginx.', [
                    'appointment_id' => $appointment->id,
                    'relay_url' => $relayUrl,
                ]);
            }

            throw new \RuntimeException(
                'Appointment relay falló (' . $status . ') en ' . $relayUrl . ': ' . $response->body()
            );
        }

        Log::info('Appointment relay OK', [
            'appointment_id' => $appointment->id,
            'action' => $this->action,
            'laboratory' => $appointment->laboratory?->name,
            'relay_url' => $relayUrl,
            'with_files' => CloudSyncFilePackager::payloadHasBase64($appointmentPayload),
        ]);
    }
}
