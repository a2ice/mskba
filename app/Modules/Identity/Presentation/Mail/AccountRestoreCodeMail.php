<?php

namespace App\Modules\Identity\Presentation\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class AccountRestoreCodeMail extends Mailable
{
    public function __construct(public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MSKBA — восстановление аккаунта');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.identity.restore-account-code');
    }
}
