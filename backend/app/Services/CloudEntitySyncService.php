<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentStudy;
use App\Models\Exam;
use App\Models\Insurance;
use App\Models\InsurancePlan;
use App\Models\Laboratory;
use App\Models\Machine;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\ReferringDoctor;
use App\Models\ReportTemplate;
use App\Models\Service;
use App\Models\Supply;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CloudEntitySyncService
{
    public static bool $applying = false;

    public function apply(string $modelKey, string $action, array $data): void
    {
        $class = $this->resolveModelClass($modelKey);
        if (!$class) {
            throw new \InvalidArgumentException("Modelo no soportado para sync: {$modelKey}");
        }

        self::$applying = true;

        try {
            if ($action === 'deleted') {
                $id = $data['id'] ?? null;
                if ($id) {
                    $class::withoutEvents(fn () => $class::where('id', $id)->delete());
                }

                return;
            }

            $this->unpackFileFields($data);

            if ($class === Persona::class && !empty($data['rut'])) {
                $this->syncPersona($data);

                return;
            }

            if ($class === Paciente::class) {
                $this->syncPaciente($data);

                return;
            }

            $class::withoutEvents(function () use ($class, $action, $data) {
                if ($class === Appointment::class) {
                    $this->syncAppointment($data);
                    return;
                }

                $attrs = $this->filterAttributes($class, $data);
                if (empty($attrs['id']) && empty($data['id'])) {
                    return;
                }

                $id = $attrs['id'] ?? $data['id'];
                unset($attrs['id']);

                if ($class === \App\Models\User::class) {
                    unset($attrs['remember_token']);
                    if (empty($attrs['password'])) {
                        unset($attrs['password']);
                    }
                }

                $this->upsertCatalogEntity($class, $id, $attrs);
            });
        } finally {
            self::$applying = false;
        }
    }

    /**
     * Pull: omitir filas que ya existen con los mismos datos (evita reescribir todo el catálogo).
     */
    public function catalogRowIsUnchanged(string $class, array $row): bool
    {
        $attrs = $this->filterAttributes($class, $row);
        $incomingId = $attrs['id'] ?? $row['id'] ?? null;
        if (!$incomingId) {
            return false;
        }
        unset($attrs['id']);

        $record = $this->findCatalogRecord($class, $attrs, (string) $incomingId);
        if (!$record) {
            return false;
        }

        foreach ($attrs as $key => $value) {
            if (in_array($key, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }
            if ($this->catalogValuesDiffer($record->getAttribute($key), $value)) {
                return false;
            }
        }

        return true;
    }

    private function upsertCatalogEntity(string $class, string $incomingId, array $attrs): void
    {
        $record = $this->findCatalogRecord($class, $attrs, $incomingId);

        if ($record) {
            if ($this->usesSoftDeletes($class) && $record->trashed()) {
                $record->restore();
            }
            $record->fill($attrs);
            $record->save();

            if ((string) $record->id !== (string) $incomingId) {
                Log::debug('cloud sync catalog: actualizado por clave de negocio', [
                    'model' => class_basename($class),
                    'incoming_id' => $incomingId,
                    'matched_id' => $record->id,
                ]);
            }

            return;
        }

        $class::create(array_merge(['id' => $incomingId], $attrs));
    }

    private function findCatalogRecord(string $class, array $attrs, string $incomingId): ?Model
    {
        $query = $this->usesSoftDeletes($class)
            ? $class::withTrashed()
            : $class::query();

        $byId = (clone $query)->find($incomingId);
        if ($byId) {
            return $byId;
        }

        return $this->findCatalogByBusinessKey($class, $attrs);
    }

    private function findCatalogByBusinessKey(string $class, array $attrs): ?Model
    {
        $query = $this->usesSoftDeletes($class)
            ? $class::withTrashed()
            : $class::query();

        return match ($class) {
            Exam::class => $this->firstWhenFilled($query, [
                'laboratory_id' => $attrs['laboratory_id'] ?? null,
                'name' => $this->normalizeCatalogText($attrs['name'] ?? null),
            ], fn ($q, $labId, $name) => $q->where('laboratory_id', $labId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])),

            Machine::class => $this->firstWhenFilled($query, [
                'laboratory_id' => $attrs['laboratory_id'] ?? null,
                'name' => $this->normalizeCatalogText($attrs['name'] ?? null),
            ], fn ($q, $labId, $name) => $q->where('laboratory_id', $labId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])),

            ReportTemplate::class => $this->firstWhenFilled($query, [
                'laboratory_id' => $attrs['laboratory_id'] ?? null,
                'group_code' => $attrs['group_code'] ?? null,
                'title' => $this->normalizeCatalogText($attrs['title'] ?? null),
            ], fn ($q, $labId, $group, $title) => $q->where('laboratory_id', $labId)
                ->where('group_code', $group)
                ->whereRaw('LOWER(TRIM(title)) = ?', [$title])),

            Supply::class => $this->firstWhenFilled($query, [
                'laboratory_id' => $attrs['laboratory_id'] ?? null,
                'name' => $this->normalizeCatalogText($attrs['name'] ?? null),
            ], fn ($q, $labId, $name) => $q->where('laboratory_id', $labId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])),

            Service::class => $this->firstWhenFilled($query, [
                'laboratory_id' => $attrs['laboratory_id'] ?? null,
                'name' => $this->normalizeCatalogText($attrs['name'] ?? null),
            ], fn ($q, $labId, $name) => $q->where('laboratory_id', $labId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])),

            ReferringDoctor::class => filled($attrs['rut'] ?? null)
                ? $query->where('rut', Persona::normalizeRut((string) $attrs['rut']))->first()
                : null,

            Insurance::class => filled($attrs['name'] ?? null)
                ? $query->when(
                    filled($attrs['laboratory_id'] ?? null),
                    fn ($q) => $q->where('laboratory_id', $attrs['laboratory_id']),
                    fn ($q) => $q->whereNull('laboratory_id')
                )->whereRaw('LOWER(TRIM(name)) = ?', [$this->normalizeCatalogText($attrs['name'])])->first()
                : null,

            InsurancePlan::class => $this->firstWhenFilled($query, [
                'insurance_id' => $attrs['insurance_id'] ?? null,
                'name' => $this->normalizeCatalogText($attrs['name'] ?? null),
            ], fn ($q, $insuranceId, $name) => $q->where('insurance_id', $insuranceId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])),

            Laboratory::class => filled($attrs['name'] ?? null) && filled($attrs['parent_id'] ?? null)
                ? $query->where('parent_id', $attrs['parent_id'])
                    ->whereRaw('LOWER(TRIM(name)) = ?', [$this->normalizeCatalogText($attrs['name'])])
                    ->first()
                : null,

            default => null,
        };
    }

    private function firstWhenFilled($query, array $fields, callable $callback): ?Model
    {
        foreach ($fields as $value) {
            if ($value === null || $value === '') {
                return null;
            }
        }

        return $callback($query, ...array_values($fields));
    }

    private function normalizeCatalogText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strtolower(trim($value));
    }

    private function catalogValuesDiffer(mixed $current, mixed $incoming): bool
    {
        if (is_array($current) || is_array($incoming)) {
            return json_encode($this->normalizeComparableValue($current))
                !== json_encode($this->normalizeComparableValue($incoming));
        }

        if (is_numeric($current) || is_numeric($incoming)) {
            return (float) $current !== (float) $incoming;
        }

        if ($current === null && $incoming === null) {
            return false;
        }

        if (is_bool($current) || is_bool($incoming)) {
            return (bool) $current !== (bool) $incoming;
        }

        return (string) $current !== (string) $incoming;
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);
            return $value;
        }

        return $value;
    }

    private function usesSoftDeletes(string $class): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($class), true);
    }

    private function resolveModelClass(string $modelKey): ?string
    {
        $map = config('cloud_sync.models', []);
        return $map[$modelKey] ?? $map[class_basename($modelKey)] ?? null;
    }

    private function filterAttributes(string $class, array $data): array
    {
        /** @var Model $instance */
        $instance = new $class();
        $fillable = $instance->getFillable();
        $filtered = Arr::only($data, array_merge($fillable, ['id']));

        foreach (['created_at', 'updated_at', 'deleted_at'] as $ts) {
            if (array_key_exists($ts, $data)) {
                $filtered[$ts] = $data[$ts];
            }
        }

        return $filtered;
    }

    private function syncPersona(array $data): ?string
    {
        $attrs = Arr::only($data, (new Persona())->getFillable());
        $rut = (string) ($data['rut'] ?? '');

        if (!empty($data['id'])) {
            $record = Persona::find($data['id']);
            if ($record) {
                $record->fill($attrs)->save();

                return $record->id;
            }

            if ($rut !== '') {
                $byRut = Persona::findByRut($rut);
                if ($byRut) {
                    $byRut->fill($attrs)->save();

                    return $byRut->id;
                }

                return $this->createPersonaOrResolveByRut(
                    array_merge(['id' => $data['id'], 'rut' => Persona::normalizeRut($rut)], $attrs),
                    $rut
                );
            }

            return $this->createPersonaOrResolveByRut(
                array_merge(['id' => $data['id']], $attrs),
                $rut
            );
        }

        if ($rut !== '') {
            return Persona::upsertByRut($rut, $attrs)->id;
        }

        return null;
    }

    private function syncAppointment(array $data): void
    {
        $studies = $data['studies'] ?? null;
        $patient = $data['patient'] ?? null;
        $laboratoryId = $data['laboratory_id'] ?? null;

        if (is_array($patient)) {
            $this->ensurePatientForAppointment($patient, $laboratoryId);
        }

        unset($data['studies'], $data['patient'], $data['machine'], $data['laboratory'], $data['supplies']);

        $attrs = $this->filterAttributes(Appointment::class, $data);
        $id = $attrs['id'] ?? $data['id'] ?? null;
        if (!$id) {
            return;
        }
        unset($attrs['id']);

        $appointment = Appointment::find($id);
        if ($appointment) {
            $appointment->fill($attrs);
            $appointment->save();
        } else {
            $appointment = Appointment::create(array_merge(['id' => $id], $attrs));
        }

        if (is_array($studies)) {
            foreach ($studies as $row) {
                if (empty($row['id'])) {
                    continue;
                }
                $studyAttrs = $this->filterAttributes(AppointmentStudy::class, $row);
                $studyId = $studyAttrs['id'] ?? $row['id'];
                unset($studyAttrs['id']);
                AppointmentStudy::updateOrCreate(
                    ['id' => $studyId],
                    array_merge($studyAttrs, ['appointment_id' => $appointment->id])
                );
            }
        }
    }

    private function syncPaciente(array $data): void
    {
        $personaId = null;
        if (!empty($data['persona']['rut'])) {
            $personaId = $this->syncPersona($data['persona'])
                ?? Persona::findByRut((string) $data['persona']['rut'])?->id;
        }
        if (!$personaId) {
            $personaId = $data['persona_id'] ?? null;
        }

        $pacienteId = $data['id'] ?? null;
        $labId = $data['laboratory_id'] ?? null;

        if (!$personaId || !$pacienteId || !$labId) {
            throw new \RuntimeException(
                'Paciente sync incompleto (persona_id, paciente_id o laboratory_id faltante).'
            );
        }

        $this->upsertPacienteRow($pacienteId, $personaId, $labId);
    }

    private function upsertPacienteRow(string $pacienteId, string $personaId, string $labId): void
    {
        $now = now();
        Paciente::query()->upsert(
            [[
                'id' => $pacienteId,
                'persona_id' => $personaId,
                'laboratory_id' => $labId,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['id'],
            ['persona_id', 'laboratory_id', 'updated_at']
        );
    }

    /**
     * Crea/actualiza persona y paciente antes de la cita (evita FK patient_id en nube).
     */
    private function ensurePatientForAppointment(array $patient, ?string $laboratoryId): void
    {
        $personaData = $patient['persona'] ?? null;
        if (!is_array($personaData) || empty($personaData['rut'])) {
            return;
        }

        $personaId = $this->syncPersona($personaData);
        if (!$personaId) {
            $persona = Persona::findByRut((string) $personaData['rut']);
            if (!$persona && filled($personaData['id'] ?? null)) {
                $persona = Persona::find($personaData['id']);
            }
            $personaId = $persona?->id;
        }
        $pacienteId = $patient['id'] ?? null;
        $labId = $patient['laboratory_id'] ?? $laboratoryId;

        if (!$personaId || !$pacienteId || !$labId) {
            throw new \RuntimeException(
                'No se pudo resolver persona/paciente para sync de cita (rut=' . ($personaData['rut'] ?? '') . ').'
            );
        }

        $this->upsertPacienteRow($pacienteId, $personaId, $labId);
    }

    private function createPersonaOrResolveByRut(array $payload, string $rut): string
    {
        try {
            return Persona::create($payload)->id;
        } catch (QueryException $e) {
            if ($rut !== '' && str_contains($e->getMessage(), 'rut_hash')) {
                $existing = Persona::findByRut($rut);
                if ($existing) {
                    return $existing->id;
                }
            }

            throw $e;
        }
    }

    private function unpackFileFields(array &$data): void
    {
        $fileCols = [
            'medical_order_path',
            'survey_path',
            'signature_path',
            'audio_path',
        ];

        foreach ($fileCols as $col) {
            $b64Key = $col . '_base64';
            if (empty($data[$b64Key])) {
                continue;
            }
            $path = $this->storeBase64File($data[$b64Key], $col);
            if ($path) {
                $data[$col] = $path;
            }
            unset($data[$b64Key]);
        }

        if (!empty($data['studies']) && is_array($data['studies'])) {
            foreach ($data['studies'] as $i => $study) {
                if (!empty($study['audio_path_base64'])) {
                    $path = $this->storeBase64File($study['audio_path_base64'], 'audio');
                    if ($path) {
                        $data['studies'][$i]['audio_path'] = $path;
                    }
                    unset($data['studies'][$i]['audio_path_base64']);
                }
            }
        }
    }

    private function storeBase64File(string $payload, string $prefix): ?string
    {
        if (!preg_match('#^data:([^;]+);base64,(.+)$#', $payload, $m)) {
            return null;
        }

        $mime = $m[1];
        $raw = base64_decode($m[2], true);
        if ($raw === false) {
            return null;
        }

        $ext = match (true) {
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
            default => 'bin',
        };

        $relative = 'cloud-sync/' . $prefix . '_' . uniqid('', true) . '.' . $ext;
        try {
            Storage::disk('public')->put($relative, $raw);
            return '/storage/' . $relative;
        } catch (\Throwable $e) {
            Log::warning('cloud sync file store failed: ' . $e->getMessage());
            return null;
        }
    }
}
