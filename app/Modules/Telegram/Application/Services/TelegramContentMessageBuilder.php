<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Content\Domain\Models\ContentItem;
use Illuminate\Support\Str;

final class TelegramContentMessageBuilder
{
    public function text(ContentItem $content): string
    {
        return $this->message($content, 1000);
    }

    public function caption(ContentItem $content): string
    {
        return $this->message($content, 750);
    }

    public function photoUrl(ContentItem $content): ?string
    {
        $cover = $content->relationLoaded('cover')
            ? $content->cover->first()
            : $content->cover()->first();

        return $cover === null
            ? null
            : url($cover->publicUrl());
    }

    private function message(ContentItem $content, int $descriptionLimit): string
    {
        return implode("\n", [
            '🏀 <b>'.$this->escape($content->title).'</b>',
            '',
            $this->escape(Str::limit($content->short_description, $descriptionLimit)),
        ]);
    }

    /** @return array{inline_keyboard: array<int, array<int, array<string, string>>>} */
    public function replyMarkup(ContentItem $content): array
    {
        return [
            'inline_keyboard' => [[
                [
                    'text' => 'Открыть материал',
                    'url' => $this->materialUrl($content),
                ],
            ]],
        ];
    }

    private function materialUrl(ContentItem $content): string
    {
        $botUsername = ltrim(trim((string) config('telegram.bot_username')), '@');

        if ($botUsername === '') {
            return $content->publicUrl();
        }

        return sprintf(
            'https://t.me/%s?startapp=%s',
            rawurlencode($botUsername),
            rawurlencode("content_{$content->id}"),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
