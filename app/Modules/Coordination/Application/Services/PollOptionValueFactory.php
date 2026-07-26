<?php

namespace App\Modules\Coordination\Application\Services;

use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\ValueObjects\PollOptionValue;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use InvalidArgumentException;

final class PollOptionValueFactory
{
    /**
     * @return array<int, PollOptionValue>
     */
    public function many(PollSubjectTypeEnum $subjectType, mixed $rawOptions): array
    {
        if (! is_array($rawOptions)) {
            throw new InvalidArgumentException('Добавьте варианты ответа.');
        }

        $rawOptions = array_values($rawOptions);

        if (count($rawOptions) < 2 || count($rawOptions) > 20) {
            throw new InvalidArgumentException('Укажите от двух до двадцати вариантов ответа.');
        }

        $options = $subjectType === PollSubjectTypeEnum::VENUE
            ? $this->venues($rawOptions)
            : array_map(
                fn (mixed $option): PollOptionValue => $this->one($subjectType, $option),
                $rawOptions,
            );
        $uniqueKeys = array_map(
            static fn (PollOptionValue $option): string => mb_strtolower($option->uniqueKey()),
            $options,
        );

        if (count(array_unique($uniqueKeys)) !== count($uniqueKeys)) {
            throw new InvalidArgumentException('Варианты ответа не должны повторяться.');
        }

        return $options;
    }

    public function one(PollSubjectTypeEnum $subjectType, mixed $rawOption): PollOptionValue
    {
        return match ($subjectType) {
            PollSubjectTypeEnum::TEXT => PollOptionValue::text((string) $rawOption),
            PollSubjectTypeEnum::DATE => PollOptionValue::date((string) $rawOption),
            PollSubjectTypeEnum::TIME => PollOptionValue::time((string) $rawOption),
            PollSubjectTypeEnum::DATETIME => PollOptionValue::dateTime((string) $rawOption),
            PollSubjectTypeEnum::TIME_INTERVAL => $this->timeInterval($rawOption),
            PollSubjectTypeEnum::VENUE => $this->venue($rawOption),
            PollSubjectTypeEnum::PARTICIPATION => PollOptionValue::participationSuggestion((string) $rawOption),
        };
    }

    private function timeInterval(mixed $rawOption): PollOptionValue
    {
        if (! is_array($rawOption)) {
            throw new InvalidArgumentException('Укажите начало и окончание интервала.');
        }

        return PollOptionValue::timeInterval(
            (string) ($rawOption['starts_at'] ?? ''),
            (string) ($rawOption['ends_at'] ?? ''),
        );
    }

    private function venue(mixed $rawOption): PollOptionValue
    {
        $venueId = filter_var($rawOption, FILTER_VALIDATE_INT);

        if ($venueId === false) {
            throw new InvalidArgumentException('Выберите доступную площадку.');
        }

        $venue = Venue::query()
            ->whereKey((int) $venueId)
            ->where('status', VenueStatusEnum::CONFIRMED->value)
            ->where('operational_status', VenueOperationalStatusEnum::ACTIVE->value)
            ->first();

        if ($venue === null) {
            throw new InvalidArgumentException('Выбранная площадка недоступна.');
        }

        return PollOptionValue::venue($venue->id, $venue->name);
    }

    /**
     * @param  array<int, mixed>  $rawOptions
     * @return array<int, PollOptionValue>
     */
    private function venues(array $rawOptions): array
    {
        $venueIds = array_map(static function (mixed $rawOption): int {
            $venueId = filter_var($rawOption, FILTER_VALIDATE_INT);

            if ($venueId === false) {
                throw new InvalidArgumentException('Выберите доступную площадку.');
            }

            return (int) $venueId;
        }, $rawOptions);
        $venues = Venue::query()
            ->whereKey($venueIds)
            ->where('status', VenueStatusEnum::CONFIRMED->value)
            ->where('operational_status', VenueOperationalStatusEnum::ACTIVE->value)
            ->get(['id', 'name'])
            ->keyBy('id');

        return array_map(static function (int $venueId) use ($venues): PollOptionValue {
            $venue = $venues->get($venueId);

            if ($venue === null) {
                throw new InvalidArgumentException('Выбранная площадка недоступна.');
            }

            return PollOptionValue::venue($venue->id, $venue->name);
        }, $venueIds);
    }
}
