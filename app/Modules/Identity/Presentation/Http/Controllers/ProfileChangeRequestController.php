<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Services\ProfileChangeRequestNotificationService;
use App\Modules\Identity\Application\Services\ProfileChangeRequestService;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProfileChangeRequestController
{
    public function store(Request $request, ProfileChangeRequestService $requests, ProfileChangeRequestNotificationService $notifications): RedirectResponse
    {
        $scope = $request->input('scope', ProfileChangeRequest::SCOPE_ACCOUNT);
        abort_unless(in_array($scope, [ProfileChangeRequest::SCOPE_ACCOUNT, ProfileChangeRequest::SCOPE_PLAYER], true), 422);

        $data = $request->validate($scope === ProfileChangeRequest::SCOPE_PLAYER ? [
            'height_cm' => ['required', 'integer', 'between:150,220'],
            'experience_started_year' => ['required', 'integer', 'between:'.(now()->year - 50).','.(now()->year - 10)],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ] : [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['required', Rule::enum(UserGenderEnum::class)],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $proposed = $scope === ProfileChangeRequest::SCOPE_PLAYER
            ? [
                'height_cm' => (int) $data['height_cm'],
                'experience_started_year' => (int) $data['experience_started_year'],
            ]
            : [
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'birth_date' => $data['birth_date'],
                'gender' => $data['gender'],
            ];
        if ($scope === ProfileChangeRequest::SCOPE_ACCOUNT
            && ($proposed['first_name'] === '' || $proposed['last_name'] === '')) {
            return back()->withErrors(['reason' => 'Имя и фамилия не могут быть пустыми.'])->withInput();
        }

        $created = $requests->submit($request->user(), $proposed, trim($data['reason']), $scope);
        $notifications->submitted($created);

        return redirect()->route($scope === ProfileChangeRequest::SCOPE_PLAYER
            ? 'account.participation-role' : 'account.profile',
            $scope === ProfileChangeRequest::SCOPE_PLAYER ? ['role' => 'player'] : [])
            ->with('profile_status', 'Заявка на изменение данных отправлена на рассмотрение.');
    }

    public function cancel(Request $request, ProfileChangeRequest $profileChangeRequest, ProfileChangeRequestService $requests,
        ProfileChangeRequestNotificationService $notifications): RedirectResponse
    {
        $requests->cancel($request->user(), $profileChangeRequest);
        $notifications->notifyThreadChanged($profileChangeRequest, true);

        $threadReturn = $request->input('return_to') === 'thread';
        $playerReturn = $profileChangeRequest->scope === ProfileChangeRequest::SCOPE_PLAYER;

        return redirect()->route(
            $threadReturn ? 'account.notifications.threads.profile'
                : ($playerReturn ? 'account.participation-role' : 'account.profile'),
            $threadReturn ? ['changeRequest' => $profileChangeRequest->id] : ($playerReturn ? ['role' => 'player'] : [])
        )->with('profile_status', 'Заявка отменена. Ты можешь отправить новую.');
    }
}
