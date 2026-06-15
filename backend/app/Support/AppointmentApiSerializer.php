<?php

namespace App\Support;

use App\Models\Appointment;
use Illuminate\Support\Collection;

class AppointmentApiSerializer
{
    public static function toArray(Appointment $appointment): array
    {
        $data = $appointment->toArray();
        $data['start_time'] = LabTimezone::formatScheduleForApi($appointment->start_time);
        $data['end_time'] = LabTimezone::formatScheduleForApi($appointment->end_time);

        return $data;
    }

    /**
     * @param  Collection<int, Appointment>|array<int, Appointment>  $appointments
     * @return array<int, array<string, mixed>>
     */
    public static function collection(Collection|array $appointments): array
    {
        return collect($appointments)
            ->map(fn (Appointment $appointment) => self::toArray($appointment))
            ->values()
            ->all();
    }
}
