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
use App\Models\SubExam;
use App\Models\Supply;
use App\Models\TipoUsuario;
use App\Models\User;
use App\Support\CloudSyncMode;
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

            if ($class === \App\Models\AppointmentLog::class) {
                $this->syncAppointmentLog($data);

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
                    if (!empty($data['persona']) && is_array($data['persona'])) {
                        $resolvedPersonaId = $this->syncPersona($data['persona']);
                        if ($resolvedPersonaId) {
                            $attrs['persona_id'] = $resolvedPersonaId;
                        }
                    }
                    $this->resolveTipoUsuarioId($data, $attrs);
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

        $attrs = $this->prepareCatalogAttributes($class, $attrs);

        foreach ($attrs as $key => $value) {
            if (in_array($key, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }
            if ($this->catalogValuesDiffer($record->getAttribute($key), $value, $key, $class)) {
                return false;
            }
        }

        return true;
    }

    private function upsertCatalogEntity(string $class, string $incomingId, array $attrs): void
    {
        $attrs = $this->prepareCatalogAttributes($class, $attrs);
        $record = $this->resolveInboundCatalogRecord($class, $incomingId, $attrs);

        if ($record) {
            if ($this->usesSoftDeletes($class) && $record->trashed()) {
                $record->restore();
            }
            if ($this->catalogEntityIsUnchanged($record, $attrs, $class)) {
                return;
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

            if (CloudSyncMode::acceptsInbound() && $class === Machine::class) {
                $this->dedupeInboundMachines((string) $record->id, $attrs);
            }

            return;
        }

        $this->saveWithIncomingId($class, $incomingId, $attrs);

        if (CloudSyncMode::acceptsInbound() && $class === Machine::class) {
            $this->dedupeInboundMachines($incomingId, $attrs);
        }
    }

    private function dedupeInboundMachines(string $keepId, array $attrs): void
    {
        $labId = $attrs['laboratory_id'] ?? null;
        $aeTitle = $attrs['ae_title'] ?? null;
        if (!$labId || !$aeTitle) {
            return;
        }

        $usedIds = Appointment::query()
            ->where('laboratory_id', $labId)
            ->whereNotNull('machine_id')
            ->pluck('machine_id');

        Machine::query()
            ->where('laboratory_id', $labId)
            ->where('ae_title', $aeTitle)
            ->where('id', '!=', $keepId)
            ->when($usedIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $usedIds))
            ->delete();
    }

    /**
     * Push inbound (nube): prioriza UUID del laboratorio; ReferringDoctor también deduplica por RUT.
     */
    private function resolveInboundCatalogRecord(string $class, string $incomingId, array $attrs): ?Model
    {
        if (CloudSyncMode::acceptsInbound()) {
            $record = $this->findCatalogRecordById($class, $incomingId);
            if ($record) {
                return $record;
            }

            return $this->findCatalogByBusinessKey($class, $attrs);
        }

        return $this->findCatalogRecord($class, $attrs, $incomingId);
    }

    private function catalogEntityIsUnchanged(Model $record, array $attrs, string $class): bool
    {
        foreach ($attrs as $key => $value) {
            if (in_array($key, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }
            if ($this->catalogValuesDiffer($record->getAttribute($key), $value, $key, $class)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Crea o actualiza respetando el UUID entrante (HasUuids no debe regenerarlo).
     *
     * @param  class-string<Model>  $class
     */
    private function saveWithIncomingId(string $class, string $incomingId, array $attrs): Model
    {
        $attrs = $this->prepareCatalogAttributes($class, $attrs);
        $query = $this->usesSoftDeletes($class)
            ? $class::withTrashed()
            : $class::query();

        /** @var Model|null $record */
        $record = (clone $query)->find($incomingId);
        if ($record) {
            if ($this->usesSoftDeletes($class) && $record->trashed()) {
                $record->restore();
            }
        } else {
            $record = new $class();
            $record->setAttribute($record->getKeyName(), $incomingId);
        }

        $record->fill($attrs);
        $record->save();

        return $record;
    }

    private function findCatalogRecordById(string $class, string $incomingId): ?Model
    {
        $query = $this->usesSoftDeletes($class)
            ? $class::withTrashed()
            : $class::query();

        return (clone $query)->find($incomingId);
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

            ReferringDoctor::class => ReferringDoctor::findByRut($attrs['rut'] ?? null),

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

        $result = $callback($query, ...array_values($fields));

        if ($result instanceof Model) {
            return $result;
        }

        return $result?->first();
    }

    private function normalizeCatalogText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strtolower(trim($value));
    }

    private function prepareCatalogAttributes(string $class, array $attrs): array
    {
        if ($class === ReferringDoctor::class && filled($attrs['rut'] ?? null)) {
            $attrs['rut'] = ReferringDoctor::normalizeRut((string) $attrs['rut']);
        }

        if ($class === Insurance::class && ! filled($attrs['code'] ?? null) && filled($attrs['name'] ?? null)) {
            $attrs['code'] = strtoupper(substr(preg_replace('/[^a-z0-9]+/i', '_', (string) $attrs['name']), 0, 32));
        }

        return $attrs;
    }

    private function catalogValuesDiffer(mixed $current, mixed $incoming, ?string $field = null, ?string $class = null): bool
    {
        if ($field === 'rut' && $class === ReferringDoctor::class) {
            return ReferringDoctor::normalizeRut((string) $current) !== ReferringDoctor::normalizeRut((string) $incoming);
        }

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
        $supplies = $data['supplies'] ?? null;
        $patient = $data['patient'] ?? null;
        $laboratoryId = $data['laboratory_id'] ?? null;

        if (is_array($patient)) {
            $this->ensurePatientForAppointment($patient, $laboratoryId);
        }

        unset($data['studies'], $data['patient'], $data['machine'], $data['laboratory'], $data['supplies']);

        $attrs = $this->filterAttributes(Appointment::class, $data);
        $id = (string) ($attrs['id'] ?? $data['id'] ?? '');
        if ($id === '') {
            return;
        }
        unset($attrs['id']);

        $this->sanitizeAppointmentForeignKeys($attrs);

        $appointment = Appointment::withTrashed()->find($id);

        if ($appointment) {
            if ($appointment->trashed()) {
                $appointment->restore();
            }
            if (!$this->catalogEntityIsUnchanged($appointment, $attrs, Appointment::class)) {
                $appointment->fill($attrs);
                $appointment->save();
            }
        } else {
            $appointment = $this->saveWithIncomingId(Appointment::class, $id, $attrs);
        }

        if (is_array($studies)) {
            $syncedStudyIds = [];
            $appointmentMachineId = $appointment->machine_id ?? ($data['machine_id'] ?? null);
            foreach ($studies as $row) {
                if (empty($row['id'])) {
                    continue;
                }
                try {
                    $this->ensureReferencedCatalogForStudy($row);
                    $studyAttrs = $this->filterAttributes(AppointmentStudy::class, $row);
                    $studyId = (string) ($studyAttrs['id'] ?? $row['id']);
                    unset($studyAttrs['id']);
                    $studyAttrs['appointment_id'] = $appointment->id;
                    $this->sanitizeAppointmentStudyForeignKeys($studyAttrs, $appointmentMachineId);

                    $existingStudy = AppointmentStudy::find($studyId);
                    if ($existingStudy) {
                        if (!$this->catalogEntityIsUnchanged($existingStudy, $studyAttrs, AppointmentStudy::class)) {
                            $existingStudy->fill($studyAttrs);
                            $existingStudy->save();
                        }
                    } else {
                        $this->saveWithIncomingId(AppointmentStudy::class, $studyId, $studyAttrs);
                    }
                    $syncedStudyIds[] = $studyId;
                } catch (\Throwable $e) {
                    Log::warning('cloud sync appointment study ' . ($row['id'] ?? '') . ': ' . $e->getMessage(), [
                        'appointment_id' => $appointment->id,
                    ]);
                }
            }

            if ($syncedStudyIds !== []) {
                AppointmentStudy::query()
                    ->where('appointment_id', $appointment->id)
                    ->whereNotIn('id', $syncedStudyIds)
                    ->delete();
            }
        }

        if (is_array($supplies)) {
            $this->syncAppointmentSupplies($appointment, $supplies);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $supplies
     */
    private function syncAppointmentSupplies(Appointment $appointment, array $supplies): void
    {
        $syncMap = [];

        foreach ($supplies as $row) {
            if (!is_array($row)) {
                continue;
            }

            $supplyId = (string) ($row['supply_id'] ?? $row['id'] ?? '');
            if ($supplyId === '' || !Supply::query()->whereKey($supplyId)->exists()) {
                Log::warning('cloud sync appointment supply omitido (no existe en destino)', [
                    'appointment_id' => $appointment->id,
                    'supply_id' => $supplyId,
                ]);
                continue;
            }

            $syncMap[$supplyId] = [
                'quantity' => (int) ($row['pivot']['quantity'] ?? $row['quantity'] ?? 1),
                'price_charged' => (float) ($row['pivot']['price_charged'] ?? $row['price_charged'] ?? $row['price'] ?? 0),
            ];
        }

        $appointment->supplies()->sync($syncMap);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function ensureReferencedCatalogForStudy(array $row): void
    {
        if (!empty($row['exam']) && is_array($row['exam']) && !empty($row['exam']['id'])) {
            if (!$this->catalogRowIsUnchanged(Exam::class, $row['exam'])) {
                $this->apply('Exam', 'updated', $row['exam']);
            }
        }

        if (!empty($row['sub_exam']) && is_array($row['sub_exam']) && !empty($row['sub_exam']['id'])) {
            if (!$this->catalogRowIsUnchanged(SubExam::class, $row['sub_exam'])) {
                $this->apply('SubExam', 'updated', $row['sub_exam']);
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
    /**
     * Omite FK opcionales que aún no existen en el lab destino (catálogo desalineado).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function sanitizeAppointmentForeignKeys(array &$attrs): void
    {
        $optional = [
            'insurance_id' => Insurance::class,
            'insurance_plan_id' => InsurancePlan::class,
            'referring_doctor_id' => ReferringDoctor::class,
            'destination_doctor_id' => User::class,
        ];

        foreach ($optional as $column => $class) {
            $fk = $attrs[$column] ?? null;
            if (!$fk || $class::query()->whereKey($fk)->exists()) {
                continue;
            }

            Log::warning('cloud sync appointment: FK opcional omitido (no existe en destino)', [
                'column' => $column,
                'id' => $fk,
            ]);
            $attrs[$column] = null;
        }

        $machineId = $attrs['machine_id'] ?? null;
        if ($machineId && !Machine::query()->whereKey($machineId)->exists()) {
            throw new \RuntimeException(
                'machine_id de la cita no existe en el laboratorio destino (' . $machineId . '). Sincronice el catálogo de salas.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function sanitizeAppointmentStudyForeignKeys(array &$attrs, ?string $fallbackMachineId = null): void
    {
        $machineId = $attrs['machine_id'] ?? null;
        if ($machineId && !Machine::query()->whereKey($machineId)->exists()) {
            if ($fallbackMachineId && Machine::query()->whereKey($fallbackMachineId)->exists()) {
                Log::warning('cloud sync appointment study: machine_id reemplazado por sala de la cita', [
                    'study_machine_id' => $machineId,
                    'appointment_machine_id' => $fallbackMachineId,
                ]);
                $attrs['machine_id'] = $fallbackMachineId;
            }
        }

        $optional = [
            'exam_id' => Exam::class,
            'machine_id' => Machine::class,
            'radiologist_user_id' => User::class,
            'sub_exam_id' => SubExam::class,
        ];

        foreach ($optional as $column => $class) {
            $fk = $attrs[$column] ?? null;
            if (!$fk || $class::query()->whereKey($fk)->exists()) {
                continue;
            }

            Log::warning('cloud sync appointment study: FK opcional omitido', [
                'column' => $column,
                'id' => $fk,
            ]);
            $attrs[$column] = null;
        }
    }

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
        $incomingId = (string) ($payload['id'] ?? '');
        unset($payload['id']);

        try {
            if ($incomingId !== '') {
                return $this->saveWithIncomingId(Persona::class, $incomingId, $payload)->id;
            }

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

    private function syncAppointmentLog(array $data): void
    {
        $appointmentId = $data['appointment_id'] ?? null;
        $userId = $data['user_id'] ?? null;
        $logId = $data['id'] ?? null;

        if (!$logId || !$appointmentId) {
            return;
        }

        if (!Appointment::find($appointmentId)) {
            Log::info('cloud sync: appointment_log omitido (cita inexistente)', [
                'log_id' => $logId,
                'appointment_id' => $appointmentId,
            ]);

            return;
        }

        if ($userId && !User::find($userId)) {
            Log::info('cloud sync: appointment_log omitido (usuario inexistente)', [
                'log_id' => $logId,
                'user_id' => $userId,
            ]);

            return;
        }

        $attrs = $this->filterAttributes(\App\Models\AppointmentLog::class, $data);
        unset($attrs['id']);
        $this->upsertCatalogEntity(\App\Models\AppointmentLog::class, (string) $logId, $attrs);
    }

    /**
     * Los laboratorios locales pueden traer UUID distinto para el mismo rol (ej. secretaria).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attrs
     */
    private function resolveTipoUsuarioId(array $data, array &$attrs): void
    {
        $incomingTipoId = $attrs['tipo_usuario_id'] ?? null;
        if ($incomingTipoId && TipoUsuario::find($incomingTipoId)) {
            return;
        }

        $tipoName = $data['tipo_usuario']['name'] ?? null;
        if (!$tipoName) {
            return;
        }

        $match = TipoUsuario::where('name', $tipoName)->first();
        if ($match) {
            $attrs['tipo_usuario_id'] = $match->id;
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
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'wav') => 'wav',
            default => 'bin',
        };

        $folder = match ($prefix) {
            'medical_order_path', 'survey_path' => 'documents',
            'audio', 'audio_path' => 'audios_dictados',
            default => 'cloud-sync',
        };
        $name = match ($prefix) {
            'medical_order_path' => 'orden',
            'survey_path' => 'encuesta',
            'audio', 'audio_path' => 'dictado',
            default => $prefix,
        };

        $relative = $folder . '/' . $name . '_' . uniqid('', true) . '.' . $ext;
        try {
            Storage::disk('public')->put($relative, $raw);
            return $relative;
        } catch (\Throwable $e) {
            Log::warning('cloud sync file store failed: ' . $e->getMessage());
            return null;
        }
    }
}
