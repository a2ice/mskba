<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\StoreProfileAvatarHandler;
use App\Modules\Identity\Presentation\Http\Requests\StoreAccountAvatarRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

final class AccountAvatarController extends Controller
{
    public function __invoke(
        StoreAccountAvatarRequest $request,
        StoreProfileAvatarHandler $storeProfileAvatar,
    ): JsonResponse|RedirectResponse {
        $profile = $request->user()?->profile;

        if ($profile === null) {
            throw new RuntimeException('Профиль пользователя не найден.');
        }

        abort_if($profile->user?->isBlocked(), 403);

        $file = $request->file('avatar');
        $path = $file?->getRealPath();
        $contents = is_string($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Не удалось прочитать изображение.'], 422)
                : back()->with('avatar_error', 'Не удалось прочитать изображение.');
        }

        try {
            $storeProfileAvatar->handle($profile, $contents);
        } catch (\InvalidArgumentException|RuntimeException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 422)
                : back()->with('avatar_error', $exception->getMessage());
        }

        return $request->expectsJson()
            ? AccountAvatarJsonResponse::success($profile, 'Аватар обновлён.')
            : back()->with('avatar_status', 'Аватар обновлён.');
    }
}
