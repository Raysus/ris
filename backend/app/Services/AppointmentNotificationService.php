<?php

namespace App\Services;

use App\Mail\AppointmentConfirmationMail;
use App\Mail\AppointmentReminderMail;
use App\Mail\ReportReadyMail;
use App\Models\Appointment;
use App\Models\AppointmentLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AppointmentNotificationService
{
    /** URL del portal de pacientes externo (otro servidor), opcional en correos. */
    public function externalPatientPortalUrl(): ?string
    {
        $url = env('PATIENT_PORTAL_URL', 'https://portal.healthticloud.cl');

        return $url ? rtrim($url, '/') : null;
    }

    public function patientEmail(Appointment $appointment): ?string
    {
        $appointment->loadMissing('patient.persona');
        $email = $appointment->patient?->persona?->email;

        return ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
    }

    public function sendConfirmation(Appointment $appointment): array
    {
        if (!$this->mailFlag('MAIL_SEND_CONFIRMATION', true)) {
            return ['sent' => false, 'reason' => 'disabled'];
        }

        $email = $this->patientEmail($appointment);
        if (!$email) {
            return ['sent' => false, 'reason' => 'sin_correo'];
        }

        try {
            Mail::to($email)->send(new AppointmentConfirmationMail(
                $appointment,
                $this->externalPatientPortalUrl(),
            ));

            $this->logMail($appointment, 'confirmacion_cita_enviada', ['email' => $email]);

            return ['sent' => true, 'email' => $email];
        } catch (\Throwable $e) {
            Log::error('Error enviando confirmación de cita', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'reason' => 'error_envio', 'message' => $e->getMessage()];
        }
    }

    public function sendReminder(Appointment $appointment): array
    {
        if ($appointment->reminder_sent_at) {
            return ['sent' => false, 'reason' => 'ya_enviado'];
        }

        $email = $this->patientEmail($appointment);
        if (!$email) {
            return ['sent' => false, 'reason' => 'sin_correo'];
        }

        try {
            Mail::to($email)->send(new AppointmentReminderMail(
                $appointment,
                $this->externalPatientPortalUrl(),
            ));

            $appointment->reminder_sent_at = now();
            $appointment->saveQuietly();

            $this->logMail($appointment, 'recordatorio_cita_enviado', ['email' => $email]);

            return ['sent' => true, 'email' => $email];
        } catch (\Throwable $e) {
            Log::error('Error enviando recordatorio de cita', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'reason' => 'error_envio', 'message' => $e->getMessage()];
        }
    }

    public function sendReportReady(Appointment $appointment): array
    {
        $email = $this->patientEmail($appointment);
        if (!$email) {
            return ['sent' => false, 'reason' => 'sin_correo'];
        }

        try {
            Mail::to($email)->send(new ReportReadyMail(
                $appointment,
                $this->externalPatientPortalUrl(),
            ));

            $this->logMail($appointment, 'resultados_disponibles_enviados', ['email' => $email]);

            return ['sent' => true, 'email' => $email];
        } catch (\Throwable $e) {
            Log::error('Error enviando aviso de resultados', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'reason' => 'error_envio', 'message' => $e->getMessage()];
        }
    }

    private function mailFlag(string $envKey, bool $default): bool
    {
        $value = env($envKey, $default);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function logMail(Appointment $appointment, string $action, array $details = []): void
    {
        AppointmentLog::create([
            'appointment_id' => $appointment->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'details' => $details,
            'ip_address' => request()?->ip(),
        ]);
    }
}
