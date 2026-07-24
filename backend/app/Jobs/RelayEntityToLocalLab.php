<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\AppointmentLog;
use App\Models\Laboratory;
use App\Models\Paciente;
use App\Models\Payment;
use App\Models\User;
use App\Support\CloudSyncMode;
use App\Support\LaboratorySyncRelay;
use App\Support\RisHttp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Nube → laboratorio: replica una entidad vía /integrations/local-sync/entity.
 */
class RelayEntityToLocalLab implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $entityType;

    public string $action;

    public string $entityId;

    /** @var array<string, mixed> */
    public array $payload;

    public int $tries = 3;

    public int $backoff = 20;

    public int $uniqueFor = 90;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(string $entityType, string $action, string $entityId, array $payload = [])
    {
        $this->entityType = $entityType;
        $this->action = $action;
        $this->entityId = $entityId;
        $this->payload = $payload;
    }

    public function uniqueId(): string
    {
        return 'entity-relay:' . strtolower(class_basename($this->entityType))
            . ':' . $this->action . ':' . $this->entityId;
    }

    public function handle(): void
    {
        if (!CloudSyncMode::isCloud()) {
            return;
        }

        $secret = config('cloud_sync.secret');
        if (!filled($secret)) {
            Log::warning('RelayEntityToLocalLab: CLOUD_SYNC_SECRET ausente', [
                'model' => $this->entityType,
                'id' => $this->entityId,
            ]);

            return;
        }

        $data = $this->action === 'deleted'
            ? ['id' => $this->entityId]
            : ($this->payload ?: ['id' => $this->entityId]);

        $labs = $this->resolveTargetLaboratories();
        if ($labs->isEmpty()) {
            Log::debug('RelayEntityToLocalLab: sin laboratorios destino', [
                'model' => $this->entityType,
                'id' => $this->entityId,
            ]);

            return;
        }

        $body = [
            'model' => $this->normalizeModelKey($this->entityType),
            'action' => $this->action,
            'data' => $data,
        ];

        foreach ($labs as $lab) {
            $relayUrl = LaboratorySyncRelay::resolveEntityUrl($lab);
            if (!$relayUrl) {
                continue;
            }

            $response = RisHttp::client(20)
                ->withToken($secret)
                ->acceptJson()
                ->asJson()
                ->post($relayUrl, $body);

            if (!$response->successful()) {
                Log::warning('RelayEntityToLocalLab falló', [
                    'model' => $this->entityType,
                    'id' => $this->entityId,
                    'lab' => $lab->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                $response->throw();
            }
        }
    }

    /** @return Collection<int, Laboratory> */
    private function resolveTargetLaboratories(): Collection
    {
        $short = class_basename($this->entityType);

        if (!empty($this->payload['laboratory_id'])) {
            $lab = Laboratory::query()->find($this->payload['laboratory_id']);

            return $lab ? collect([$lab]) : collect();
        }

        return match ($short) {
            'Laboratory' => tap(collect(), function (Collection $c) {
                $lab = Laboratory::query()->find($this->entityId);
                if ($lab) {
                    $c->push($lab);
                }
            }),
            'User' => User::query()->with('laboratories')->find($this->entityId)?->laboratories ?? collect(),
            'Persona' => $this->labsForPersona($this->entityId),
            'Paciente' => $this->labsFromPayloadOrModel(
                Paciente::query()->find($this->entityId)?->laboratory_id
            ),
            'AppointmentLog' => $this->labsFromAppointment(
                $this->payload['appointment_id']
                    ?? AppointmentLog::query()->whereKey($this->entityId)->value('appointment_id')
            ),
            'Payment' => $this->labsFromAppointment(
                $this->payload['appointment_id']
                    ?? Payment::query()->whereKey($this->entityId)->value('appointment_id')
            ),
            'SupportTicket' => $this->labsFromPayloadOrModel(
                \App\Models\SupportTicket::query()->whereKey($this->entityId)->value('laboratory_id')
            ),
            default => $this->labsFromPayloadOrModel(
                $this->payload['laboratory_id'] ?? null
            ),
        };
    }

    private function labsForPersona(string $personaId): Collection
    {
        $labIds = Paciente::query()
            ->where('persona_id', $personaId)
            ->pluck('laboratory_id')
            ->filter()
            ->unique()
            ->values();

        if ($labIds->isEmpty()) {
            // Persona de usuario: sedes del user
            $userLabIds = User::query()
                ->where('persona_id', $personaId)
                ->with('laboratories')
                ->get()
                ->flatMap(fn (User $u) => $u->laboratories->pluck('id'))
                ->unique()
                ->values();
            $labIds = $userLabIds;
        }

        if ($labIds->isEmpty()) {
            return collect();
        }

        return Laboratory::query()->whereIn('id', $labIds)->get();
    }

    private function labsFromAppointment(mixed $appointmentId): Collection
    {
        if (!$appointmentId) {
            return collect();
        }

        $labId = Appointment::query()->whereKey($appointmentId)->value('laboratory_id');

        return $this->labsFromPayloadOrModel($labId);
    }

    private function labsFromPayloadOrModel(mixed $labId): Collection
    {
        if (!$labId) {
            return collect();
        }

        $lab = Laboratory::query()->find($labId);

        return $lab ? collect([$lab]) : collect();
    }

    private function normalizeModelKey(string $entityType): string
    {
        if (str_contains($entityType, '\\')) {
            return $entityType;
        }

        return 'App\\Models\\' . class_basename($entityType);
    }
}
