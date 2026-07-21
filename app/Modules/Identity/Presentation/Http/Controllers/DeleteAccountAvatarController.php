<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\DeleteProfileAvatarHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class DeleteAccountAvatarController extends Controller
{
    public function __invoke(
        Request $request,
        int $avatar,
        DeleteProfileAvatarHandler $deleteProfileAvatar,
    ): RedirectResponse {
        $profile = $request->user()?->profile;

        if ($profile === null) {
            throw new RuntimeException('Профиль пользователя не найден.');
        }

        abort_if($profile->user?->isBlocked(), 403);

        $deleteProfileAvatar->handle($profile, $avatar);

        return back()->with('avatar_status', 'Аватар удалён.');
    }
}
