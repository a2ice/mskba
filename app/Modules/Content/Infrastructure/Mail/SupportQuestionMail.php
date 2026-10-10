<?php

namespace App\Modules\Content\Infrastructure\Mail;

use App\Modules\Content\Domain\Models\SupportQuestion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class SupportQuestionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly SupportQuestion $question,
        public readonly string $topicLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MSKBA — вопрос #'.$this->question->id);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.support-question');
    }
}
