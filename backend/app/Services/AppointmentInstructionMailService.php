<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentLog;
use App\Models\ExamInstruction;
use App\Mail\ExamInstructionsMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AppointmentInstructionMailService
{
    public function shouldSend(Appointment $appointment): bool
    {
        return strtolower(trim($appointment->origin ?? 'Ambulatorio')) !== 'ambulatorio';
    }

    public function sendIfApplicable(Appointment $appointment): array
    {
        if (!$this->shouldSend($appointment)) {
            return ['sent' => false, 'reason' => 'ambulatorio'];
        }

        $appointment->loadMissing(['patient.persona', 'studies', 'laboratory', 'machine']);

        $email = $appointment->patient?->persona?->email;
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'reason' => 'sin_correo'];
        }

        $instructions = $this->collectInstructions($appointment);
        if ($instructions->isEmpty()) {
            return ['sent' => false, 'reason' => 'sin_instrucciones'];
        }

        try {
            Mail::to($email)->send(new ExamInstructionsMail($appointment, $instructions));

            AppointmentLog::create([
                'appointment_id' => $appointment->id,
                'user_id' => auth()->id(),
                'action' => 'instrucciones_examen_enviadas',
                'ip_address' => request()->ip(),
            ]);

            return ['sent' => true, 'email' => $email, 'count' => $instructions->count()];
        } catch (\Throwable $e) {
            Log::error('Error enviando instrucciones de examen', [
                'appointment_id' => $appointment->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'reason' => 'error_envio', 'message' => $e->getMessage()];
        }
    }

    private function collectInstructions(Appointment $appointment)
    {
        $examIds = $appointment->studies->pluck('exam_id')->filter()->unique()->values();

        if ($examIds->isEmpty()) {
            return collect();
        }

        return ExamInstruction::query()
            ->with('exam')
            ->whereIn('exam_id', $examIds)
            ->where('is_active', true)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->get()
            ->unique('exam_id')
            ->values();
    }
}
