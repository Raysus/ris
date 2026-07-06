<?php

namespace App\Services;

use App\Models\Appointment;
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
use App\Models\User;

class CloudCatalogExportService
{
    /**
     * @return array<string, mixed>
     */
    public function export(
        ?string $laboratoryId,
        bool $includePatients = false,
        bool $includeUsers = false,
        bool $includeAppointments = false,
        ?string $appointmentsFrom = null,
        ?string $appointmentsTo = null,
    ): array {
        $labIds = $this->resolveLaboratoryScope($laboratoryId);

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'laboratory_id' => $laboratoryId,
            'laboratory_ids' => $labIds,
            'laboratories' => Laboratory::query()
                ->when($labIds !== null, fn ($q) => $q->whereIn('id', $labIds))
                ->get()
                ->toArray(),
            'referring_doctors' => ReferringDoctor::query()->orderBy('names')->get()->toArray(),
            'exams' => Exam::query()
                ->when($labIds !== null, fn ($q) => $q->whereIn('laboratory_id', $labIds))
                ->get()
                ->toArray(),
            'machines' => Machine::query()
                ->when($labIds !== null, fn ($q) => $q->whereIn('laboratory_id', $labIds))
                ->get()
                ->toArray(),
            'supplies' => Supply::query()
                ->when($labIds !== null, fn ($q) => $q->whereIn('laboratory_id', $labIds))
                ->get()
                ->toArray(),
            'report_templates' => ReportTemplate::query()
                ->when($labIds !== null, fn ($q) => $q->whereIn('laboratory_id', $labIds))
                ->get()
                ->toArray(),
            'insurances' => Insurance::query()
                ->when($labIds !== null, fn ($q) => $q->whereIn('laboratory_id', $labIds))
                ->get()
                ->toArray(),
            'insurance_plans' => InsurancePlan::query()
                ->when($labIds !== null, function ($q) use ($labIds) {
                    $q->whereHas('insurance', fn ($iq) => $iq->whereIn('laboratory_id', $labIds));
                })
                ->get()
                ->toArray(),
            'services' => Service::query()
                ->when($labIds !== null, fn ($q) => $q->whereIn('laboratory_id', $labIds))
                ->get()
                ->toArray(),
        ];

        if ($includePatients && $labIds !== null) {
            $patients = Paciente::query()
                ->with('persona')
                ->whereIn('laboratory_id', $labIds)
                ->limit(5000)
                ->get();

            $payload['pacientes'] = $patients->map(fn ($p) => array_merge(
                $p->toArray(),
                ['persona' => $p->persona?->toArray()]
            ))->values()->all();
        }

        if ($includeUsers && $labIds !== null) {
            $userIds = \Illuminate\Support\Facades\DB::table('laboratory_user')
                ->whereIn('laboratory_id', $labIds)
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();

            $users = User::query()
                ->with(['persona', 'tipoUsuario'])
                ->whereIn('id', $userIds)
                ->get();

            $payload['users'] = $users->map(function (User $user) {
                $row = $user->toArray();
                unset($row['password']);
                $row['persona'] = $user->persona?->toArray();

                return $row;
            })->values()->all();

            $payload['laboratory_users'] = \Illuminate\Support\Facades\DB::table('laboratory_user')
                ->whereIn('laboratory_id', $labIds)
                ->whereIn('user_id', $userIds)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->values()
                ->all();
        }

        if ($includeAppointments && $labIds !== null) {
            $from = $appointmentsFrom
                ? \Illuminate\Support\Carbon::parse($appointmentsFrom)->startOfDay()
                : now()->subDays(30)->startOfDay();
            $to = $appointmentsTo
                ? \Illuminate\Support\Carbon::parse($appointmentsTo)->endOfDay()
                : now()->addMonths(6)->endOfDay();

            $appointments = Appointment::query()
                ->with(['patient.persona', 'studies'])
                ->whereIn('laboratory_id', $labIds)
                ->whereBetween('start_time', [$from, $to])
                ->orderBy('start_time')
                ->limit(3000)
                ->get();

            $payload['appointments'] = $appointments
                ->map(fn (Appointment $appointment) => $this->serializeAppointmentForExport($appointment))
                ->values()
                ->all();
            $payload['appointments_range'] = [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAppointmentForExport(Appointment $appointment): array
    {
        $row = $appointment->toArray();

        if ($appointment->relationLoaded('patient') && $appointment->patient) {
            $row['patient'] = array_merge($appointment->patient->toArray(), [
                'persona' => $appointment->patient->persona?->toArray(),
            ]);
        }

        if ($appointment->relationLoaded('studies')) {
            $row['studies'] = $appointment->studies->map->toArray()->values()->all();
        }

        return $row;
    }

    /**
     * @return list<string>|null null = sin filtro (sis / todas)
     */
    private function resolveLaboratoryScope(?string $laboratoryId): ?array
    {
        if (!$laboratoryId) {
            return null;
        }

        $lab = Laboratory::with('children')->find($laboratoryId);
        if (!$lab) {
            return [$laboratoryId];
        }

        $ids = [$lab->id];
        if (is_null($lab->parent_id)) {
            $ids = array_merge($ids, $lab->children->pluck('id')->all());
        }

        return array_values(array_unique($ids));
    }
}
