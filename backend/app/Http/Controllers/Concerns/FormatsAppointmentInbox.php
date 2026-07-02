<?php

namespace App\Http\Controllers\Concerns;

use App\Support\LabTimezone;
use Carbon\Carbon;
use Illuminate\Http\Request;

trait FormatsAppointmentInbox
{
    protected function applyExamDateFilter($query, Request $request)
    {
        $date = trim((string) $request->query('date', ''));
        if ($date === '' || strtolower($date) === 'all') {
            return $query;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $query;
        }

        $tz = LabTimezone::name();
        $start = Carbon::parse($date, $tz)->startOfDay()->utc();
        $end = Carbon::parse($date, $tz)->endOfDay()->utc();

        return $query->whereBetween('start_time', [$start, $end]);
    }

    protected function examInboxTimingFields($appointment): array
    {
        return [
            'examDate' => LabTimezone::examDateForApi($appointment->start_time),
            'examDateTime' => LabTimezone::formatScheduleForApi($appointment->start_time),
            'examTime' => LabTimezone::examTimeForApi($appointment->start_time),
        ];
    }
}
