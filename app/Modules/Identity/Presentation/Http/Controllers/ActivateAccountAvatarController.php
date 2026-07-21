<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\ActivateProfileAvatarHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ActivateAccountAvatarController extends Controller
{
    public function __invoke(
        Request $request,
        int $avatar,
        ActivateProfileAvatarHandler $activateProfileAvatar,
    ): RedirectResponse {
        $profile = $request->user()?->profile;

        if ($profile === null) {
            throw new RuntimeException('Профиль пользователя не найден.');
        }

        abort_if($profile->user?->isBlocked(), 403);

        $activateProfileAvatar->handle($profile, $avatar);

        return back()->with('avatar_status', 'Активный аватар изменён.');
    }
}
