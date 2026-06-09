<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Exam;
use App\Models\Insurance;
use App\Models\InsurancePlan;
use App\Models\Machine;
use App\Models\ReferringDoctor;
use App\Models\Service;
use App\Models\Supply;
use Illuminate\Support\Facades\DB;

class CatalogDedupeService
{
    /**
     * @return array<string, array{groups: int, removed: int, reassigned: int}>
     */
    public function dedupeAll(bool $dryRun = false): array
    {
        return [
            'referring_doctors' => $this->dedupeReferringDoctors($dryRun),
            'insurances' => $this->dedupeInsurances($dryRun),
            'exams' => $this->dedupeByLabAndName(Exam::class, $dryRun),
            'machines' => $this->dedupeByLabAndName(Machine::class, $dryRun),
            'supplies' => $this->dedupeByLabAndName(Supply::class, $dryRun),
            'services' => $this->dedupeByLabAndName(Service::class, $dryRun),
            'insurance_plans' => $this->dedupeInsurancePlans($dryRun),
        ];
    }

    /**
     * @return array{groups: int, removed: int, reassigned: int}
     */
    public function dedupeReferringDoctors(bool $dryRun = false): array
    {
        $this->normalizeReferringDoctorRuts($dryRun);

        $rows = ReferringDoctor::query()
            ->select('id', 'rut', 'created_at')
            ->orderBy('created_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = ReferringDoctor::normalizeRut($row->rut);
            if (!$key) {
                continue;
            }
            $groups[$key][] = $row;
        }

        $removed = 0;
        $reassigned = 0;
        $groupCount = 0;

        foreach ($groups as $duplicates) {
            if (count($duplicates) < 2) {
                continue;
            }

            $groupCount++;
            $keeper = $this->pickKeeper(collect($duplicates), ReferringDoctor::class);
            $duplicateIds = collect($duplicates)
                ->pluck('id')
                ->filter(fn ($id) => (string) $id !== (string) $keeper->id)
                ->values()
                ->all();

            if ($duplicateIds === []) {
                continue;
            }

            $reassigned += $this->reassignAppointments($duplicateIds, (string) $keeper->id, $dryRun);
            $removed += $this->deleteIds(ReferringDoctor::class, $duplicateIds, $dryRun);
        }

        return [
            'groups' => $groupCount,
            'removed' => $removed,
            'reassigned' => $reassigned,
        ];
    }

    /**
     * @return array{groups: int, removed: int, reassigned: int}
     */
    public function dedupeInsurances(bool $dryRun = false): array
    {
        $rows = Insurance::query()
            ->select('id', 'laboratory_id', 'name', 'created_at')
            ->orderBy('created_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = ($row->laboratory_id ?? 'null') . '|' . mb_strtolower(trim((string) $row->name));
            $groups[$key][] = $row;
        }

        return $this->dedupeSimpleGroups($groups, Insurance::class, $dryRun);
    }

    /**
     * @return array{groups: int, removed: int, reassigned: int}
     */
    public function dedupeInsurancePlans(bool $dryRun = false): array
    {
        $rows = InsurancePlan::query()
            ->select('id', 'insurance_id', 'name', 'created_at')
            ->orderBy('created_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = $row->insurance_id . '|' . mb_strtolower(trim((string) $row->name));
            $groups[$key][] = $row;
        }

        return $this->dedupeSimpleGroups($groups, InsurancePlan::class, $dryRun);
    }

    /**
     * @param  class-string  $modelClass
     * @return array{groups: int, removed: int, reassigned: int}
     */
    public function dedupeByLabAndName(string $modelClass, bool $dryRun = false): array
    {
        $rows = $modelClass::query()
            ->select('id', 'laboratory_id', 'name', 'created_at')
            ->orderBy('created_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = $row->laboratory_id . '|' . mb_strtolower(trim((string) $row->name));
            $groups[$key][] = $row;
        }

        return $this->dedupeSimpleGroups($groups, $modelClass, $dryRun);
    }

    /**
     * @param  array<string, list<object>>  $groups
     * @param  class-string  $modelClass
     * @return array{groups: int, removed: int, reassigned: int}
     */
    private function dedupeSimpleGroups(array $groups, string $modelClass, bool $dryRun): array
    {
        $removed = 0;
        $groupCount = 0;

        foreach ($groups as $duplicates) {
            if (count($duplicates) < 2) {
                continue;
            }

            $groupCount++;
            $keeper = $this->pickKeeper(collect($duplicates), $modelClass);
            $duplicateIds = collect($duplicates)
                ->pluck('id')
                ->filter(fn ($id) => (string) $id !== (string) $keeper->id)
                ->values()
                ->all();

            $removed += $this->deleteIds($modelClass, $duplicateIds, $dryRun);
        }

        return ['groups' => $groupCount, 'removed' => $removed, 'reassigned' => 0];
    }

    private function normalizeReferringDoctorRuts(bool $dryRun): void
    {
        ReferringDoctor::query()
            ->whereNotNull('rut')
            ->where('rut', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($doctors) use ($dryRun) {
                foreach ($doctors as $doctor) {
                    $normalized = ReferringDoctor::normalizeRut($doctor->rut);
                    if (!$normalized || $normalized === $doctor->rut) {
                        continue;
                    }

                    if (!$dryRun) {
                        ReferringDoctor::withoutEvents(function () use ($doctor, $normalized) {
                            $doctor->forceFill(['rut' => $normalized])->save();
                        });
                    }
                }
            });
    }

    private function pickKeeper($rows, string $modelClass)
    {
        if ($modelClass === ReferringDoctor::class) {
            $appointmentCounts = Appointment::query()
                ->select('referring_doctor_id', DB::raw('COUNT(*) as total'))
                ->whereIn('referring_doctor_id', $rows->pluck('id'))
                ->groupBy('referring_doctor_id')
                ->pluck('total', 'referring_doctor_id');

            return $rows->sort(function ($a, $b) use ($appointmentCounts) {
                $countA = (int) ($appointmentCounts[$a->id] ?? 0);
                $countB = (int) ($appointmentCounts[$b->id] ?? 0);
                if ($countA !== $countB) {
                    return $countB <=> $countA;
                }

                return $a->created_at <=> $b->created_at;
            })->first();
        }

        return $rows->sortBy(fn ($row) => $row->created_at)->first();
    }

    /**
     * @param  list<string>  $fromIds
     */
    private function reassignAppointments(array $fromIds, string $toId, bool $dryRun): int
    {
        if ($fromIds === []) {
            return 0;
        }

        $count = Appointment::query()
            ->whereIn('referring_doctor_id', $fromIds)
            ->count();

        if ($count > 0 && !$dryRun) {
            Appointment::withoutEvents(function () use ($fromIds, $toId) {
                Appointment::query()
                    ->whereIn('referring_doctor_id', $fromIds)
                    ->update(['referring_doctor_id' => $toId]);
            });
        }

        return $count;
    }

    /**
     * @param  class-string  $modelClass
     * @param  list<string>  $ids
     */
    private function deleteIds(string $modelClass, array $ids, bool $dryRun): int
    {
        if ($ids === []) {
            return 0;
        }

        if (!$dryRun) {
            $modelClass::withoutEvents(fn () => $modelClass::query()->whereIn('id', $ids)->delete());
        }

        return count($ids);
    }
}
