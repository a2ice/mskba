<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Reaction\Domain\Enums\ReactionValueEnum;

final class TelegramReactionClassifier
{
    /** @param list<array<string, mixed>> $reactions */
    public function classify(array $reactions): ?ReactionValueEnum
    {
        $hasPositive = false;
        $hasNegative = false;

        foreach ($reactions as $reaction) {
            if (($reaction['type'] ?? null) !== 'emoji' || ! is_string($reaction['emoji'] ?? null)) {
                continue;
            }

            $emoji = $this->normalizeEmoji($reaction['emoji']);

            if (in_array($emoji, $this->positive(), true)) {
                $hasPositive = true;
            }

            if (in_array($emoji, $this->negative(), true)) {
                $hasNegative = true;
            }
        }

        if ($hasPositive === $hasNegative) {
            return null;
        }

        return $hasPositive ? ReactionValueEnum::LIKE : ReactionValueEnum::DISLIKE;
    }

    /** @param list<array<string, mixed>> $reactions @return list<string> */
    public function recognizedEmojis(array $reactions): array
    {
        $supported = [...$this->positive(), ...$this->negative()];
        $recognized = [];

        foreach ($reactions as $reaction) {
            if (($reaction['type'] ?? null) !== 'emoji' || ! is_string($reaction['emoji'] ?? null)) {
                continue;
            }

            $emoji = $this->normalizeEmoji($reaction['emoji']);
            if (in_array($emoji, $supported, true)) {
                $recognized[] = $emoji;
            }
        }

        return array_values(array_unique($recognized));
    }

    /** @return list<string> */
    private function positive(): array
    {
        return array_values(array_unique(array_map(
            $this->normalizeEmoji(...),
            (array) config('telegram.reactions.positive', []),
        )));
    }

    /** @return list<string> */
    private function negative(): array
    {
        return array_values(array_unique(array_map(
            $this->normalizeEmoji(...),
            (array) config('telegram.reactions.negative', []),
        )));
    }

    private function normalizeEmoji(string $emoji): string
    {
        return str_replace("\u{FE0F}", '', trim($emoji));
    }
}
