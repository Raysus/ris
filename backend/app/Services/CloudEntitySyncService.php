<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentStudy;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Model;
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
            $class::withoutEvents(function () use ($class, $action, $data) {
                if ($action === 'deleted') {
                    $id = $data['id'] ?? null;
                    if ($id) {
                        $class::where('id', $id)->delete();
                    }
                    return;
                }

                $this->unpackFileFields($data);

                if ($class === Persona::class && !empty($data['rut'])) {
                    $this->syncPersona($data);
                    return;
                }

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

                $record = $class::find($id);
                if ($record) {
                    $record->fill($attrs);
                    $record->save();
                } else {
                    $class::create(array_merge(['id' => $id], $attrs));
                }
            });
        } finally {
            self::$applying = false;
        }
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

    private function syncPersona(array $data): void
    {
        $attrs = Arr::only($data, (new Persona())->getFillable());
        Persona::upsertByRut((string) $data['rut'], $attrs);
        if (!empty($data['id'])) {
            Persona::where('id', $data['id'])->update(Arr::only($attrs, ['names', 'last_name_1', 'last_name_2', 'gender', 'birth_date', 'phone', 'address']));
        }
    }

    private function syncAppointment(array $data): void
    {
        $studies = $data['studies'] ?? null;
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
