<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\Insurance;
use App\Models\InsurancePlan;
use App\Models\Laboratory;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\SubExam;
use App\Models\User;
use App\Support\RisHttp;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
    public function pull(
        ?string $laboratoryId,
        bool $includePatients = false,
        ?array $remotePayload = null,
        bool $includeUsers = false,
        bool $includeAppointments = false,
        ?string $appointmentsFrom = null,
        ?string $appointmentsTo = null,
    ): array {
        $payload = $remotePayload ?? $this->fetchFromCloud(
            $laboratoryId,
            $includePatients,
            $includeUsers,
            $includeAppointments,
            $appointmentsFrom,
            $appointmentsTo,
        );

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

        if ($includeAppointments && !empty($payload['appointments'])) {
            $this->pullReferencedExamCatalog($payload, $counts);

            $counts['appointments'] = 0;
            $counts['appointments_failed'] = 0;
            $counts['appointment_studies'] = 0;
            $counts['appointment_documents'] = 0;
            foreach ($payload['appointments'] as $row) {
                if (empty($row['id'])) {
                    continue;
                }
                try {
                    $hadDocs = !empty($row['medical_order_path'])
                        || !empty($row['medical_order_path_base64'])
                        || !empty($row['survey_path'])
                        || !empty($row['survey_path_base64']);
                    $this->entitySync->apply('App\Models\Appointment', 'updated', $row);
                    $counts['appointments']++;
                    $counts['appointment_studies'] += count($row['studies'] ?? []);
                    if ($hadDocs) {
                        $counts['appointment_documents']++;
                    }
                } catch (\Throwable $e) {
                    $counts['appointments_failed']++;
                    Log::warning('cloud pull appointment ' . $row['id'] . ': ' . $e->getMessage());
                }
            }

            $this->pullOrphanDocuments($payload, $counts, $laboratoryId);
        }

        return [
            'counts' => $counts,
            'source' => $remotePayload ? 'payload' : 'remote',
        ];
    }

    /** Aplica catálogo de la misma BD (nube leyendo a sí misma — prueba / matriz). */
    public function pullLocalSnapshot(
        ?string $laboratoryId,
        bool $includePatients = false,
        bool $includeUsers = false,
        bool $includeAppointments = false,
        ?string $appointmentsFrom = null,
        ?string $appointmentsTo = null,
    ): array {
        $payload = $this->exporter->export(
            $laboratoryId,
            $includePatients,
            $includeUsers,
            $includeAppointments,
            $appointmentsFrom,
            $appointmentsTo,
        );

        return $this->pull(
            $laboratoryId,
            $includePatients,
            $payload,
            $includeUsers,
            $includeAppointments,
            $appointmentsFrom,
            $appointmentsTo,
        );
    }

    private function fetchFromCloud(
        ?string $laboratoryId,
        bool $includePatients,
        bool $includeUsers = false,
        bool $includeAppointments = false,
        ?string $appointmentsFrom = null,
        ?string $appointmentsTo = null,
    ): array {
        $url = config('cloud_sync.export_url');
        $secret = config('cloud_sync.secret');

        if (!$url || !$secret) {
            throw new \RuntimeException('Configure CLOUD_EXPORT_URL y CLOUD_SYNC_SECRET en el .env del laboratorio.');
        }

        $http = RisHttp::client(120)->withToken($secret)->acceptJson();

        $response = $http->get($url, array_filter([
            'laboratory_id' => $laboratoryId,
            'include_patients' => $includePatients ? '1' : '0',
            'include_users' => $includeUsers ? '1' : '0',
            'include_appointments' => $includeAppointments ? '1' : '0',
            'appointments_from' => $appointmentsFrom,
            'appointments_to' => $appointmentsTo,
        ], fn ($value) => $value !== null && $value !== ''));

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

    /**
     * Importa exámenes/subexámenes referenciados por citas que aún no existen localmente.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $counts
     */
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $counts
     */
    private function pullOrphanDocuments(array $payload, array &$counts, ?string $laboratoryId): void
    {
        $orphans = $payload['orphan_documents'] ?? [];
        $counts['orphan_documents'] = 0;
        $counts['orphan_documents_stored'] = 0;

        foreach ($orphans as $doc) {
            if (empty($doc['base64']) || empty($doc['storage_path'])) {
                continue;
            }

            $counts['orphan_documents']++;
            $storedPath = $this->storeImportedDocument((string) $doc['base64'], (string) $doc['storage_path']);
            if ($storedPath !== null) {
                $counts['orphan_documents_stored']++;
                Log::info('cloud pull orphan document stored', [
                    'source' => $doc['storage_path'],
                    'local_path' => $storedPath,
                    'laboratory_id' => $laboratoryId,
                ]);
            }
        }
    }

    private function storeImportedDocument(string $base64, string $sourcePath): ?string
    {
        if (!preg_match('#^data:([^;]+);base64,(.+)$#', $base64, $matches)) {
            return null;
        }

        $raw = base64_decode($matches[2], true);
        if ($raw === false) {
            return null;
        }

        $basename = basename($sourcePath);
        $relative = 'documents/' . $basename;
        if (Storage::disk('public')->exists($relative)) {
            $relative = 'documents/cloud_' . uniqid('', true) . '_' . $basename;
        }

        try {
            Storage::disk('public')->put($relative, $raw);
        } catch (\Throwable $e) {
            Log::warning('cloud pull orphan document store failed: ' . $e->getMessage());

            return null;
        }

        return '/storage/' . $relative;
    }

    private function pullReferencedExamCatalog(array $payload, array &$counts): void
    {
        $examsById = [];
        foreach ($payload['appointment_exams'] ?? [] as $row) {
            if (!empty($row['id'])) {
                $examsById[(string) $row['id']] = $row;
            }
        }
        $subExamsById = [];
        foreach ($payload['appointment_sub_exams'] ?? [] as $row) {
            if (!empty($row['id'])) {
                $subExamsById[(string) $row['id']] = $row;
            }
        }

        foreach ($payload['appointments'] ?? [] as $appointment) {
            foreach ($appointment['studies'] ?? [] as $study) {
                if (!empty($study['exam']['id'])) {
                    $examsById[(string) $study['exam']['id']] = $study['exam'];
                }
                if (!empty($study['sub_exam']['id'])) {
                    $subExamsById[(string) $study['sub_exam']['id']] = $study['sub_exam'];
                }
            }
        }

        $counts['appointment_exams'] = 0;
        $counts['appointment_exams_skipped'] = 0;
        foreach ($examsById as $row) {
            try {
                if ($this->entitySync->catalogRowIsUnchanged(Exam::class, $row)) {
                    $counts['appointment_exams_skipped']++;
                    continue;
                }
                $this->entitySync->apply('Exam', 'updated', $row);
                $counts['appointment_exams']++;
            } catch (\Throwable $e) {
                Log::warning('cloud pull appointment exam ' . ($row['id'] ?? '') . ': ' . $e->getMessage());
            }
        }

        $counts['appointment_sub_exams'] = 0;
        $counts['appointment_sub_exams_skipped'] = 0;
        foreach ($subExamsById as $row) {
            try {
                if ($this->entitySync->catalogRowIsUnchanged(SubExam::class, $row)) {
                    $counts['appointment_sub_exams_skipped']++;
                    continue;
                }
                $this->entitySync->apply('SubExam', 'updated', $row);
                $counts['appointment_sub_exams']++;
            } catch (\Throwable $e) {
                Log::warning('cloud pull appointment sub_exam ' . ($row['id'] ?? '') . ': ' . $e->getMessage());
            }
        }
    }
}
