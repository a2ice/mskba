<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Notification\Domain\Models\UserNotification;

final class TelegramUserNotificationMessageBuilder
{
    /** @return array<string, mixed> */
    public function build(UserNotification $notification, int $chatId): array
    {
        $text = '<b>'.$this->escape($notification->title).'</b>';
        if (filled($notification->body)) {
            $text .= "\n\n".$this->escape($notification->body);
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if (filled($notification->action_url)) {
            $payload['reply_markup'] = [
                'inline_keyboard' => [[[
                    'text' => $notification->action_text ?: 'Открыть',
                    'url' => $this->actionUrl($notification),
                ]]],
            ];
        }

        return $payload;
    }

    private function actionUrl(UserNotification $notification): string
    {
        $botUsername = ltrim(trim((string) config('telegram.bot_username')), '@');

        if ($botUsername !== '') {
            return sprintf(
                'https://t.me/%s?startapp=notification_%d',
                rawurlencode($botUsername),
                $notification->getKey(),
            );
        }

        return str_starts_with($notification->action_url, 'http://')
            || str_starts_with($notification->action_url, 'https://')
            ? $notification->action_url
            : url($notification->action_url);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
