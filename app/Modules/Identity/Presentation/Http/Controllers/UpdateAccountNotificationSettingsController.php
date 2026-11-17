<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserMessengerNotificationPreferenceEnum;
use App\Modules\Identity\Domain\Models\UserNotificationSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateAccountNotificationSettingsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email_notifications' => ['required', Rule::enum(UserMessengerNotificationPreferenceEnum::class)],
            'messenger_notifications' => ['required', Rule::enum(UserMessengerNotificationPreferenceEnum::class)],
        ]);

        $user = $request->user()->canonical();
        abort_if($user->isBlocked(), 403);

        UserNotificationSetting::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'email_notifications' => $data['email_notifications'],
                'messenger_notifications' => $data['messenger_notifications'],
            ],
        );

        return redirect()->route('account.settings')
            ->with('status', 'Настройки уведомлений сохранены.');
    }
}
