<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacyVisibilityEnum;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateWalletTransferRequestPrivacyController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['allow_requests' => ['sometimes', 'boolean']]);
        $user = $request->user()->canonical();
        abort_if($user->isBlocked(), 403);
        $user->privacySettings()->updateOrCreate(
            ['type' => UserPrivacySettingTypeEnum::WALLET_TRANSFER_REQUESTS->value],
            ['visibility' => ($data['allow_requests'] ?? false)
                ? UserPrivacyVisibilityEnum::EVERYONE->value
                : UserPrivacyVisibilityEnum::NOBODY->value],
        );
        return back()->with('status', 'Настройка запросов на перевод обновлена.');
    }
}
