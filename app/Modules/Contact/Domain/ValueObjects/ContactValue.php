<?php

namespace App\Modules\Contact\Domain\ValueObjects;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use InvalidArgumentException;

final readonly class ContactValue
{
    private string $value;

    public function __construct(
        public ContactTypeEnum $type,
        string $value,
    ) {
        $this->value = match ($this->type) {
            ContactTypeEnum::EMAIL => $this->normalizeEmail($value),
            ContactTypeEnum::PHONE => $this->normalizePhone($value),
            ContactTypeEnum::TELEGRAM => $this->normalizeTelegram($value),
            ContactTypeEnum::VK => $this->normalizeVk($value),
            ContactTypeEnum::OTHER => $this->normalizeGeneric($value),
        };
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private function normalizeEmail(string $value): string
    {
        $email = $this->normalizeGeneric($value);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Некорректный формат email адреса.');
        }

        return $email;
    }

    private function normalizePhone(string $value): string
    {
        $phone = trim($value);
        $phone = str_replace([' ', '-', '(', ')'], '', $phone);

        if (! preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
            throw new InvalidArgumentException('Некорректный формат телефона.');
        }

        return $phone;
    }

    private function normalizeTelegram(string $value): string
    {
        $telegram = $this->normalizeGeneric($value);
        $telegram = preg_replace('~^https?://(?:t\.me|telegram\.me)/~i', '', $telegram) ?? $telegram;
        $telegram = ltrim($telegram, '@');

        if (! preg_match('/^[A-Za-z0-9_]{5,32}$/', $telegram)) {
            throw new InvalidArgumentException('Некорректный формат Telegram.');
        }

        return '@'.$telegram;
    }

    private function normalizeVk(string $value): string
    {
        $vk = $this->normalizeGeneric($value);
        $vk = preg_replace('~^https?://(?:www\.)?vk\.com/~i', '', $vk) ?? $vk;
        $vk = ltrim($vk, '@');

        if (! preg_match('/^[A-Za-z0-9_.]{2,64}$/', $vk)) {
            throw new InvalidArgumentException('Некорректный формат VK.');
        }

        return $vk;
    }

    private function normalizeGeneric(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Значение контакта не может быть пустым.');
        }

        if (mb_strlen($value) > 255) {
            throw new InvalidArgumentException('Значение контакта не должно быть длиннее 255 символов.');
        }

        return $value;
    }
}
