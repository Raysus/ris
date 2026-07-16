<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Laboratory;
use App\Support\LabTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class AppointmentScheduleService
{
  /** Estados que ocupan agenda (recepción). */
    public const BLOCKING_STATUSES = ['pre-agendado', 'agendado', 'confirmado', 'espera'];

    /**
     * @param  list<array{machine_id?: string|null, quantity?: int|float|string|null}>  $studies
     * @return array{start: CarbonInterface, end: CarbonInterface, adjusted: bool, shift_minutes: int, overbooked: bool}
     */
    public function resolveStartTime(
        string $laboratoryId,
        CarbonInterface $preferredStart,
        array $studies,
        ?string $excludeAppointmentId = null,
        ?string $fallbackMachineId = null,
        bool $allowOverbook = false,
    ): array {
        $lab = Laboratory::findOrFail($laboratoryId);
        $schedule = $lab->resolveScheduleSettings();
        $intervalMinutes = $this->intervalMinutesFromSetting($schedule['intervalo'] ?? '00:15:00');

        $preferred = $this->roundToInterval(
            $preferredStart->copy()->timezone(LabTimezone::name()),
            $intervalMinutes
        );

        $existingBlocks = $this->loadBlockingBlocksForDay(
            $laboratoryId,
            $preferred,
            $excludeAppointmentId
        );

        if ($allowOverbook) {
            $candidateBlocks = $this->buildBlocks($preferred, $studies, $intervalMinutes, $fallbackMachineId);

            if ($candidateBlocks === []) {
                throw new \RuntimeException('Debe indicar al menos un examen con sala asignada.');
            }

            if (!$this->blocksWithinLabHours($candidateBlocks, $schedule)) {
                throw new \RuntimeException('El horario solicitado está fuera del horario de atención del laboratorio.');
            }

            $end = $candidateBlocks[array_key_last($candidateBlocks)]['end'];
            $overbooked = $this->blocksOverlapExisting($candidateBlocks, $existingBlocks);

            return [
                'start' => $preferred->copy()->utc(),
                'end' => $end->copy()->utc(),
                'adjusted' => false,
                'shift_minutes' => 0,
                'overbooked' => $overbooked,
            ];
        }

        $cursor = $preferred->copy();
        $maxAttempts = 240;
        $shiftMinutes = 0;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $candidateBlocks = $this->buildBlocks($cursor, $studies, $intervalMinutes, $fallbackMachineId);

            if ($candidateBlocks === []) {
                throw new \RuntimeException('Debe indicar al menos un examen con sala asignada.');
            }

            if (!$this->blocksOverlapExisting($candidateBlocks, $existingBlocks)
                && $this->blocksWithinLabHours($candidateBlocks, $schedule)) {
                $end = $candidateBlocks[array_key_last($candidateBlocks)]['end'];

                return [
                    // Persistir siempre en UTC: Eloquent con app.timezone=UTC no convierte
                    // Carbon en TZ del lab y guardaría la hora mural como UTC (13:00 → 09:00 al mostrar).
                    'start' => $cursor->copy()->utc(),
                    'end' => $end->copy()->utc(),
                    'adjusted' => $shiftMinutes > 0,
                    'shift_minutes' => $shiftMinutes,
                    'overbooked' => false,
                ];
            }

            $cursor->addMinutes($intervalMinutes);
            $shiftMinutes += $intervalMinutes;
        }

        throw new \RuntimeException('No hay huecos disponibles en el horario del laboratorio para las salas seleccionadas.');
    }

    /**
     * @param  list<array{machine_id?: string|null, quantity?: int|float|string|null}>  $studies
     * @return list<array{machine_id: string, start: CarbonInterface, end: CarbonInterface}>
     */
    public function buildBlocks(
        CarbonInterface $start,
        array $studies,
        int $intervalMinutes,
        ?string $fallbackMachineId = null,
    ): array {
        $cursor = $start instanceof CarbonInterface
            ? $start->copy()->timezone(LabTimezone::name())
            : LabTimezone::parseScheduleTime((string) $start);
        $blocks = [];

        if ($studies !== []) {
            foreach ($studies as $study) {
                $machineId = (string) ($study['machine_id'] ?? $fallbackMachineId ?? '');
                if ($machineId === '') {
                    continue;
                }
                $qty = max(1, (int) ($study['quantity'] ?? 1));
                $mins = $intervalMinutes * $qty;
                $blockStart = $cursor->copy();
                $blockEnd = $cursor->copy()->addMinutes($mins);
                $blocks[] = [
                    'machine_id' => $machineId,
                    'start' => $blockStart,
                    'end' => $blockEnd,
                ];
                $cursor = $blockEnd;
            }

            return $blocks;
        }

        $machineId = (string) ($fallbackMachineId ?? '');
        if ($machineId === '') {
            return [];
        }

        $blockStart = $cursor->copy();
        $blockEnd = $cursor->copy()->addMinutes($intervalMinutes);

        return [[
            'machine_id' => $machineId,
            'start' => $blockStart,
            'end' => $blockEnd,
        ]];
    }

    /**
     * @return list<list<array{machine_id: string, start: CarbonInterface, end: CarbonInterface}>>
     */
    private function loadBlockingBlocksForDay(
        string $laboratoryId,
        CarbonInterface $dayAnchor,
        ?string $excludeAppointmentId,
    ): array {
        $tz = LabTimezone::name();
        $dayStart = $dayAnchor->copy()->timezone($tz)->startOfDay();
        $dayEnd = $dayAnchor->copy()->timezone($tz)->endOfDay();

        $lab = Laboratory::findOrFail($laboratoryId);
        $intervalMinutes = $this->intervalMinutesFromSetting(
            $lab->resolveScheduleSettings()['intervalo'] ?? '00:15:00'
        );

        $appointments = Appointment::query()
            ->where('laboratory_id', $laboratoryId)
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->when($excludeAppointmentId, fn ($q) => $q->where('id', '!=', $excludeAppointmentId))
            ->where('start_time', '<', $dayEnd)
            ->where('end_time', '>', $dayStart)
            ->with('studies')
            ->lockForUpdate()
            ->get();

        $allBlocks = [];
        foreach ($appointments as $appointment) {
            $studies = $appointment->studies
                ->map(fn ($study) => [
                    'machine_id' => $study->machine_id,
                    'quantity' => $study->quantity,
                ])
                ->values()
                ->all();

            $blocks = $this->buildBlocks(
                $appointment->start_time,
                $studies,
                $intervalMinutes,
                (string) $appointment->machine_id
            );

            if ($blocks !== []) {
                $allBlocks[] = $blocks;
            }
        }

        return $allBlocks;
    }

    /**
     * @param  list<array{machine_id: string, start: CarbonInterface, end: CarbonInterface}>  $candidateBlocks
     * @param  list<list<array{machine_id: string, start: CarbonInterface, end: CarbonInterface}>>  $existingBlocks
     */
    private function blocksOverlapExisting(array $candidateBlocks, array $existingBlocks): bool
    {
        foreach ($candidateBlocks as $candidate) {
            foreach ($existingBlocks as $appointmentBlocks) {
                foreach ($appointmentBlocks as $existing) {
                    if ($candidate['machine_id'] !== $existing['machine_id']) {
                        continue;
                    }
                    if ($candidate['start']->lt($existing['end']) && $candidate['end']->gt($existing['start'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{machine_id: string, start: CarbonInterface, end: CarbonInterface}>  $blocks
     * @param  array{horaInicio?: string, horaFin?: string}  $schedule
     */
    private function blocksWithinLabHours(array $blocks, array $schedule): bool
    {
        if ($blocks === []) {
            return false;
        }

        $tz = LabTimezone::name();
        $day = $blocks[0]['start']->copy()->timezone($tz)->format('Y-m-d');
        $horaInicio = $schedule['horaInicio'] ?? '08:00:00';
        $horaFin = $schedule['horaFin'] ?? '20:00:00';
        $open = LabTimezone::parseScheduleTime("{$day} {$horaInicio}");
        $close = LabTimezone::parseScheduleTime("{$day} {$horaFin}");

        $first = $blocks[0]['start'];
        $last = $blocks[array_key_last($blocks)]['end'];

        return $first->gte($open) && $last->lte($close);
    }

    private function roundToInterval(CarbonInterface $time, int $intervalMinutes): CarbonInterface
    {
        $rounded = $time->copy()->second(0);
        $minutes = (int) $rounded->format('i');
        $remainder = $minutes % $intervalMinutes;
        if ($remainder !== 0) {
            $rounded->addMinutes($intervalMinutes - $remainder);
        }

        return $rounded;
    }

    private function intervalMinutesFromSetting(string $interval): int
    {
        $parts = explode(':', $interval);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 15);

        return max(1, ($hours * 60) + $minutes);
    }
}
