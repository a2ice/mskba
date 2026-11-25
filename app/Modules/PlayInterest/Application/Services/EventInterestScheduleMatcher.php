<?php

namespace App\Modules\PlayInterest\Application\Services;

use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use Carbon\CarbonImmutable;

final class EventInterestScheduleMatcher
{
    /** @return list<array{CarbonImmutable, CarbonImmutable}> */
    public function candidateTimes(Event $event, string $timezone): array
    {
        if ($event->starts_at === null || $event->ends_at === null) {
            return [];
        }
        $start = $event->starts_at->setTimezone($timezone);
        $end = $event->ends_at->setTimezone($timezone);
        $candidateTimes = [];
        foreach (($event->proposed_time_options ?? []) as $option) {
            if (! is_array($option) || empty($option['starts_at']) || empty($option['duration_minutes'])) {
                continue;
            }
            $proposedStart = CarbonImmutable::parse($option['starts_at'], $timezone);
            $candidateTimes[] = [$proposedStart, $proposedStart->addMinutes((int) $option['duration_minutes'])];
        }
        if ($candidateTimes === []) {
            $candidateTimes[] = [$start, $end];
        }
        return $candidateTimes;
    }

    public function matchesSchedule(
        PlayIntent $intent,
        Event $event,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): bool {
        $date = $start->toDateString();
        if ($intent->cadence === 'once') {
            $dates = (array) ($intent->dates ?? []);
            if ($dates !== [] && ! in_array($date, $dates, true)) {
                return false;
            }
            if ($dates === [] && (
                $intent->period_from === null || $intent->period_until === null
                || $date < $intent->period_from->toDateString()
                || $date > $intent->period_until->toDateString()
            )) {
                return false;
            }
        }

        $weekdays = array_map('intval', (array) ($intent->weekdays ?? []));
        if ($weekdays !== [] && ! in_array($start->dayOfWeekIso, $weekdays, true)) {
            return false;
        }

        $wantedDuration = $intent->duration_minutes;
        if ($wantedDuration !== null && (int) $start->diffInMinutes($end) !== (int) $wantedDuration) {
            return false;
        }

        $formats = (array) ($intent->formats ?? []);
        if ($formats !== []) {
            if ($event->type !== EventTypeEnum::GAME || $event->primaryGame === null) {
                return false;
            }
            $game = $event->primaryGame;
            $format = match ($game->format?->value) {
                'basketball_5x5' => '5x5',
                'streetball_3x3' => '3x3',
                'streetball_1x1' => '1x1',
                'custom' => $game->side_a_size === $game->side_b_size ? $game->side_a_size.'x'.$game->side_b_size : null,
                default => null,
            };
            if ($format === null || ! in_array($format, $formats, true)) {
                return false;
            }
        }

        $windows = (array) ($intent->windows ?? []);
        if ($windows === []) {
            return true;
        }

        // A night window belongs to its starting weekday/date, and may
        // contain a game starting after midnight on the following day.
        foreach ([$start->startOfDay(), $start->subDay()->startOfDay()] as $candidateDay) {
            foreach ($windows as $window) {
                if (! is_array($window) || empty($window['start_time']) || empty($window['end_time'])) {
                    continue;
                }
                if (! empty($window['date']) && $window['date'] !== $candidateDay->toDateString()) {
                    continue;
                }
                if (isset($window['weekday']) && (int) $window['weekday'] !== $candidateDay->dayOfWeekIso) {
                    continue;
                }
                $from = CarbonImmutable::parse($candidateDay->toDateString().' '.$window['start_time'], $start->timezone);
                $until = CarbonImmutable::parse($candidateDay->toDateString().' '.$window['end_time'], $start->timezone);
                if (! empty($window['end_next_day']) || $until->lessThanOrEqualTo($from)) {
                    $until = $until->addDay();
                }
                if ($start->greaterThanOrEqualTo($from) && $end->lessThanOrEqualTo($until)) {
                    return true;
                }
            }
        }

        return false;
    }
}
