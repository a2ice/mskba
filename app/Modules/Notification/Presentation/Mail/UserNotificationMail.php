<?php

namespace App\Modules\Notification\Presentation\Mail;

use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class UserNotificationMail extends Mailable
{
    public function __construct(public readonly UserNotification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notification.user-notification');
    }
}
