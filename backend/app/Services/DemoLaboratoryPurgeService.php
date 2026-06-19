<?php

namespace App\Services;

use App\Models\Laboratory;
use App\Support\DemoLaboratoryNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DemoLaboratoryPurgeService
{
    /**
     * @return array{labs: list<string>, appointments: int, patients: int}
     */
    public function purge(bool $dryRun = false): array
    {
        Schema::disableForeignKeyConstraints();

        try {
            $stats = ['labs' => [], 'appointments' => 0, 'patients' => 0, 'demo_accessions' => 0];

            $demoLabIds = Laboratory::query()
                ->whereIn('name', DemoLaboratoryNames::LABORATORY_NAMES)
                ->orderByRaw('CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END')
                ->pluck('id')
                ->all();

            foreach ($demoLabIds as $labId) {
                $name = Laboratory::query()->whereKey($labId)->value('name') ?? $labId;
                if ($dryRun) {
                    $stats['labs'][] = (string) $name;
                    continue;
                }
                $stats['appointments'] += $this->purgeAppointmentsForLab($labId);
                $stats['appointments'] += $this->purgeAppointmentsReferencingLabCatalog($labId);
                $stats['patients'] += $this->purgePatientsForLab($labId);
                $this->purgeCatalogForLab($labId);
                DB::table('laboratory_user')->where('laboratory_id', $labId)->delete();
                Laboratory::query()->whereKey($labId)->delete();
                $stats['labs'][] = (string) $name;
            }

            if (!$dryRun) {
                $stats['demo_accessions'] = $this->purgeDemoAccessionAppointments();
                $stats['appointments'] += $this->purgeDemoReferringDoctors();
            }

            return $stats;
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function purgeAppointmentsForLab(string $labId): int
    {
        $appointmentIds = DB::table('appointments')->where('laboratory_id', $labId)->pluck('id');
        if ($appointmentIds->isEmpty()) {
            return 0;
        }

        $this->deleteAppointmentGraph($appointmentIds);

        return $appointmentIds->count();
    }

    private function purgeDemoAccessionAppointments(): int
    {
        $appointmentIds = DB::table('appointments')
            ->where('accession_number', 'like', 'DEMO-%')
            ->pluck('id');

        if ($appointmentIds->isEmpty()) {
            return 0;
        }

        $this->deleteAppointmentGraph($appointmentIds);

        return $appointmentIds->count();
    }

    private function deleteAppointmentGraph($appointmentIds): void
    {
        $studyIds = DB::table('appointment_studies')->whereIn('appointment_id', $appointmentIds)->pluck('id');
        if ($studyIds->isNotEmpty()) {
            DB::table('medical_reports')->whereIn('appointment_study_id', $studyIds)->delete();
            DB::table('appointment_studies')->whereIn('id', $studyIds)->delete();
        }

        DB::table('appointment_supplies')->whereIn('appointment_id', $appointmentIds)->delete();
        DB::table('appointment_logs')->whereIn('appointment_id', $appointmentIds)->delete();
        DB::table('appointment_deliveries')->whereIn('appointment_id', $appointmentIds)->delete();
        DB::table('payments')->whereIn('appointment_id', $appointmentIds)->delete();
        DB::table('appointments')->whereIn('id', $appointmentIds)->delete();
    }

    private function purgeAppointmentsReferencingLabCatalog(string $labId): int
    {
        $planIds = DB::table('insurance_plans')->where('laboratory_id', $labId)->pluck('id');
        $machineIds = DB::table('machines')->where('laboratory_id', $labId)->pluck('id');
        $examIds = DB::table('exams')->where('laboratory_id', $labId)->pluck('id');

        $appointmentIds = collect();

        if ($planIds->isNotEmpty()) {
            $appointmentIds = $appointmentIds->merge(
                DB::table('appointments')->whereIn('insurance_plan_id', $planIds)->pluck('id')
            );
        }

        if ($machineIds->isNotEmpty()) {
            $appointmentIds = $appointmentIds->merge(
                DB::table('appointments')->whereIn('machine_id', $machineIds)->pluck('id')
            );
        }

        if ($examIds->isNotEmpty()) {
            $appointmentIds = $appointmentIds->merge(
                DB::table('appointment_studies')->whereIn('exam_id', $examIds)->pluck('appointment_id')
            );
        }

        $appointmentIds = $appointmentIds->unique()->filter()->values();
        if ($appointmentIds->isEmpty()) {
            return 0;
        }

        $this->deleteAppointmentGraph($appointmentIds);

        return $appointmentIds->count();
    }

    private function purgeDemoReferringDoctors(): int
    {
        $doctorIds = DB::table('referring_doctors')
            ->where('email', 'medico.demo@healthticloud.cl')
            ->pluck('id');

        if ($doctorIds->isEmpty()) {
            return 0;
        }

        $appointmentIds = DB::table('appointments')
            ->whereIn('referring_doctor_id', $doctorIds)
            ->pluck('id');

        if ($appointmentIds->isNotEmpty()) {
            $this->deleteAppointmentGraph($appointmentIds);
        }

        DB::table('referring_doctors')->whereIn('id', $doctorIds)->delete();

        return $appointmentIds->count();
    }

    private function purgePatientsForLab(string $labId): int
    {
        $count = (int) DB::table('patients')->where('laboratory_id', $labId)->count();
        DB::table('patients')->where('laboratory_id', $labId)->delete();

        return $count;
    }

    private function purgeCatalogForLab(string $labId): void
    {
        DB::table('machines')->where('laboratory_id', $labId)->delete();
        DB::table('exams')->where('laboratory_id', $labId)->delete();
        DB::table('supplies')->where('laboratory_id', $labId)->delete();
        DB::table('supply_packs')->where('laboratory_id', $labId)->delete();
        DB::table('insurance_plans')->where('laboratory_id', $labId)->delete();
        DB::table('services')->where('laboratory_id', $labId)->delete();
        DB::table('pacs_servers')->where('laboratory_id', $labId)->delete();
        DB::table('report_templates')->where('laboratory_id', $labId)->delete();
    }
}
