<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Exam;
use App\Models\Machine;
use App\Models\ReferringDoctor;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class ImportLegacyData extends Command
{
    // El nombre del comando en la terminal
    protected $signature = 'import:legacy {file}';
    protected $description = 'Importa datos del sistema antiguo (MySQL) a PostgreSQL';

    public function handle()
    {
        $filePath = $this->argument('file');

        if (!file_exists($filePath)) {
            $this->error("El archivo no existe: {$filePath}");
            return;
        }

        $this->info("Leyendo archivo: {$filePath}");
        $lines = file($filePath);
        $this->info("Procesando " . count($lines) . " líneas. Esto puede tomar unos segundos...");

        $countDoctores = 0;
        $countExamenes = 0;
        $countEquipos = 0;
        Model::unguard();
        DB::beginTransaction();
        try {
            foreach ($lines as $line) {
                $line = trim($line);

                // ==========================================
                // 1. MÉDICOS TRATANTES
                // ==========================================
                if (str_starts_with($line, "INSERT INTO `medico_tratante` VALUES")) {
                    $values = substr($line, strpos($line, 'VALUES (') + 8, -2);
                    $data = str_getcsv($values, ',', "'");

                    ReferringDoctor::updateOrCreate(
                        ['id' => $data[0]], // Respetamos el ID antiguo
                        [
                            'rut' => $data[1] === 'NULL' ? null : $data[1],
                            'names' => $data[2] === 'NULL' ? 'Desconocido' : $data[2],
                            'last_name_1' => $data[3] === 'NULL' ? null : $data[3],
                            'last_name_2' => $data[4] === 'NULL' ? null : $data[4],
                            'phone' => $data[5] === 'NULL' ? null : $data[5],
                            'email' => $data[7] === 'NULL' ? null : $data[7],
                        ]
                    );
                    $countDoctores++;
                }

                // ==========================================
                // 2. EXÁMENES (Catálogo y tiempos)
                // ==========================================
                if (str_starts_with($line, "INSERT INTO `examen` VALUES")) {
                    $values = substr($line, strpos($line, 'VALUES (') + 8, -2);
                    $data = str_getcsv($values, ',', "'");

                    // Calcular duración en minutos desde el formato "00:15:00"
                    $minutes = 15;
                    if ($data[3] !== 'NULL') {
                        $parts = explode(':', $data[3]);
                        if (count($parts) >= 2) {
                            $minutes = ((int) $parts[0] * 60) + (int) $parts[1];
                        }
                    }

                    // Mapeo inteligente de "id_tipo_ex" al Group Code de nuestro JS
                    $group = 'GEN';
                    switch ($data[2]) {
                        case '1':
                        case '4':
                        case '5':
                        case '6':
                        case '7':
                        case '8':
                        case '9':
                            $group = 'RX';
                            break;
                        case '2':
                            $group = 'MAMO';
                            break;
                        case '3':
                            $group = 'ECO';
                            break;
                        case '10':
                            $group = 'DEXA';
                            break;
                        case '11':
                            $group = 'CT';
                            break;
                        case '12':
                            $group = 'MRI';
                            break;
                    }

                    Exam::updateOrCreate(
                        ['id' => $data[0]],
                        [
                            'laboratory_id' => 1, // Tu sucursal principal
                            'group_code' => $group,
                            'fonasa_code' => $data[1] === 'NULL' ? null : $data[1],
                            'name' => $data[5] === 'NULL' ? 'Examen ' . $data[0] : $data[5],
                            'estimated_duration' => $minutes,
                            'is_active' => true,
                            'sub_exams' => [] // Vacio por ahora
                        ]
                    );
                    $countExamenes++;
                }

                // ==========================================
                // 3. EQUIPOS (Máquinas y conexión DICOM)
                // ==========================================
                if (str_starts_with($line, "INSERT INTO `equipo` VALUES")) {
                    $values = substr($line, strpos($line, 'VALUES (') + 8, -2);
                    $data = str_getcsv($values, ',', "'");

                    $group = 'GEN';
                    switch ($data[6]) { // id_tipo_eq
                        case '1':
                            $group = 'RX';
                            break;
                        case '2':
                            $group = 'MAMO';
                            break;
                        case '3':
                            $group = 'ECO';
                            break;
                        case '4':
                            $group = 'DEXA';
                            break;
                        case '5':
                            $group = 'MRI';
                            break;
                        case '6':
                            $group = 'CT';
                            break;
                    }

                    Machine::updateOrCreate(
                        ['id' => $data[0]],
                        [
                            'laboratory_id' => 1,
                            'name' => $data[7] === 'NULL' ? 'Equipo ' . $data[0] : $data[7],
                            'group' => $group,
                            'is_active' => $data[1] === '1',
                            'ip_address' => $data[10] === 'NULL' ? null : $data[10],
                            'port' => $data[11] === 'NULL' ? null : $data[11],
                            'ae_title' => $data[13] === 'NULL' ? null : $data[13],
                        ]
                    );
                    $countEquipos++;
                }
            }

            // Sincronizamos las secuencias numéricas de PostgreSQL para que no falle cuando agregues nuevos
            DB::statement("SELECT setval('referring_doctors_id_seq', (SELECT COALESCE(MAX(id), 1) FROM referring_doctors))");
            DB::statement("SELECT setval('exams_id_seq', (SELECT COALESCE(MAX(id), 1) FROM exams))");
            DB::statement("SELECT setval('machines_id_seq', (SELECT COALESCE(MAX(id), 1) FROM machines))");

            DB::commit();
            Model::reguard();
            $this->info("✅ Migración de datos finalizada con éxito.");
            $this->info("   - Médicos Tratantes: {$countDoctores}");
            $this->info("   - Exámenes (Catálogo): {$countExamenes}");
            $this->info("   - Salas / Equipos: {$countEquipos}");

        } catch (\Exception $e) {
            DB::rollBack();
            Model::reguard();
            $this->error("❌ Error durante la migración: " . $e->getMessage());
        }
    }
}