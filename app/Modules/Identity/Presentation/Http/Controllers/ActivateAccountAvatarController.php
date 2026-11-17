<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\ActivateProfileAvatarHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ActivateAccountAvatarController extends Controller
{
    public function __invoke(
        Request $request,
        int $avatar,
        ActivateProfileAvatarHandler $activateProfileAvatar,
    ): JsonResponse|RedirectResponse {
        $profile = $request->user()?->profile;

        if ($profile === null) {
            throw new RuntimeException('Профиль пользователя не найден.');
        }

        abort_if($profile->user?->isBlocked(), 403);

        $activateProfileAvatar->handle($profile, $avatar);

        return $request->expectsJson()
            ? AccountAvatarJsonResponse::success($profile, 'Активный аватар изменён.')
            : back()->with('avatar_status', 'Активный аватар изменён.');
    }
}
