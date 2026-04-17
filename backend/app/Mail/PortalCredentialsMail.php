<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public $nombre;
    public $rut;
    public $password;

    public function __construct($nombre, $rut, $password)
    {
        $this->nombre = $nombre;
        $this->rut = $rut;
        $this->password = $password;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tus credenciales para el Portal de Pacientes',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.portal_credentials',
        );
    }
}