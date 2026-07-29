<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Coordination\Domain\Enums\CoordinationFlowTypeEnum;
use App\Modules\Coordination\Domain\Enums\ParticipationIntentEnum;
use App\Modules\Coordination\Domain\Enums\PollResultsVisibilityEnum;
use App\Modules\Coordination\Domain\Enums\PollSelectionModeEnum;
use App\Modules\Coordination\Domain\Enums\PollStatusEnum;
use App\Modules\Coordination\Domain\Enums\PollSubjectTypeEnum;
use App\Modules\Coordination\Domain\Models\Poll;
use App\Modules\Coordination\Domain\Models\PollOption;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Carbon\CarbonImmutable;
use Throwable;

final class TelegramCoordinationMessageBuilder
{
    public function text(Poll $poll): string
    {
        $session = $poll->session;
        $lines = ['🏀 <b>'.$this->escape($session->title).'</b>'];

        if (trim($poll->question) !== '') {
            $lines[] = $this->escape($poll->question);
        }

        $lines[] = '';
        $showResults = $this->showsResults($poll);
        $optionsAreShownAsButtons = $poll->status === PollStatusEnum::OPEN
            && $poll->closes_at->isFuture()
            && $poll->selection_mode === PollSelectionModeEnum::SINGLE;

        array_push($lines, ...$this->eventAttendanceContext($poll));

        if (! $optionsAreShownAsButtons || $showResults) {
            foreach ($poll->options as $option) {
                $line = $this->optionMarker($poll, $option).' '
                    .$this->escape(mb_strimwidth($option->label, 0, 80, '…'));

                if ($showResults) {
                    $line .= ' — <b>'.$option->selections_count.'</b>';
                }

                $lines[] = $line;

                if ($showResults && ! $poll->is_anonymous) {
                    $voterNames = $option->selections
                        ->map(fn ($selection): string => $this->userName($selection->ballot->user))
                        ->filter()
                        ->unique();
                    $names = $voterNames
                        ->take(2)
                        ->implode(', ');

                    if ($names !== '') {
                        $remaining = $voterNames->count() - 2;
                        $lines[] = '   '.$this->escape($names)
                            .($remaining > 0 ? " и ещё {$remaining}" : '');
                    }
                }

                $lines[] = '';
            }
        }

        if ($lines[array_key_last($lines)] !== '') {
            $lines[] = '';
        }

        $lines[] = '⏱ До '.$this->formatDateTime($poll->closes_at);
        $lines[] = 'Статус: <b>'.$this->escape($poll->status->label()).'</b>';

        if ($poll->selection_mode === PollSelectionModeEnum::MULTIPLE && $poll->status === PollStatusEnum::OPEN) {
            $lines[] = 'Выберите несколько вариантов в Mini App.';
        }

        if ($session->decision !== null) {
            $lines[] = 'Решение: <b>'.$this->escape($session->decision->option->label).'</b>';
        }

        return implode("\n", $lines);
    }

    /** @return array{inline_keyboard: array<int, array<int, array<string, string>>>} */
    public function replyMarkup(Poll $poll): array
    {
        $keyboard = [];

        if ($poll->status === PollStatusEnum::OPEN
            && $poll->closes_at->isFuture()
            && $poll->selection_mode === PollSelectionModeEnum::SINGLE) {
            foreach ($poll->options as $option) {
                $label = mb_substr($option->label, 0, 50);

                if ($this->showsResults($poll)) {
                    $label .= ' ('.$option->selections_count.')';
                }

                $keyboard[] = [[
                    'text' => $label,
                    'callback_data' => "coord:{$poll->id}:vote:{$option->id}",
                ]];
            }
        }

        $keyboard[] = [[
            'text' => '🏀 Открыть опрос',
            'url' => $this->miniAppUrl($poll),
        ]];

        return ['inline_keyboard' => $keyboard];
    }

    private function optionMarker(Poll $poll, PollOption $option): string
    {
        if ($poll->subject_type !== PollSubjectTypeEnum::PARTICIPATION) {
            return '•';
        }

        $intent = ParticipationIntentEnum::tryFrom((string) ($option->value['intent'] ?? ''));

        return match ($intent) {
            ParticipationIntentEnum::GOING => '⇢',
            ParticipationIntentEnum::NOT_GOING => '⇢',
            ParticipationIntentEnum::THINKING => '⇢',
            default => '⇢',
        };
    }

    /** @return list<string> */
    private function eventAttendanceContext(Poll $poll): array
    {
        if ($poll->session->flow_type !== CoordinationFlowTypeEnum::EVENT_ATTENDANCE) {
            return [];
        }

        $configuration = $poll->configuration ?? [];
        $venueId = (int) ($configuration['venue_id'] ?? 0);
        $startsAtValue = $configuration['starts_at'] ?? null;

        if ($venueId < 1 || ! is_string($startsAtValue) || $startsAtValue === '') {
            return [];
        }

        $venue = Venue::withTrashed()->find($venueId);

        if ($venue === null) {
            return [];
        }

        try {
            $startsAt = CarbonImmutable::parse($startsAtValue)
                ->timezone((string) config('app.timezone', 'Europe/Moscow'));
        } catch (Throwable) {
            return [];
        }

        $duration = max(0, (int) ($configuration['duration_minutes'] ?? 0));
        $time = $startsAt->locale('ru')->translatedFormat('j F H:i');

        if ($duration > 0) {
            $time .= '–'.$startsAt->addMinutes($duration)->format('H:i');
        }

        return [
            '📍 <b>'.$this->escape($venue->name).'</b>',
            '🗓 '.$time,
            '',
        ];
    }

    private function showsResults(Poll $poll): bool
    {
        return $poll->results_visibility === PollResultsVisibilityEnum::ALWAYS
            || $poll->status !== PollStatusEnum::OPEN;
    }

    private function formatDateTime(CarbonImmutable $dateTime): string
    {
        return $dateTime
            ->timezone((string) config('app.timezone', 'Europe/Moscow'))
            ->locale('ru')
            ->translatedFormat('j F H:i');
    }

    private function miniAppUrl(Poll $poll): string
    {
        $botUsername = ltrim(trim((string) config('telegram.bot_username')), '@');

        return $botUsername !== ''
            ? "https://t.me/{$botUsername}?startapp=coordination_{$poll->session_id}"
            : route('coordination.show', $poll->session_id);
    }

    private function userName(User $user): string
    {
        $name = trim(implode(' ', array_filter([
            $user->profile?->first_name,
            $user->profile?->last_name,
        ])));

        return mb_strimwidth($name !== '' ? $name : $user->username, 0, 30, '…');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
