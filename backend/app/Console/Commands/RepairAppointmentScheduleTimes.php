<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Laboratory;
use App\Support\LabTimezone;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RepairAppointmentScheduleTimes extends Command
{
    protected $signature = 'ris:repair-appointment-times
        {--lab= : UUID del laboratorio (opcional)}
        {--wall-as-utc : Reinterpretar la hora en BD como hora local del lab (citas con desfase −4 h)}
        {--dry-run : Solo mostrar cambios sin guardar}';

    protected $description = 'Corrige citas guardadas con hora local en columna UTC y normaliza duración al intervalo del lab';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $labId = (string) ($this->option('lab') ?: '');

        $query = Appointment::query()->with('laboratory');
        if ($labId !== '') {
            $query->where('laboratory_id', $labId);
        }

        $fixed = 0;
        $skipped = 0;

        $query->orderBy('start_time')->chunk(100, function ($appointments) use ($dryRun, &$fixed, &$skipped) {
            foreach ($appointments as $appointment) {
                $result = $this->repairAppointment($appointment, $dryRun, (bool) $this->option('wall-as-utc'));
                if ($result) {
                    $fixed++;
                } else {
                    $skipped++;
                }
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '') . "Citas corregidas: {$fixed}, sin cambios: {$skipped}");

        return self::SUCCESS;
    }

    private function repairAppointment(Appointment $appointment, bool $dryRun, bool $wallAsUtc): bool
    {
        $lab = $appointment->laboratory ?? Laboratory::find($appointment->laboratory_id);
        $schedule = $lab?->resolveScheduleSettings() ?? ['intervalo' => '00:10:00', 'horaInicio' => '08:00:00'];
        $intervalMinutes = $this->intervalMinutes((string) ($schedule['intervalo'] ?? '00:10:00'));
        $openMinutes = $this->timeToMinutes((string) ($schedule['horaInicio'] ?? '08:00:00'));

        $raw = (string) $appointment->getRawOriginal('start_time');
        if ($raw === '') {
            return false;
        }

        $asUtc = Carbon::parse($raw, 'UTC');
        $displayLocal = $asUtc->copy()->timezone(LabTimezone::name());
        $asLocalWall = Carbon::parse($raw, LabTimezone::name());

        $displayMinutes = $this->timeToMinutes($displayLocal->format('H:i'));
        $localWallMinutes = $this->timeToMinutes($asLocalWall->format('H:i'));

        $start = $asUtc->copy();
        $reason = null;

        if ($displayMinutes < $openMinutes && $localWallMinutes >= $openMinutes) {
            $start = $asLocalWall->copy()->utc();
            $reason = 'hora local guardada como UTC';
        } elseif ($wallAsUtc && !$asUtc->equalTo($asLocalWall->utc())) {
            $start = $asLocalWall->copy()->utc();
            $reason = 'hora mural guardada como UTC (--wall-as-utc)';
        }

        $end = $start->copy()->addMinutes($intervalMinutes);
        $currentStart = $appointment->start_time?->copy()->utc();
        $currentEnd = $appointment->end_time?->copy()->utc();

        $needsUpdate = $reason !== null
            || !$currentStart?->equalTo($start)
            || !$currentEnd?->equalTo($end);

        if (!$needsUpdate) {
            return false;
        }

        $label = LabTimezone::formatScheduleForApi($start);
        $this->line(sprintf(
            '%s %s → %s (%s)',
            $appointment->id,
            LabTimezone::formatScheduleForApi($appointment->start_time),
            $label,
            $reason ?? 'duración normalizada'
        ));

        if (!$dryRun) {
            $appointment->start_time = $start;
            $appointment->end_time = $end;
            $appointment->save();
        }

        return true;
    }

    private function intervalMinutes(string $interval): int
    {
        $parts = explode(':', $interval);

        return max(1, ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 10));
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);

        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }
}
