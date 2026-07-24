<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportTicketCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket)
    {
        $this->ticket->loadMissing(['laboratory', 'creator.persona']);
    }

    public function envelope(): Envelope
    {
        $lab = $this->ticket->laboratory?->name ?? 'RIS';
        $subject = $this->ticket->subject ?: 'Nueva solicitud';

        return new Envelope(
            subject: "Soporte RIS — {$lab}: {$subject}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.support_ticket_created',
        );
    }
}
