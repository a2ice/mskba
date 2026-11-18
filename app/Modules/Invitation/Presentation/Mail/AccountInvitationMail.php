<?php

namespace App\Modules\Invitation\Presentation\Mail;

use App\Modules\Invitation\Domain\Models\AccountInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class AccountInvitationMail extends Mailable
{
    public function __construct(
        public readonly AccountInvitation $invitation,
        public readonly string $invitationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MSKBA — приглашение: '.$this->invitation->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-invitations.invitation');
    }
}
