<?php

namespace App\Services;

use App\Models\Insurance;
use App\Models\InsurancePlan;
use App\Models\Laboratory;
use App\Models\Paciente;
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
    public function pull(?string $laboratoryId, bool $includePatients = false, ?array $remotePayload = null): array
    {
        $payload = $remotePayload ?? $this->fetchFromCloud($laboratoryId, $includePatients);

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
            foreach ($rows as $row) {
                if (empty($row['id'])) {
                    continue;
                }
                try {
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

        return [
            'counts' => $counts,
            'source' => $remotePayload ? 'payload' : 'remote',
        ];
    }

    /** Aplica catálogo de la misma BD (nube leyendo a sí misma — prueba / matriz). */
    public function pullLocalSnapshot(?string $laboratoryId, bool $includePatients = false): array
    {
        $payload = $this->exporter->export($laboratoryId, $includePatients);
        return $this->pull($laboratoryId, $includePatients, $payload);
    }

    private function fetchFromCloud(?string $laboratoryId, bool $includePatients): array
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
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Exportación nube falló: ' . $response->body());
        }

        $json = $response->json();
        if (!($json['success'] ?? false)) {
            throw new \RuntimeException($json['message'] ?? 'Respuesta inválida de la nube.');
        }

        return $json['data'] ?? [];
    }
}
