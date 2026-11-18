<?php

namespace App\Modules\Identity\Presentation\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class TemporaryPasswordMail extends Mailable
{
    public function __construct(
        public readonly string $password,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MSKBA — восстановление доступа');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.identity.temporary-password');
    }
}
