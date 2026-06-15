<?php

namespace Tests\Unit;

use App\Support\LabTimezone;
use Carbon\Carbon;
use Tests\TestCase;

class LabTimezoneTest extends TestCase
{
    public function test_naive_frontend_time_is_interpreted_as_lab_timezone(): void
    {
        config(['app.lab_timezone' => 'America/Santiago']);

        $utc = LabTimezone::parseScheduleTime('2026-06-08T11:15:00');

        $this->assertSame('2026-06-08 15:15:00', $utc->format('Y-m-d H:i:s'));
        $this->assertSame('111500', LabTimezone::worklistDateTime($utc)['time']);
    }

    public function test_iso_with_offset_is_preserved(): void
    {
        config(['app.lab_timezone' => 'America/Santiago']);

        $utc = LabTimezone::parseScheduleTime('2026-06-08T15:15:00Z');

        $this->assertSame('2026-06-08 15:15:00', $utc->format('Y-m-d H:i:s'));
        $this->assertSame('111500', LabTimezone::worklistDateTime($utc)['time']);
    }

    public function test_worklist_time_uses_local_not_utc_hour(): void
    {
        config(['app.lab_timezone' => 'America/Santiago']);

        $stored = Carbon::parse('2026-06-08 21:00:00', 'UTC');

        $this->assertSame('170000', LabTimezone::worklistDateTime($stored)['time']);
    }

    public function test_format_schedule_for_api_returns_lab_local_without_offset(): void
    {
        config(['app.lab_timezone' => 'America/Santiago']);

        $stored = Carbon::parse('2026-06-15 14:45:00', 'UTC');

        $this->assertSame('2026-06-15T10:45:00', LabTimezone::formatScheduleForApi($stored));
    }
}
