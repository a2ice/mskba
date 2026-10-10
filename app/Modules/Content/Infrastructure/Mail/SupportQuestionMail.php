<?php

namespace App\Modules\Content\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SupportQuestionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $topicLabel,
        public readonly string $sourcePath,
        public readonly string $questionBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MSKBA — вопрос в поддержку');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.support-question');
    }
}
