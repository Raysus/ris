<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Appointment $appointment, public ?string $portalUrl = null)
    {
        $this->appointment->loadMissing(['patient.persona', 'machine', 'studies', 'laboratory']);
    }

    public function envelope(): Envelope
    {
        $lab = $this->appointment->laboratory?->name ?? config('app.name');

        return new Envelope(
            subject: "Confirmación de cita — {$lab}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.appointment_confirmation',
        );
    }
}
