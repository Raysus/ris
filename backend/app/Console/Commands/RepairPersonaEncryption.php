<?php

namespace App\Console\Commands;

use App\Models\Persona;
use Illuminate\Console\Command;

class RepairPersonaEncryption extends Command
{
    protected $signature = 'ris:repair-persona-encryption {--dry-run : Solo listar filas afectadas}';

    protected $description = 'Re-cifra RUT/email/teléfono de personas (corrige texto plano tras sync inbound)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;

        Persona::query()->orderBy('id')->chunkById(100, function ($personas) use ($dryRun, &$fixed) {
            foreach ($personas as $persona) {
                $persona->rut = $persona->rut;
                $persona->email = $persona->email;
                $persona->phone = $persona->phone;

                if (!$persona->isDirty()) {
                    continue;
                }

                $fixed++;
                if ($dryRun) {
                    $this->line("  {$persona->id}");
                    continue;
                }

                $persona->save();
            }
        });

        if ($dryRun) {
            $this->info("Dry-run: se re-guardarían hasta {$fixed} persona(s). Ejecute sin --dry-run.");
        } else {
            $this->info("Personas re-cifradas/actualizadas: {$fixed}");
        }

        return self::SUCCESS;
    }
}
