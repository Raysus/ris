<?php

namespace App\Services;

use App\Models\Insurance;
use App\Models\InsurancePlan;
use App\Models\Laboratory;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudCatalogPullService
{
    public function __construct(
        private readonly CloudEntitySyncService $entitySync,
        private readonly CloudCatalogExportService $exporter,
    ) {}

    /**
     * Importa catálogo desde la nube (HTTP) o aplica un payload ya descargado.
     *
     * @return array{counts: array<string, int>, source: string}
     */
    public function pull(?string $laboratoryId, bool $includePatients = false, ?array $remotePayload = null, bool $includeUsers = false): array
    {
        $payload = $remotePayload ?? $this->fetchFromCloud($laboratoryId, $includePatients, $includeUsers);

        $counts = [];

        $map = [
            'laboratories' => Laboratory::class,
            'referring_doctors' => \App\Models\ReferringDoctor::class,
            'exams' => \App\Models\Exam::class,
            'machines' => \App\Models\Machine::class,
            'supplies' => \App\Models\Supply::class,
            'report_templates' => \App\Models\ReportTemplate::class,
            'insurances' => Insurance::class,
            'insurance_plans' => InsurancePlan::class,
            'services' => \App\Models\Service::class,
        ];

        foreach ($map as $key => $class) {
            $rows = $payload[$key] ?? [];
            $counts[$key] = 0;
            $counts[$key . '_skipped'] = 0;
            foreach ($rows as $row) {
                if (empty($row['id'])) {
                    continue;
                }
                try {
                    if ($this->entitySync->catalogRowIsUnchanged($class, $row)) {
                        $counts[$key . '_skipped']++;
                        continue;
                    }
                    $this->entitySync->apply(class_basename($class), 'updated', $row);
                    $counts[$key]++;
                } catch (\Throwable $e) {
                    Log::warning("cloud pull {$key} {$row['id']}: " . $e->getMessage());
                }
            }
        }

        if ($includePatients && !empty($payload['pacientes'])) {
            $counts['pacientes'] = 0;
            foreach ($payload['pacientes'] as $row) {
                try {
                    if (!empty($row['persona'])) {
                        $this->entitySync->apply('Persona', 'updated', $row['persona']);
                    }
                    $this->entitySync->apply('Paciente', 'updated', Arr::except($row, ['persona']));
                    $counts['pacientes']++;
                } catch (\Throwable $e) {
                    Log::warning('cloud pull paciente: ' . $e->getMessage());
                }
            }
        }

        if ($includeUsers && !empty($payload['users'])) {
            $counts['users'] = 0;
            $counts['users_skipped'] = 0;
            foreach ($payload['users'] as $row) {
                if (empty($row['id'])) {
                    continue;
                }
                try {
                    if (!empty($row['persona']) && $this->entitySync->catalogRowIsUnchanged(Persona::class, $row['persona'])) {
                        // persona sin cambios
                    } elseif (!empty($row['persona'])) {
                        $this->entitySync->apply('Persona', 'updated', $row['persona']);
                    }
                    if ($this->entitySync->catalogRowIsUnchanged(User::class, $row)) {
                        $counts['users_skipped']++;
                        continue;
                    }
                    $this->entitySync->apply('User', 'updated', $row);
                    $counts['users']++;
                } catch (\Throwable $e) {
                    Log::warning("cloud pull user {$row['id']}: " . $e->getMessage());
                }
            }

            $counts['laboratory_users'] = 0;
            foreach ($payload['laboratory_users'] ?? [] as $row) {
                if (empty($row['id'])) {
                    continue;
                }
                try {
                    if ($this->entitySync->catalogRowIsUnchanged(\App\Models\LaboratoryUser::class, $row)) {
                        continue;
                    }
                    $this->entitySync->apply('LaboratoryUser', 'updated', $row);
                    $counts['laboratory_users']++;
                } catch (\Throwable $e) {
                    Log::warning("cloud pull laboratory_user {$row['id']}: " . $e->getMessage());
                }
            }
        }

        return [
            'counts' => $counts,
            'source' => $remotePayload ? 'payload' : 'remote',
        ];
    }

    /** Aplica catálogo de la misma BD (nube leyendo a sí misma — prueba / matriz). */
    public function pullLocalSnapshot(?string $laboratoryId, bool $includePatients = false, bool $includeUsers = false): array
    {
        $payload = $this->exporter->export($laboratoryId, $includePatients, $includeUsers);
        return $this->pull($laboratoryId, $includePatients, $payload, $includeUsers);
    }

    private function fetchFromCloud(?string $laboratoryId, bool $includePatients, bool $includeUsers = false): array
    {
        $url = config('cloud_sync.export_url');
        $secret = config('cloud_sync.secret');

        if (!$url || !$secret) {
            throw new \RuntimeException('Configure CLOUD_EXPORT_URL y CLOUD_SYNC_SECRET en el .env del laboratorio.');
        }

        $http = Http::timeout(60)->withToken($secret)->acceptJson();
        if (app()->environment('local', 'testing')) {
            $http = $http->withoutVerifying();
        }

        $response = $http->get($url, [
            'laboratory_id' => $laboratoryId,
            'include_patients' => $includePatients ? '1' : '0',
            'include_users' => $includeUsers ? '1' : '0',
        ]);

        if ($response->failed()) {
            if ($response->status() === 401) {
                throw new \RuntimeException(
                    'Token de sincronización inválido: CLOUD_SYNC_SECRET del laboratorio no coincide con el de '
                    . parse_url($url, PHP_URL_HOST) . '. Pídalo a sistemas, actualice backend/.env y reinicie api/queue.'
                );
            }
            throw new \RuntimeException('Exportación nube falló: ' . $response->body());
        }

        $json = $response->json();
        if (!($json['success'] ?? false)) {
            throw new \RuntimeException($json['message'] ?? 'Respuesta inválida de la nube.');
        }

        return $json['data'] ?? [];
    }
}
