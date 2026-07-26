<?php

namespace App\Modules\Contact\Presentation\Mail;

use App\Modules\Contact\Domain\Models\ContactVerification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContactVerificationCodeMail extends Mailable
{
    public function __construct(
        public readonly ContactVerification $verification,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Код подтверждения контакта',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.contact.verification-code',
        );
    }
}
