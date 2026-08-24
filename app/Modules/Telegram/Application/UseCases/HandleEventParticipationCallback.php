<?php

namespace App\Modules\Telegram\Application\UseCases;

use App\Modules\Event\Application\Services\GameAdmissionService;
use App\Modules\Event\Application\Services\StandaloneGamePlayerWithdrawalService;
use App\Modules\Event\Application\Services\StandaloneGameQrJoinService;
use App\Modules\Event\Application\UseCases\DeclineEventHandler;
use App\Modules\Event\Application\UseCases\JoinEventHandler;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionCandidateTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Enums\GameRecruitmentModeEnum;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Application\DTO\TelegramUserIdentityDTO;
use App\Modules\Telegram\Domain\Models\TelegramEventPublication;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use InvalidArgumentException;
use Throwable;

final class HandleEventParticipationCallback
{
    public function __construct(
        private readonly JoinEventHandler $join,
        private readonly DeclineEventHandler $decline,
        private readonly StandaloneGameQrJoinService $gameJoin,
        private readonly StandaloneGamePlayerWithdrawalService $gameWithdrawal,
        private readonly GameAdmissionService $gameAdmissions,
        private readonly CurrentActorResolver $actors,
        private readonly ResolveTelegramUserHandler $resolveTelegramUser,
        private readonly TelegramBotApiClient $telegram,
    ) {}

    /** @param array<string, mixed> $callback */
    public function handle(array $callback): void
    {
        $callbackId = data_get($callback, 'id');
        $telegramUserId = data_get($callback, 'from.id');
        $telegramUser = data_get($callback, 'from');
        $chatId = data_get($callback, 'message.chat.id');
        $messageId = data_get($callback, 'message.message_id');
        $data = data_get($callback, 'data');

        if (! is_string($callbackId)
            || ! is_numeric($telegramUserId)
            || ! is_array($telegramUser)
            || ! is_numeric($chatId)
            || ! is_numeric($messageId)
            || ! is_string($data)
            || preg_match('/^event:(\d+):(join|leave)$/', $data, $matches) !== 1) {
            return;
        }

        $eventId = (int) $matches[1];
        $action = $matches[2];
        $publication = TelegramEventPublication::query()
            ->where('event_id', $eventId)
            ->where('chat_id', (string) $chatId)
            ->where('message_id', (int) $messageId)
            ->first();

        if ($publication === null) {
            $this->answer($callbackId, 'Это сообщение больше не связано с мероприятием.', true);

            return;
        }

        $event = Event::query()->find($eventId);

        if ($event === null) {
            $this->answer($callbackId, 'Мероприятие больше недоступно.', true);

            return;
        }

        try {
            $resolved = $this->resolveTelegramUser->handle(new TelegramUserIdentityDTO(
                id: (int) $telegramUserId,
                username: $this->nullableString(data_get($telegramUser, 'username')),
                firstName: $this->nullableString(data_get($telegramUser, 'first_name')),
                lastName: $this->nullableString(data_get($telegramUser, 'last_name')),
                languageCode: $this->nullableString(data_get($telegramUser, 'language_code')),
                photoUrl: null,
                rawData: ['user' => $telegramUser],
                source: 'telegram_chat_callback',
                registrationChannel: UserRegistrationChannelEnum::TELEGRAM_CHAT,
            ));
            $sourceUser = $resolved['user'];
            $user = $sourceUser->canonical();

            if ($sourceUser->isBlocked() || $user->isBlocked()) {
                throw new InvalidArgumentException('Ваш аккаунт заблокирован.');
            }

            $game = $this->configuredStandaloneGame($event);
            if ($game?->recruitment_mode === GameRecruitmentModeEnum::PREFORMED_TEAMS) {
                throw new InvalidArgumentException('В этой игре участвуют готовые команды. Индивидуальные заявки не используются.');
            }

            if ($game?->recruitment_mode === GameRecruitmentModeEnum::INDIVIDUAL_DRAFT) {
                $message = $this->handleIndividualGame($game, $user, $action);
            } elseif ($action === 'join') {
                $this->join->handle($event->routeIdentifier(), $user);
                $message = 'Отлично, что и ты с нами!';
            } else {
                $this->decline->handle($event->routeIdentifier(), $user);
                $message = 'Жаль. Тогда в следующий раз!';
            }

            if ($resolved['created']) {
                $message .= ' Аккаунт MSKBA создан.';
            }

            $this->answer($callbackId, $message);
        } catch (InvalidArgumentException $exception) {
            $this->answer($callbackId, $exception->getMessage(), true);
        } catch (Throwable $exception) {
            report($exception);
            $this->answer($callbackId, 'Не удалось сохранить ответ. Попробуйте ещё раз.', true);
        }
    }

    private function configuredStandaloneGame(Event $event): ?Game
    {
        if ($event->type !== EventTypeEnum::GAME || $event->primary_game_id === null) {
            return null;
        }

        return $event->primaryGame()->first();
    }

    private function handleIndividualGame(Game $game, User $user, string $action): string
    {
        $actor = $this->actors->resolve($user, null)
            ?? throw new InvalidArgumentException('Не удалось определить участника игры.');

        if ($action === 'leave') {
            return $this->gameWithdrawal->withdraw($game, $actor)
                ? 'Участие в игре отменено.'
                : 'Вы и так не участвуете в этой игре.';
        }

        $admission = $this->activeIndividualAdmission($game, $user);
        if ($admission?->status === GameAdmissionStatusEnum::ACCEPTED) {
            return 'Вы уже участвуете в этой игре.';
        }
        if ($admission?->status === GameAdmissionStatusEnum::PENDING) {
            if ($admission->direction === GameAdmissionDirectionEnum::APPLICATION) {
                return 'Заявка уже отправлена организатору.';
            }

            try {
                $this->gameAdmissions->respond(
                    $game,
                    $admission,
                    $actor,
                    GameAdmissionStatusEnum::ACCEPTED,
                );
                event(new EventChanged($game->event_id));

                return 'Приглашение на игру принято.';
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException('У вас уже есть приглашение на эту игру. Откройте мероприятие, чтобы продолжить.');
            }
        }

        $this->gameJoin->apply($game, $actor);

        return 'Заявка отправлена организатору.';
    }

    private function activeIndividualAdmission(Game $game, User $user): ?GameAdmission
    {
        return $game->admissions()
            ->where('candidate_type', GameAdmissionCandidateTypeEnum::USER->value)
            ->whereIn('user_id', $user->identityIds())
            ->whereIn('status', [
                GameAdmissionStatusEnum::PENDING->value,
                GameAdmissionStatusEnum::ACCEPTED->value,
            ])
            ->latest('id')
            ->first();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function answer(string $callbackId, string $message, bool $alert = false): void
    {
        $this->telegram->call('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => mb_substr($message, 0, 200),
            'show_alert' => $alert,
            'cache_time' => 0,
        ]);
    }
}
