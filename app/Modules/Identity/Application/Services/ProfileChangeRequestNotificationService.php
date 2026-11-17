<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\Services\ProfileRequestThreadAccess;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChangedBroadcast;

final class ProfileChangeRequestNotificationService
{
    public function __construct(
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    public function submitted(ProfileChangeRequest $request): void
    {
        // Notify reviewers without including the private proposed profile values.
        $reviewers = User::query()->where('status', UserStatusEnum::CONFIRMED->value)
            ->where(function ($q) use ($request): void {
                $q->where('system_role', UserSystemRoleEnum::SUPERADMIN->value)
                    ->orWhere(function ($admin) use ($request): void {
                        $admin->where('system_role', UserSystemRoleEnum::ADMIN->value)
                            ->whereHas('operationalPermissions', fn ($p) => $p
                                ->where('permission', UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES->value)
                                ->where('is_allowed', true))
                            ->whereExists(function ($cursor) use ($request): void {
                                $cursor->selectRaw('1')->from('review_permission_cursors')
                                    ->whereColumn('review_permission_cursors.user_id', 'users.id')
                                    ->where('permission', UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES->value)
                                    ->where('after_request_id', '<', $request->id);
                            });
                    });
            })->whereDoesntHave('operationalPermissions', fn ($p) => $p
            ->where('permission', UserOperationalPermissionEnum::REVIEW_PROFILE_CHANGES->value)
            ->where('is_allowed', false))->pluck('id');

        foreach ($reviewers as $id) {
            if ((int) $id === (int) $request->user_id) {
                continue;
            }
            $this->send((int) $id, 'Новая заявка на изменение профиля',
                'Поступила заявка на изменение подтверждённых данных пользователя.',
                route('account.notifications.threads.profile', $request->id), 'Рассмотреть заявку',
                'identity.profile_change.submitted', $request->id);
        }
    }

    public function reviewed(ProfileChangeRequest $request, string $action): void
    {
        [$title, $body] = match ($action) {
            'approve' => ['Заявка на изменение профиля одобрена', 'Изменения подтверждённых данных применены.'],
            'reject' => ['Заявка на изменение профиля отклонена', 'Изменение отклонено. Можно подать новую заявку.'],
            'block' => ['Заявки на изменение профиля заблокированы', 'Повторная подача недоступна до разблокировки администратором.'],
            'unblock' => ['Подача заявок на изменение профиля разблокирована', 'Ты снова можешь отправить заявку на исправление данных.'],
            default => ['Статус заявки изменён', 'Статус твоей заявки изменён.'],
        };

        if ($action !== 'unblock' && filled($request->review_reason)) {
            $body .= ' Причина: '.$request->review_reason;
        }

        $this->send($request->user_id, $title, $body, route('account.notifications.threads.profile', $request->id),
            'Открыть заявку', 'identity.profile_change.'.$action, $request->id);
        $this->notifyOtherReviewers($request, $request->reviewed_by_user_id,
            'Решение по заявке изменено', 'Статус заявки на изменение данных обновлён.', 'identity.profile_change.'.$action);
        $this->notifyThreadChanged($request, true);
    }

    public function notifyThreadChanged(ProfileChangeRequest $request, bool $accessChanged = false): void
    {
        if ($request->thread?->id !== null) {
            try {
                ConversationChangedBroadcast::dispatch('profile', $request->thread->id, $accessChanged);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    public function messageSent(ProfileChangeRequest $request, User $sender): void
    {
        $thread = $request->thread;
        if ($thread === null) {
            return;
        }
        $this->notifyOtherReviewers($request, $sender->canonical()->id,
            'Новое сообщение по заявке', 'Появилось новое сообщение по заявке на изменение данных.',
            'identity.profile_change.message');
        if ((int) $request->user_id !== (int) $sender->canonical()->id) {
            $this->send($request->user_id, 'Новое сообщение по заявке',
                'Появилось новое сообщение по заявке на изменение данных.',
                route('account.notifications.threads.profile', $request->id),
                'Открыть переписку', 'identity.profile_change.message', $request->id);
        }
    }

    /** Trigger only after the membership transaction commits. */
    public function claimed(ProfileChangeRequest $request): void
    {
        $this->send($request->user_id, 'Заявка взята в работу',
            'Уполномоченный сотрудник начал рассмотрение заявки на изменение данных.',
            route('account.notifications.threads.profile', $request->id), 'Открыть обсуждение',
            'identity.profile_change.claimed', $request->id);
    }

    public function invited(ProfileChangeRequest $request, User $target): void
    {
        $this->send($target->canonical()->id, 'Приглашение к рассмотрению заявки',
            'Тебя пригласили к рассмотрению конкретной заявки на изменение данных.',
            route('account.notifications.threads.profile', $request->id), 'Перейти к заявке',
            'identity.profile_change.invited', $request->id);
    }

    public function leadTransferred(ProfileChangeRequest $request, User $target): void
    {
        $this->send($target->canonical()->id, 'Передано руководство рассмотрением',
            'Теперь ты главный ответственный за рассмотрение заявки на изменение данных.',
            route('account.notifications.threads.profile', $request->id), 'Перейти к заявке',
            'identity.profile_change.lead_transferred', $request->id);
    }

    public function excluded(ProfileChangeRequest $request, User $target): void
    {
        // A neutral notice links to the tombstone; no names or private values.
        $this->send($target->canonical()->id, 'Участие в рассмотрении завершено',
            'Доступ к обсуждению заявки прекращён. Карточка остаётся в списке сообщений.',
            route('account.notifications.threads.profile', $request->id), 'Посмотреть статус',
            'identity.profile_change.excluded', $request->id);
    }

    public function leadRescued(ProfileChangeRequest $request): void
    {
        $this->send($request->user_id, 'Ответственный по заявке изменён',
            'Для рассмотрения заявки на изменение данных назначен новый ответственный.',
            route('account.notifications.threads.profile', $request->id), 'Открыть обсуждение',
            'identity.profile_change.lead_rescued', $request->id);
    }

    private function notifyOtherReviewers(ProfileChangeRequest $request, ?int $actorId,
        string $title, string $body, string $source): void
    {
        $thread = $request->thread;
        if ($thread === null) {
            return;
        }
        $recipients = User::query()->whereIn('id', $thread->memberships()->whereNull('removed_at')
            ->pluck('user_id'))->get();
        $access = app(ProfileRequestThreadAccess::class);
        foreach ($recipients as $reviewer) {
            if ($reviewer->id !== $actorId && $reviewer->id !== $request->user_id
                && $access->canRead($reviewer, $request, $thread)) {
                $this->send($reviewer->id, $title, $body,
                    route('account.notifications.threads.profile', $request->id),
                    'Открыть переписку', $source, $request->id);
            }
        }
    }

    private function send(int $userId, string $title, string $body, string $url, string $actionText, string $source, int $requestId): void
    {
        $this->notifications->handle(new CreateUserNotificationDTO(
            userId: $userId,
            type: UserNotificationTypeEnum::PROFILE,
            title: $title,
            body: $body,
            actionUrl: $url,
            actionText: $actionText,
            payload: [
                'source' => $source,
                'profile_change_request_id' => $requestId,
                'delivery_category' => UserNotificationDeliveryCategoryEnum::REQUEST->value,
            ],
        ));
    }
}
