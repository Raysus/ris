<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class ExamInstructionsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public Collection $instructions
    ) {
        $this->appointment->loadMissing(['patient.persona', 'laboratory', 'machine', 'studies']);
    }

    public function envelope(): Envelope
    {
        $centro = $this->appointment->laboratory?->name ?? 'Centro de Diagnóstico';

        return new Envelope(
            subject: "Instrucciones para su examen — {$centro}",
        );
    }

    public function content(): Content
    {
        $persona = $this->appointment->patient?->persona;
        $fecha = Carbon::parse($this->appointment->start_time)->locale('es')->translatedFormat('l d \d\e F \d\e Y \a \l\a\s H:i');

        return new Content(
            view: 'emails.exam_instructions',
            with: [
                'nombrePaciente' => trim("{$persona?->names} {$persona?->last_name_1}"),
                'fechaCita' => $fecha,
                'centro' => $this->appointment->laboratory?->name ?? 'Centro de Diagnóstico',
                'direccion' => $this->appointment->laboratory?->address,
                'telefono' => $this->appointment->laboratory?->phone,
                'estudios' => $this->appointment->studies,
                'instructions' => $this->instructions,
            ],
        );
    }
}
