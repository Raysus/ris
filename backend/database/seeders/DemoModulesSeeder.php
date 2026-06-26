<?php

namespace Database\Seeders;

use App\Models\Persona;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos operativos de demostración para todos los módulos del RIS.
 * Laboratorios demo: RIS PRO (1), Sucursal Sur (4), Dental (9–10), Vet (11–12).
 * No modifica sedes SIRESA (5–8). Elimina IMEX si existiera.
 */
class DemoModulesSeeder extends Seeder
{
    private const DEMO_LABS = [1, 4, 9, 10, 11, 12];

    public function run(): void
    {
        \Schema::disableForeignKeyConstraints();

        $this->removeImexLaboratory();
        $this->ensureDemoLaboratoriesExist();
        $this->seedDemoOperationalData();

        \Schema::enableForeignKeyConstraints();
    }

    private function uuid(string $table, string|int $oldId): string
    {
        $hash = md5($table . '_' . $oldId);

        return substr($hash, 0, 8) . '-' .
            substr($hash, 8, 4) . '-' .
            substr($hash, 12, 4) . '-' .
            substr($hash, 16, 4) . '-' .
            substr($hash, 20, 12);
    }

    private function purgeDemoAppointments(): void
    {
        $appointmentIds = DB::table('appointments')->where('accession_number', 'like', 'DEMO-%')->pluck('id');
        if ($appointmentIds->isEmpty()) {
            return;
        }

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

        foreach (self::DEMO_LABS as $labOld) {
            for ($i = 0; $i < 12; $i++) {
                $slot = "demo_{$labOld}_{$i}";
                DB::table('patients')->where('id', $this->uuid('patients', $slot))->delete();
                Persona::query()->where('id', $this->uuid('personas', $slot))->forceDelete();
            }
        }
    }

    private function removeImexLaboratory(): void
    {
        $imex = DB::table('laboratories')->where('name', 'like', 'IMEX%')->first();
        if (!$imex) {
            return;
        }

        $labId = $imex->id;

        $appointmentIds = DB::table('appointments')->where('laboratory_id', $labId)->pluck('id');
        if ($appointmentIds->isNotEmpty()) {
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

        DB::table('patients')->where('laboratory_id', $labId)->delete();
        DB::table('machines')->where('laboratory_id', $labId)->delete();
        DB::table('exams')->where('laboratory_id', $labId)->delete();
        DB::table('supplies')->where('laboratory_id', $labId)->delete();
        DB::table('supply_packs')->where('laboratory_id', $labId)->delete();
        DB::table('insurance_plans')->where('laboratory_id', $labId)->delete();
        DB::table('services')->where('laboratory_id', $labId)->delete();
        DB::table('pacs_servers')->where('laboratory_id', $labId)->delete();
        DB::table('report_templates')->where('laboratory_id', $labId)->delete();
        DB::table('laboratory_user')->where('laboratory_id', $labId)->delete();
        DB::table('laboratories')->where('id', $labId)->delete();

        $this->command?->info('IMEX eliminado de la base de datos.');
    }

    private function ensureDemoLaboratoriesExist(): void
    {
        $now = Carbon::now();
        $labs = [
            [1, 1, null, 'Centro de Diagnóstico RIS PRO', 'Manuel Montt 942', 'Temuco'],
            [4, 1, null, 'Sucursal Sur', 'Av. Demo Sur 100', 'Temuco'],
            [9, 3, null, 'Dental Demo — CBCT Temuco', 'Dinamarca 661', 'Temuco'],
            [10, 3, 9, 'Dental Demo — Sucursal Centro', 'Centro 200', 'Temuco'],
            [11, 2, null, 'Veterinaria Demo Sur', 'Victoria 300', 'Victoria'],
            [12, 2, null, 'Veterinaria Demo — Urgencias 24h', 'Urgencias 24', 'Temuco'],
        ];

        foreach ($labs as $l) {
            $id = $this->uuid('laboratories', $l[0]);
            if (DB::table('laboratories')->where('id', $id)->exists()) {
                continue;
            }

            DB::table('laboratories')->insert([
                'id' => $id,
                'laboratory_type_id' => $this->uuid('laboratory_types', $l[1]),
                'parent_id' => $l[2] ? $this->uuid('laboratories', $l[2]) : null,
                'name' => $l[3],
                'address' => $l[4],
                'city' => $l[5],
                'phone' => '(45) 000 0000',
                'settings' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::DEMO_LABS as $labOld) {
            $labId = $this->uuid('laboratories', $labOld);
            foreach ([1, 2, 3] as $userOld) {
                DB::table('laboratory_user')->insertOrIgnore([
                    'id' => $this->uuid('laboratory_user', 'demo_u' . $userOld . '_l' . $labOld),
                    'laboratory_id' => $labId,
                    'user_id' => $this->uuid('users', $userOld),
                    'is_primary' => $labOld === 1 && $userOld === 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedDemoOperationalData(): void
    {
        if (DB::table('appointments')->where('accession_number', 'like', 'DEMO-%')->exists()) {
            $this->purgeDemoAppointments();
            $this->command?->warn('Demos DEMO-* previos eliminados; recreando escenarios.');
        }

        $now = Carbon::now();
        $userAdmin = $this->uuid('users', 1);
        $userRad = $this->uuid('users', 2);
        $userTec = $this->uuid('users', 3);
        $insuranceParticular = $this->uuid('insurances', 3);
        $insuranceFonasa = $this->uuid('insurances', 4);

        $refDoctorId = $this->uuid('referring_doctors', 'demo_ref_1');
        DB::table('referring_doctors')->insertOrIgnore([
            [
                'id' => $refDoctorId,
                'rut' => '11.111.111-1',
                'names' => 'Carlos',
                'last_name_1' => 'Médico',
                'last_name_2' => 'Demo',
                'phone' => '+56911111111',
                'email' => 'medico.demo@healthticloud.cl',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        foreach (self::DEMO_LABS as $labOld) {
            $labId = $this->uuid('laboratories', $labOld);
            $planId = $this->resolveInsurancePlanId($labOld, $insuranceParticular);

            DB::table('services')->insertOrIgnore([
                [
                    'id' => $this->uuid('services', 'demo_svc_' . $labOld),
                    'laboratory_id' => $labId,
                    'name' => 'Informe urgente',
                    'description' => 'Entrega prioritaria de resultados (demo)',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            DB::table('pacs_servers')->insertOrIgnore([
                [
                    'id' => $this->uuid('pacs_servers', 'demo_pacs_' . $labOld),
                    'laboratory_id' => $labId,
                    'name' => 'Orthanc Demo',
                    'ae_title' => 'ORTHANC_DEMO',
                    'ip_address' => '127.0.0.1',
                    'port' => '8042',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            if ($labOld === 1) {
                DB::table('report_templates')->insertOrIgnore([
                    [
                        'id' => $this->uuid('report_templates', 'demo_tpl_ct'),
                        'user_id' => $userRad,
                        'laboratory_id' => $labId,
                        'group_code' => 'CT',
                        'title' => 'TC cerebral — plantilla demo',
                        'content' => '<p>Parénquima cerebral de densidad y morfología conservadas. Línea media centrada. Cisternas basales libres.</p>',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ]);
            }

            $this->ensureDemoCatalog($labOld, $now);
            $machineId = $this->resolveMachineId($labOld);
            $examId = $this->resolveExamId($labOld);

            foreach ($this->statusScenarios() as $idx => $scenario) {
                $this->createDemoAppointment(
                    $labOld,
                    $idx,
                    $scenario,
                    $labId,
                    $machineId,
                    $examId,
                    $planId,
                    $insuranceFonasa,
                    $refDoctorId,
                    $userAdmin,
                    $userRad,
                    $userTec,
                    $now
                );
            }
        }

        $this->command?->info('Demos operativos creados en laboratorios 1, 4, 9, 10, 11 y 12.');
    }

    private function ensureDemoCatalog(int $labOld, Carbon $now): void
    {
        $labId = $this->uuid('laboratories', $labOld);

        if (!DB::table('machines')->where('laboratory_id', $labId)->exists()) {
            DB::table('machines')->insert([
                'id' => $this->uuid('machines', 'auto_' . $labOld),
                'laboratory_id' => $labId,
                'name' => 'Sala demo auto',
                'group' => in_array($labOld, [9, 10], true) ? 'CBCT' : (in_array($labOld, [11, 12], true) ? 'RX' : 'RX'),
                'ip_address' => '127.0.0.1',
                'ae_title' => 'DEMO_AE_' . $labOld,
                'is_active' => true,
                'event_color' => '#a894c4',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (!DB::table('exams')->where('laboratory_id', $labId)->exists()) {
            DB::table('exams')->insert([
                'id' => $this->uuid('exams', 'auto_' . $labOld),
                'laboratory_id' => $labId,
                'group_code' => 'DEMO',
                'name' => 'Examen demostración',
                'fonasa_code' => 'DEMO-EX-' . $labOld,
                'price' => 45000,
                'estimated_duration' => 20,
                'sub_exams' => '[]',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (!DB::table('insurance_plans')->where('laboratory_id', $labId)->exists()) {
            DB::table('insurance_plans')->insert([
                'id' => $this->uuid('insurance_plans', 'auto_plan_' . $labOld),
                'insurance_id' => $this->uuid('insurances', 3),
                'name' => 'Particular sin copago',
                'percentage' => 0,
                'laboratory_id' => $labId,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function resolveInsurancePlanId(int $labOld, string $insuranceParticular): string
    {
        $candidates = in_array($labOld, [9, 10, 11, 12], true)
            ? [$this->uuid('insurance_plans', 'demo_part_' . $labOld), $this->uuid('insurance_plans', 'auto_plan_' . $labOld)]
            : [$this->uuid('insurance_plans', 1), $this->uuid('insurance_plans', 'demo_part_4'), $this->uuid('insurance_plans', 'auto_plan_' . $labOld)];

        foreach ($candidates as $id) {
            if (DB::table('insurance_plans')->where('id', $id)->exists()) {
                return $id;
            }
        }

        $labId = $this->uuid('laboratories', $labOld);

        return DB::table('insurance_plans')->where('laboratory_id', $labId)->value('id')
            ?? $this->uuid('insurance_plans', 1);
    }

    private function resolveMachineId(int $labOld): string
    {
        $preferred = match ($labOld) {
            1 => $this->uuid('machines', 8),
            4 => $this->uuid('machines', 237),
            9 => $this->uuid('machines', 301),
            10 => $this->uuid('machines', 302),
            11 => $this->uuid('machines', 401),
            12 => $this->uuid('machines', 402),
            default => $this->uuid('machines', 8),
        };

        return $this->resolveCatalogId('machines', $preferred, $labOld);
    }

    private function resolveExamId(int $labOld): string
    {
        $preferred = match ($labOld) {
            1 => $this->uuid('exams', 63),
            4 => $this->uuid('exams', 601),
            9 => $this->uuid('exams', 201),
            10 => $this->uuid('exams', 204),
            11 => $this->uuid('exams', 501),
            12 => $this->uuid('exams', 504),
            default => $this->uuid('exams', 5),
        };

        return $this->resolveCatalogId('exams', $preferred, $labOld);
    }

    private function resolveCatalogId(string $table, string $preferredId, int $labOld): string
    {
        if (DB::table($table)->where('id', $preferredId)->exists()) {
            return $preferredId;
        }

        $labId = $this->uuid('laboratories', $labOld);
        $fallback = DB::table($table)
            ->where('laboratory_id', $labId)
            ->where('is_active', true)
            ->orderBy('name')
            ->value('id');

        if (!$fallback) {
            throw new \RuntimeException("Sin registros en {$table} para laboratorio demo {$labOld}. Ejecute DatabaseSeeder primero.");
        }

        return $fallback;
    }

    private function statusScenarios(): array
    {
        return [
            ['key' => 'agenda', 'status' => 'pre-agendado', 'study_status' => 'espera', 'day_offset' => 3, 'hour' => 9],
            ['key' => 'agenda-conf', 'status' => 'confirmado', 'study_status' => 'espera', 'day_offset' => 2, 'hour' => 10],
            ['key' => 'worklist', 'status' => 'dicom_enviado', 'study_status' => 'espera', 'day_offset' => 1, 'hour' => 11, 'images_received' => true],
            ['key' => 'atencion', 'status' => 'en_atencion', 'study_status' => 'espera', 'day_offset' => 0, 'hour' => 12],
            ['key' => 'radiologo', 'status' => 'pendiente_radiologo', 'study_status' => 'espera', 'day_offset' => -1, 'hour' => 14],
            ['key' => 'informe', 'status' => 'en_informe', 'study_status' => 'en_informe', 'day_offset' => -1, 'hour' => 15, 'report' => 'Borrador de informe radiológico (demo).'],
            ['key' => 'transcripcion', 'status' => 'en_transcripcion', 'study_status' => 'en_transcripcion', 'day_offset' => -2, 'hour' => 16, 'report' => 'Audio dictado pendiente de transcripción.'],
            ['key' => 'validacion', 'status' => 'para_firma', 'study_status' => 'para_firma', 'day_offset' => -3, 'hour' => 17, 'report' => 'Informe transcrito listo para validación del radiólogo.'],
            ['key' => 'entrega', 'status' => 'entregable', 'study_status' => 'entregable', 'day_offset' => -4, 'hour' => 18, 'report' => 'Informe firmado. Paciente puede retirar resultados.'],
            ['key' => 'entregado', 'status' => 'entregado', 'study_status' => 'entregado', 'day_offset' => -5, 'hour' => 9, 'report' => 'Informe entregado al paciente.', 'delivered' => true],
        ];
    }

    private function createDemoAppointment(
        int $labOld,
        int $scenarioIdx,
        array $scenario,
        string $labId,
        string $machineId,
        string $examId,
        string $planId,
        string $insuranceFonasa,
        string $refDoctorId,
        string $userAdmin,
        string $userRad,
        string $userTec,
        Carbon $now
    ): void {
        $personaSlot = "demo_{$labOld}_{$scenarioIdx}";
        $personaId = $this->uuid('personas', $personaSlot);

        $rut = sprintf('%02d.%03d.%03d-%s', 90 + $labOld, 100 + $scenarioIdx, 50 + $labOld, ($scenarioIdx % 10) === 9 ? 'K' : (string) (1 + $scenarioIdx % 9));

        $persona = new Persona([
            'rut' => $rut,
            'names' => $scenario['key'] === 'entregado' ? 'Paciente' : 'Demo',
            'last_name_1' => 'Módulo',
            'last_name_2' => strtoupper($scenario['key']),
            'gender' => $scenarioIdx % 2 === 0 ? 'F' : 'M',
            'birth_date' => '1985-06-15',
            'phone' => '+569' . (88000000 + $labOld * 100 + $scenarioIdx),
            'email' => "demo.{$labOld}.{$scenarioIdx}@ejemplo.cl",
            'has_sso_account' => false,
        ]);
        $persona->id = $personaId;
        $persona->created_at = $now;
        $persona->updated_at = $now;
        $persona->save();

        $patientId = $this->uuid('patients', $personaSlot);
        DB::table('patients')->insert([
            'id' => $patientId,
            'laboratory_id' => $labId,
            'persona_id' => $personaId,
            'clinical_data' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $start = $now->copy()->addDays($scenario['day_offset'])->setTime($scenario['hour'], 0, 0);
        $end = $start->copy()->addMinutes(30);
        $accession = 'DEMO-L' . $labOld . '-' . strtoupper($scenario['key']);

        $appointmentId = $this->uuid('appointments', $personaSlot);
        $useFonasa = $scenarioIdx % 3 === 0;

        DB::table('appointments')->insert([
            'id' => $appointmentId,
            'laboratory_id' => $labId,
            'patient_id' => $patientId,
            'machine_id' => $machineId,
            'insurance_id' => $useFonasa ? $insuranceFonasa : $this->uuid('insurances', 3),
            'insurance_plan_id' => $planId,
            'referring_doctor_id' => $refDoctorId,
            'destination_doctor_id' => $userRad,
            'start_time' => $start,
            'end_time' => $end,
            'status' => $scenario['status'],
            'needs_review' => $scenario['status'] === 'devuelto_worklist',
            'priority' => $scenarioIdx === 0 ? 'Urgente' : 'Normal',
            'origin' => 'Ambulatorio',
            'payment_method' => $useFonasa ? 'FONASA' : 'Particular',
            'payment_status' => in_array($scenario['status'], ['entregable', 'entregado'], true) ? 'Pagado' : 'Pendiente',
            'transaction_code' => $useFonasa ? 'BONO-DEMO-' . $labOld . $scenarioIdx : null,
            'accession_number' => $accession,
            'referring_doctor' => 'Dr. Carlos Médico Demo',
            'images_received_at' => !empty($scenario['images_received']) ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $examRow = DB::table('exams')->where('id', $examId)->first();
        $studyId = $this->uuid('appointment_studies', $personaSlot);

        DB::table('appointment_studies')->insert([
            'id' => $studyId,
            'appointment_id' => $appointmentId,
            'machine_id' => $machineId,
            'exam_id' => $examId,
            'radiologist_user_id' => $userRad,
            'exam_name' => $examRow->name ?? 'Examen demo',
            'quantity' => 1,
            'fonasa_code' => $examRow->fonasa_code ?? null,
            'price' => $examRow->price ?? 50000,
            'status' => $scenario['study_status'],
            'anamnesis' => 'Paciente demo — escenario ' . $scenario['key'] . ' para laboratorio ' . $labOld,
            'report' => $scenario['report'] ?? null,
            'dictation_method' => isset($scenario['report']) ? 'texto' : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!empty($scenario['report'])) {
            DB::table('medical_reports')->insert([
                'id' => $this->uuid('medical_reports', $personaSlot),
                'appointment_study_id' => $studyId,
                'radiologist_id' => $userRad,
                'transcriptionist_id' => in_array($scenario['status'], ['en_transcripcion', 'para_firma'], true) ? $userTec : null,
                'report_text' => $scenario['report'],
                'status' => $scenario['status'] === 'entregable' || $scenario['status'] === 'entregado' ? 'firmado' : 'borrador',
                'signed_at' => in_array($scenario['status'], ['entregable', 'entregado'], true) ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $supplyId = $this->resolveSupplyId($labOld);
        if ($supplyId && in_array($scenario['status'], ['en_atencion', 'pendiente_radiologo', 'en_informe'], true)) {
            DB::table('appointment_supplies')->insert([
                'id' => $this->uuid('appointment_supplies', $personaSlot),
                'appointment_id' => $appointmentId,
                'supply_id' => $supplyId,
                'quantity' => 1,
                'price_charged' => 3500,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (in_array($scenario['status'], ['entregable', 'entregado'], true)) {
            DB::table('payments')->insert([
                'id' => $this->uuid('payments', $personaSlot),
                'appointment_id' => $appointmentId,
                'user_id' => $userAdmin,
                'amount' => $examRow->price ?? 50000,
                'payment_method' => $useFonasa ? 'FONASA' : 'Efectivo',
                'transaction_code' => 'PAY-DEMO-' . $labOld . $scenarioIdx,
                'status' => 'completado',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (!empty($scenario['delivered'])) {
            DB::table('appointment_deliveries')->insert([
                'id' => $this->uuid('appointment_deliveries', $personaSlot),
                'appointment_id' => $appointmentId,
                'delivered_by' => $userAdmin,
                'receiver_rut' => $rut,
                'receiver_name' => 'Demo Módulo ' . strtoupper($scenario['key']),
                'relationship' => 'Titular',
                'delivery_method' => 'Presencial',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('appointment_logs')->insert([
            'id' => $this->uuid('appointment_logs', $personaSlot),
            'appointment_id' => $appointmentId,
            'user_id' => $userAdmin,
            'action' => 'DEMO_SEED',
            'details' => json_encode(['scenario' => $scenario['key'], 'lab' => $labOld]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function resolveSupplyId(int $labOld): ?string
    {
        $preferred = match ($labOld) {
            1 => $this->uuid('supplies', 4),
            4 => $this->uuid('supplies', 105),
            9 => $this->uuid('supplies', 101),
            11 => $this->uuid('supplies', 103),
            default => null,
        };

        if (!$preferred) {
            return null;
        }

        if (DB::table('supplies')->where('id', $preferred)->exists()) {
            return $preferred;
        }

        $labId = $this->uuid('laboratories', $labOld);

        return DB::table('supplies')->where('laboratory_id', $labId)->value('id');
    }
}
