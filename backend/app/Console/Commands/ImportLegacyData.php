<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ReferringDoctor;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class ImportLegacyData extends Command
{
    // El nombre del comando en la terminal
    protected $signature = 'import:legacy-doctors {file}';
    protected $description = 'Importa solo los médicos referidos del sistema antiguo (MySQL) a PostgreSQL adaptado a UUIDs';

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
        Model::unguard();
        DB::beginTransaction();
        
        try {
            foreach ($lines as $line) {
                $line = trim($line);

                // ==========================================
                // 1. MÉDICOS TRATANTES / REFERIDOS
                // ==========================================
                if (str_starts_with($line, "INSERT INTO `medico_tratante` VALUES")) {
                    $values = substr($line, strpos($line, 'VALUES (') + 8, -2);
                    $data = str_getcsv($values, ',', "'");

                    $rut = $data[1] === 'NULL' ? null : $data[1];
                    $nombres = $data[2] === 'NULL' ? 'Desconocido' : $data[2];
                    $apellido1 = $data[3] === 'NULL' ? null : $data[3];

                    // Usamos el Nombre y Primer Apellido como llave de búsqueda para evitar duplicados.
                    // El ID lo generará Laravel automáticamente en formato UUID.
                    ReferringDoctor::updateOrCreate(
                        [
                            'names' => $nombres,
                            'last_name_1' => $apellido1
                        ],
                        [
                            'rut' => $rut,
                            'last_name_2' => $data[4] === 'NULL' ? null : $data[4],
                            'phone' => $data[5] === 'NULL' ? null : $data[5],
                            'email' => $data[7] === 'NULL' ? null : $data[7],
                        ]
                    );
                    $countDoctores++;
                }
            }

            DB::commit();
            Model::reguard();
            $this->info("✅ Migración de Médicos Referidos finalizada con éxito.");
            $this->info("   - Total importados: {$countDoctores}");

        } catch (\Exception $e) {
            DB::rollBack();
            Model::reguard();
            $this->error("❌ Error durante la migración: " . $e->getMessage());
        }
    }
}