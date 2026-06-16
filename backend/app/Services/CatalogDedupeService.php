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
use Illuminate\Support\Facades\Schema;

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

            $reassigned += $this->reassignColumn(
                'appointments',
                'referring_doctor_id',
                $duplicateIds,
                (string) $keeper->id,
                $dryRun
            );
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
        $select = ['id', 'laboratory_id', 'name', 'created_at'];
        if ($modelClass === Exam::class) {
            $select[] = 'group_code';
        }

        $rows = $modelClass::query()
            ->select($select)
            ->orderBy('created_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = ($row->laboratory_id ?? '') . '|' . mb_strtolower(trim((string) $row->name));
            if ($modelClass === Exam::class) {
                $key .= '|' . strtoupper(trim((string) ($row->group_code ?? '')));
            }
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
        $reassigned = 0;

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

            if ($modelClass === Insurance::class) {
                $reassigned += $this->reassignColumn('appointments', 'insurance_id', $duplicateIds, (string) $keeper->id, $dryRun);
                $reassigned += $this->reassignColumn('insurance_plans', 'insurance_id', $duplicateIds, (string) $keeper->id, $dryRun);
            } elseif ($modelClass === InsurancePlan::class) {
                $reassigned += $this->reassignColumn('appointments', 'insurance_plan_id', $duplicateIds, (string) $keeper->id, $dryRun);
                $reassigned += $this->reassignColumn('tariffs', 'insurance_plan_id', $duplicateIds, (string) $keeper->id, $dryRun);
            } elseif ($modelClass === Exam::class) {
                $reassigned += $this->reassignColumn('appointment_studies', 'exam_id', $duplicateIds, (string) $keeper->id, $dryRun);
                $reassigned += $this->reassignColumn('appointment_studies', 'sub_exam_id', $duplicateIds, (string) $keeper->id, $dryRun);
                $reassigned += $this->reassignColumn('tariffs', 'exam_id', $duplicateIds, (string) $keeper->id, $dryRun);
            } elseif ($modelClass === Machine::class) {
                $reassigned += $this->reassignColumn('appointments', 'machine_id', $duplicateIds, (string) $keeper->id, $dryRun);
                $reassigned += $this->reassignColumn('appointment_studies', 'machine_id', $duplicateIds, (string) $keeper->id, $dryRun);
            } elseif ($modelClass === Supply::class) {
                $reassigned += $this->reassignColumn('appointment_supplies', 'supply_id', $duplicateIds, (string) $keeper->id, $dryRun);
                $reassigned += $this->reassignColumn('supply_pack_items', 'supply_id', $duplicateIds, (string) $keeper->id, $dryRun);
            }

            $removed += $this->deleteIds($modelClass, $duplicateIds, $dryRun);
        }

        return ['groups' => $groupCount, 'removed' => $removed, 'reassigned' => $reassigned];
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

        if ($modelClass === Exam::class) {
            $studyCounts = DB::table('appointment_studies')
                ->select('exam_id', DB::raw('COUNT(*) as total'))
                ->whereIn('exam_id', $rows->pluck('id'))
                ->groupBy('exam_id')
                ->pluck('total', 'exam_id');

            return $rows->sort(function ($a, $b) use ($studyCounts) {
                $countA = (int) ($studyCounts[$a->id] ?? 0);
                $countB = (int) ($studyCounts[$b->id] ?? 0);
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
    private function reassignColumn(string $table, string $column, array $fromIds, string $toId, bool $dryRun): int
    {
        if ($fromIds === [] || !Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return 0;
        }

        $count = (int) DB::table($table)->whereIn($column, $fromIds)->count();

        if ($count > 0 && !$dryRun) {
            DB::table($table)->whereIn($column, $fromIds)->update([$column => $toId]);
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
