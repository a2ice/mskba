<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Content\Domain\Enums\ContentStatusEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;

final readonly class WelcomeAccountConfirmationGuide
{
    public function __construct(private ContentBodyRenderer $renderer) {}

    public function html(): ?string
    {
        $faq = ContentItem::query()
            ->where('type', ContentTypeEnum::FAQ)
            ->where('system_key', 'faq.welcome')
            ->first();

        if ($faq === null) {
            // Exact account-confirmation instructions from the legacy /faq/welcome fallback.
            return '<p>Откройте мастер подтверждения аккаунта. Обязательны подтверждённый основной контакт и выбранная роль участия. Для игрока и тренера также обязательны дата рождения и пол. Имя и фамилию можно заполнить дополнительно, но они не являются обязательным условием подтверждения.</p>';
        }

        // Never expose draft or archived FAQ via an otherwise public profile dialog.
        if ($faq->status !== ContentStatusEnum::PUBLISHED) {
            return null;
        }

        $source = (string) $faq->full_description;
        $anchorPattern = "/\\[anchor\\s+id=[\"']?account-confirmation[\"']?\\s*\\]/iu";
        if (preg_match($anchorPattern, $source, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $offset = $matches[0][1] + strlen($matches[0][0]);
        $section = substr($source, $offset);
        $section = preg_split("/\\[anchor\\s+id=[\"']?[a-z0-9_-]+[\"']?\\s*\\]/iu", $section, 2)[0] ?? '';
        $section = preg_replace('/^\\s*<h[1-6]\\b[^>]*>.*?<\\/h[1-6]>\\s*/isu', '', $section, 1) ?? $section;
        $section = preg_replace('/^\\s*#{1,6}[^\\n]*\\n/u', '', $section, 1) ?? $section;

        if (trim($section) === '') {
            return null;
        }

        // Clone before rendering: never change the stored FAQ body.
        $excerpt = clone $faq;
        $excerpt->full_description = $section;

        return $this->renderer->render($excerpt);
    }
}
