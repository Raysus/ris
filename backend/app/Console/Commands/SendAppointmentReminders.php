<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\AppointmentNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendAppointmentReminders extends Command
{
    protected $signature = 'ris:send-appointment-reminders';

    protected $description = 'Envía recordatorio por correo a citas confirmadas para el día siguiente';

    public function handle(AppointmentNotificationService $notifications): int
    {
        $tomorrow = Carbon::tomorrow();
        $windowStart = $tomorrow->copy()->startOfDay();
        $windowEnd = $tomorrow->copy()->endOfDay();

        $appointments = Appointment::query()
            ->with(['patient.persona', 'machine', 'studies', 'laboratory'])
            ->whereNull('reminder_sent_at')
            ->whereBetween('start_time', [$windowStart, $windowEnd])
            ->whereIn('status', ['agendado', 'confirmado', 'espera', 'pre-agendado'])
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($appointments as $appointment) {
            $result = $notifications->sendReminder($appointment);
            if ($result['sent'] ?? false) {
                $sent++;
                $this->line("✓ Recordatorio enviado: {$appointment->id}");
            } else {
                $skipped++;
            }
        }

        $this->info("Recordatorios: {$sent} enviados, {$skipped} omitidos.");

        return self::SUCCESS;
    }
}
