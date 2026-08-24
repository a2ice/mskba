<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use Illuminate\Support\Str;

final class TelegramEventMessageBuilder
{
    public function text(Event $event): string
    {
        $timezone = $event->venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
        $startsAt = $event->starts_at->setTimezone($timezone);
        $endsAt = $event->ends_at->setTimezone($timezone);
        $participants = $event->participants()
            ->where('status', EventParticipantStatusEnum::CONFIRMED->value)
            ->count();
        $capacity = $event->max_participants === null
            ? (string) $participants
            : "{$participants}/{$event->max_participants}";
        $description = trim((string) $event->description);
        $primaryGame = $this->primaryGame($event);

        $lines = [
            '🏀 <b>'.$this->escape($this->title($event)).'</b>',
            'Тип активности: '.$this->escape($event->type->label()),
        ];

        if ($event->type === EventTypeEnum::GAME && $primaryGame !== null) {
            $lines[] = 'Формат: '.$this->escape($this->gameFormat($primaryGame));
            if ($primaryGame->recruitment_mode !== null) {
                $lines[] = 'Набор: '.$this->escape($primaryGame->recruitment_mode->label());
            }

            if ($primaryGame->recruitment_mode === GameRecruitmentModeEnum::INDIVIDUAL_DRAFT) {
                [$accepted, $pending] = $this->individualPoolCounts($primaryGame);
                $lines[] = '👥 Набор игроков: принято '.$accepted.($pending > 0 ? ' · на рассмотрении '.$pending : '');
                $lines[] = '📝 Заявки: '.($this->canJoinIndividualGame($event, $primaryGame) ? 'принимаются' : 'закрыты');
            } elseif ($primaryGame->recruitment_mode === GameRecruitmentModeEnum::PREFORMED_TEAMS) {
                $teamNames = $this->preformedTeamNames($primaryGame);
                $lines[] = $teamNames === []
                    ? '🤝 Команды: формируются'
                    : '🤝 Команды: '.$this->escape(implode(' — ', $teamNames));
            }

            $scoreLine = $this->scoreLine($primaryGame);
            if ($scoreLine !== null) {
                $lines[] = $scoreLine;
            }
        }

        $lines[] = '📝 Описание: '.$this->escape(
            $description === '' ? '—' : Str::limit($description, 1000),
        );
        $lines[] = '';
        $lines[] = '📍 '.$this->escape($event->venue->name);
        $lines[] = '🗓 '.$this->dateTimeLabel($startsAt, $endsAt, $timezone);
        if ($event->type !== EventTypeEnum::GAME) {
            $lines[] = '👥 Участники: '.$capacity;
        }

        $responsibles = $event->participants
            ->filter(fn ($participant) => $participant->status === EventParticipantStatusEnum::CONFIRMED
                && $participant->confirmation_version === $event->participation_confirmation_version
                && $participant->responsibility_status === EventResponsibilityStatusEnum::ACCEPTED)
            ->map(fn ($participant): string => $this->userName($participant->user))
            ->values();
        if ($responsibles->isNotEmpty()) {
            $lines[] = '🛡 Ответственные: '.$this->escape($responsibles->join(', '));
        }

        if ($event->type !== EventTypeEnum::GAME && $event->games->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🎮 <b>Мини-игры: '.$event->games->count().'</b>';
            foreach ($event->games as $game) {
                $sides = $game->sides->keyBy('slot');
                $sideA = $sides->get('A');
                $sideB = $sides->get('B');
                $showScore = in_array($game->status, [
                    GameStatusEnum::IN_PROGRESS,
                    GameStatusEnum::AWAITING_RESULT,
                    GameStatusEnum::COMPLETED,
                ], true);
                $score = $showScore && $sideA?->score !== null && $sideB?->score !== null
                    ? "{$sideA->score}:{$sideB->score}"
                    : '—:—';
                $lines[] = '• <b>'.$this->escape($game->title ?: 'Игра #'.$game->id).'</b>';
                $lines[] = $this->escape($sideA?->display_name ?: 'Команда A')
                    .' <b>'.$score.'</b> '
                    .$this->escape($sideB?->display_name ?: 'Команда B');
            }
        }

        if ($event->status === EventStatusEnum::CANCELLED) {
            $lines[] = '';
            $lines[] = '🚫 <b>Мероприятие отменено</b>';
        } elseif ($event->status === EventStatusEnum::COMPLETED) {
            $lines[] = '';
            $lines[] = '✅ <b>Мероприятие состоялось</b>';
        } elseif ($primaryGame?->status === GameStatusEnum::IN_PROGRESS) {
            $lines[] = '';
            $lines[] = '🟢 <b>Игра идёт сейчас</b>';
        } elseif ($event->ends_at->lessThanOrEqualTo(now())) {
            $lines[] = '';
            $lines[] = '⏱ <b>Время мероприятия завершилось</b>';
        } elseif ($event->starts_at->lessThanOrEqualTo(now())) {
            $lines[] = '';
            $lines[] = '🟢 <b>Мероприятие уже началось</b>';
        }

        return implode("\n", $lines);
    }

    /** @return array<string, array<int, array<int, array<string, string>>>> */
    public function replyMarkup(Event $event): array
    {
        $rows = [];
        $participationButtons = $this->participationButtons($event);
        if ($participationButtons !== []) {
            $rows[] = $participationButtons;
        }

        $rows[] = [[
            'text' => '🏀 Открыть мероприятие',
            'url' => $this->eventUrl($event),
        ]];

        return ['inline_keyboard' => $rows];
    }

    /** @return list<array{text:string,callback_data:string}> */
    private function participationButtons(Event $event): array
    {
        if ($event->status !== EventStatusEnum::PUBLISHED
            || $event->visibility !== EventVisibilityEnum::PUBLIC) {
            return [];
        }

        $primaryGame = $this->primaryGame($event);
        if ($event->type === EventTypeEnum::GAME && $primaryGame?->recruitment_mode !== null) {
            if ($primaryGame->recruitment_mode === GameRecruitmentModeEnum::PREFORMED_TEAMS
                || ! $this->individualGameParticipationOpen($event, $primaryGame)) {
                return [];
            }

            $buttons = [];
            if ($primaryGame->accepts_applications) {
                $buttons[] = [
                    'text' => '✅ Пойду',
                    'callback_data' => "event:{$event->id}:join",
                ];
            }
            $buttons[] = [
                'text' => '❌ Не пойду',
                'callback_data' => "event:{$event->id}:leave",
            ];

            return $buttons;
        }

        if ($event->ends_at->lessThanOrEqualTo(now())) {
            return [];
        }

        return [
            [
                'text' => '✅ Пойду',
                'callback_data' => "event:{$event->id}:join",
            ],
            [
                'text' => '❌ Не пойду',
                'callback_data' => "event:{$event->id}:leave",
            ],
        ];
    }

    private function individualGameParticipationOpen(Event $event, Game $game): bool
    {
        if ($game->actual_ended_at !== null) {
            return false;
        }
        if ($game->status === GameStatusEnum::IN_PROGRESS) {
            return true;
        }

        return $game->status === GameStatusEnum::SCHEDULED
            && $event->ends_at->isFuture();
    }

    private function canJoinIndividualGame(Event $event, Game $game): bool
    {
        return $game->recruitment_mode === GameRecruitmentModeEnum::INDIVIDUAL_DRAFT
            && $game->accepts_applications
            && $this->individualGameParticipationOpen($event, $game)
            && $event->status === EventStatusEnum::PUBLISHED
            && $event->visibility === EventVisibilityEnum::PUBLIC;
    }

    private function primaryGame(Event $event): ?Game
    {
        if ($event->primary_game_id === null) {
            return null;
        }
        if ($event->relationLoaded('primaryGame')) {
            return $event->primaryGame;
        }
        if ($event->relationLoaded('games')) {
            return $event->games->firstWhere('id', (int) $event->primary_game_id);
        }

        return $event->primaryGame()
            ->with(['sides', 'admissions.user', 'admissions.team', 'rosterEntries'])
            ->first();
    }

    private function gameFormat(Game $game): string
    {
        if ($game->format === GameFormatEnum::CUSTOM) {
            return 'Свой формат · '.$game->formatLabel();
        }

        return $game->format?->label() ?? $game->formatLabel();
    }

    /** @return array{int,int} */
    private function individualPoolCounts(Game $game): array
    {
        $game->loadMissing('admissions.user');
        $individual = $game->admissions
            ->where('candidate_type', GameAdmissionCandidateTypeEnum::USER);
        $accepted = $individual
            ->where('status', GameAdmissionStatusEnum::ACCEPTED)
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->count();
        $pending = $individual
            ->where('status', GameAdmissionStatusEnum::PENDING)
            ->where('direction', GameAdmissionDirectionEnum::APPLICATION)
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->count();

        return [$accepted, $pending];
    }

    /** @return list<string> */
    private function preformedTeamNames(Game $game): array
    {
        $game->loadMissing(['sides', 'admissions.team']);
        if ($game->sides->isNotEmpty()) {
            return $game->sides
                ->sortBy('slot')
                ->map(fn ($side): string => $side->display_name ?: $side->team?->name ?: 'Команда '.$side->slot)
                ->values()
                ->all();
        }

        return $game->admissions
            ->where('candidate_type', GameAdmissionCandidateTypeEnum::TEAM)
            ->where('status', GameAdmissionStatusEnum::ACCEPTED)
            ->map(fn ($admission): ?string => $admission->team?->name)
            ->filter()
            ->unique()
            ->take(2)
            ->values()
            ->all();
    }

    private function scoreLine(Game $game): ?string
    {
        $game->loadMissing('sides');
        $sides = $game->sides->keyBy('slot');
        $sideA = $sides->get('A');
        $sideB = $sides->get('B');
        if ($sideA === null || $sideB === null) {
            return null;
        }

        return '🏀 '.$this->escape($sideA->display_name ?: 'Команда A')
            .' <b>'.((int) ($sideA->score ?? 0)).':'.((int) ($sideB->score ?? 0)).'</b> '
            .$this->escape($sideB->display_name ?: 'Команда B');
    }

    private function dateTimeLabel($startsAt, $endsAt, string $timezone): string
    {
        $date = $startsAt->isSameDay(now($timezone))
            ? 'Сегодня'
            : $startsAt->format('d.m.Y');
        $end = $endsAt->isSameDay($startsAt)
            ? $endsAt->format('H:i')
            : $endsAt->format('d.m H:i');

        return $date.', '.$startsAt->format('H:i').'–'.$end;
    }

    private function title(Event $event): string
    {
        return match ($event->type) {
            EventTypeEnum::GAME,
            EventTypeEnum::GAME_TRAINING => 'Играем на '.$event->venue->name,
            default => $event->title,
        };
    }

    private function eventUrl(Event $event): string
    {
        $botUsername = ltrim(trim((string) config('telegram.bot_username')), '@');

        if ($botUsername === '') {
            return route('events.show', $event->routeIdentifier());
        }

        return sprintf(
            'https://t.me/%s?startapp=%s',
            rawurlencode($botUsername),
            rawurlencode("event_{$event->id}"),
        );
    }

    private function userName($user): string
    {
        $profileName = trim(implode(' ', array_filter([
            $user?->profile?->first_name,
            $user?->profile?->last_name,
        ])));

        return $profileName !== ''
            ? $profileName
            : ($user?->username ?: 'Пользователь #'.$user?->id);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
