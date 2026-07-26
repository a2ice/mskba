<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;

class AccountCheckForPresentationService
{
    public function __construct(
        private readonly ThemeResolver $themeResolver,
    ) {}

    public function handle(?User $user): User
    {
        if (! $user) {
            throw new \Exception('Вы не авторизованы. Пожалуйста, войдите, чтобы увидеть свой профиль.', 401);
        }

        if ($user->isBlocked()) {
            throw new \Exception('Ваш аккаунт заблокирован. Пожалуйста, обратитесь в поддержку для получения дополнительной информации.', 403);
        }

        return $user;
    }
}
