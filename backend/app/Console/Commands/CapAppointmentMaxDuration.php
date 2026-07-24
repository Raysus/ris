<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Laboratory;
use Illuminate\Console\Command;

class CapAppointmentMaxDuration extends Command
{
    protected $signature = 'ris:cap-appointment-duration
        {--lab= : UUID o nombre del laboratorio (ej. Siresa)}
        {--max=45 : Minutos máximos de duración}
        {--future-only : Solo citas con inicio futuro}
        {--dry-run : Mostrar sin guardar}';

    protected $description = 'Recorta end_time de citas que superan la duración máxima del laboratorio';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $maxMinutes = max(1, (int) $this->option('max'));
        $labOpt = trim((string) $this->option('lab'));

        $labQuery = Laboratory::query();
        if ($labOpt !== '') {
            if (preg_match('/^[0-9a-f-]{36}$/i', $labOpt)) {
                $labQuery->where('id', $labOpt);
            } else {
                $labQuery->where('name', 'like', '%' . $labOpt . '%');
            }
        }

        $labs = $labQuery->get();
        if ($labs->isEmpty()) {
            $this->error('No se encontró laboratorio.');

            return self::FAILURE;
        }

        $fixed = 0;
        $skipped = 0;

        foreach ($labs as $lab) {
            $settings = $lab->resolveScheduleSettings();
            $labMax = $this->intervalMinutes((string) ($settings['duracionMaximaCita'] ?? ''));
            $cap = $labMax > 0 ? $labMax : $maxMinutes;

            $this->info("Lab {$lab->name}: tope {$cap} min");

            $q = Appointment::query()->where('laboratory_id', $lab->id);
            if ($this->option('future-only')) {
                $q->where('start_time', '>=', now());
            }

            $q->orderBy('start_time')->chunkById(100, function ($appointments) use ($dryRun, $cap, &$fixed, &$skipped) {
                foreach ($appointments as $appointment) {
                    if (!$appointment->start_time || !$appointment->end_time) {
                        $skipped++;
                        continue;
                    }
                    $duration = $appointment->start_time->diffInMinutes($appointment->end_time);
                    if ($duration <= $cap) {
                        $skipped++;
                        continue;
                    }
                    $newEnd = $appointment->start_time->copy()->addMinutes($cap);
                    $this->line(sprintf(
                        '%s %s → %s (%d→%d min)',
                        $appointment->id,
                        $appointment->start_time->toIso8601String(),
                        $newEnd->toIso8601String(),
                        $duration,
                        $cap
                    ));
                    if (!$dryRun) {
                        $appointment->end_time = $newEnd;
                        $appointment->save();
                    }
                    $fixed++;
                }
            });
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Citas recortadas: {$fixed}, sin cambios: {$skipped}");

        return self::SUCCESS;
    }

    private function intervalMinutes(string $interval): int
    {
        if ($interval === '') {
            return 0;
        }
        $parts = explode(':', $interval);

        return max(0, ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0));
    }
}
