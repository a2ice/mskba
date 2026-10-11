<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationDeliveryCategoryEnum;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;

final class ProfileChangeRequestNotificationService
{
    public function __construct(
        private readonly CreateUserNotificationHandler $notifications,
    ) {}

    public function submitted(ProfileChangeRequest $request): void
    {
        // Notify reviewers without including the private proposed profile values.
        $reviewers = User::query()
            ->where('system_role', UserSystemRoleEnum::SUPERADMIN->value)
            ->where('status', UserStatusEnum::CONFIRMED->value)
            ->pluck('id');

        foreach ($reviewers as $id) {
            if ((int) $id === (int) $request->user_id) {
                continue;
            }
            $this->send((int) $id, 'Новая заявка на изменение профиля',
                'Поступила заявка на изменение подтверждённых данных пользователя.',
                route('admin.profile-change-requests'), 'Рассмотреть заявку',
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

        $this->send($request->user_id, $title, $body, route('account.profile'),
            'Открыть профиль', 'identity.profile_change.'.$action, $request->id);
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
