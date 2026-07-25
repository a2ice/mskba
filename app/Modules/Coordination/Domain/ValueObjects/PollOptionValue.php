<?php

namespace App\Modules\Coordination\Domain\ValueObjects;

use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PollOptionValue
{
    /** @param array<string, int|string> $value */
    private function __construct(
        public PollSubjectTypeEnum $subjectType,
        public string $label,
        public array $value,
    ) {}

    public static function text(string $value): self
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 255) {
            throw new InvalidArgumentException('Текстовый вариант должен содержать от 1 до 255 символов.');
        }

        return new self(PollSubjectTypeEnum::TEXT, $value, ['value' => $value]);
    }

    public static function date(string $value): self
    {
        $date = self::parseExact('!Y-m-d', $value, 'Укажите корректную дату.');

        return new self(
            PollSubjectTypeEnum::DATE,
            $date->format('d.m.Y'),
            ['date' => $date->format('Y-m-d')],
        );
    }

    public static function time(string $value): self
    {
        $time = self::parseExact('!H:i', $value, 'Укажите корректное время.');

        return new self(
            PollSubjectTypeEnum::TIME,
            $time->format('H:i'),
            ['time' => $time->format('H:i')],
        );
    }

    public static function dateTime(string $value): self
    {
        $dateTime = self::parseExact('!Y-m-d\TH:i', $value, 'Укажите корректные дату и время.');

        return new self(
            PollSubjectTypeEnum::DATETIME,
            $dateTime->format('d.m.Y H:i'),
            ['datetime' => $dateTime->format('Y-m-d\TH:i')],
        );
    }

    public static function timeInterval(string $startsAt, string $endsAt): self
    {
        $start = self::parseExact('!H:i', $startsAt, 'Укажите корректное начало интервала.');
        $end = self::parseExact('!H:i', $endsAt, 'Укажите корректное окончание интервала.');

        if ($end <= $start) {
            throw new InvalidArgumentException('Окончание интервала должно быть позже начала.');
        }

        return new self(
            PollSubjectTypeEnum::TIME_INTERVAL,
            $start->format('H:i').'–'.$end->format('H:i'),
            [
                'starts_at' => $start->format('H:i'),
                'ends_at' => $end->format('H:i'),
            ],
        );
    }

    public static function venue(int $venueId, string $venueName): self
    {
        $venueName = trim($venueName);

        if ($venueId < 1 || $venueName === '') {
            throw new InvalidArgumentException('Выберите доступную площадку.');
        }

        return new self(
            PollSubjectTypeEnum::VENUE,
            $venueName,
            ['venue_id' => $venueId],
        );
    }

    public function uniqueKey(): string
    {
        return $this->subjectType->value.':'.json_encode(
            $this->value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private static function parseExact(string $format, string $value, string $message): DateTimeImmutable
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException($message);
        }

        $comparisonFormat = ltrim($format, '!');

        if ($parsed->format($comparisonFormat) !== $value) {
            throw new InvalidArgumentException($message);
        }

        return $parsed;
    }
}
