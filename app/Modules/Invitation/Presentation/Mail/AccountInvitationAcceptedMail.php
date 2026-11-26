<?php

namespace App\Modules\Invitation\Presentation\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class AccountInvitationAcceptedMail extends Mailable
{
    public function __construct(
        public readonly string $venueName,
        public readonly string $role,
        public readonly string $email,
        public readonly ?string $username,
        public readonly ?string $generatedPassword,
        public readonly string $loginUrl,
        public readonly array $portalContacts,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MSKBA — приглашение принято');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-invitations.accepted');
    }
}
