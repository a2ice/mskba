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
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['required', Rule::enum(UserGenderEnum::class)],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $proposed = [
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'birth_date' => $data['birth_date'],
            'gender' => $data['gender'],
        ];
        if ($proposed['first_name'] === '' || $proposed['last_name'] === '') {
            return back()->withErrors(['reason' => 'Имя и фамилия не могут быть пустыми.'])->withInput();
        }

        $created = $requests->submit($request->user(), $proposed, trim($data['reason']));
        $notifications->submitted($created);

        return redirect()->route('account.profile')->with('profile_status', 'Заявка на изменение данных отправлена на рассмотрение.');
    }

    public function cancel(Request $request, ProfileChangeRequest $profileChangeRequest, ProfileChangeRequestService $requests,
        ProfileChangeRequestNotificationService $notifications): RedirectResponse
    {
        $requests->cancel($request->user(), $profileChangeRequest);
        $notifications->notifyThreadChanged($profileChangeRequest, true);

        return redirect()->route(
            $request->input('return_to') === 'thread' ? 'account.notifications.threads.profile' : 'account.profile',
            $request->input('return_to') === 'thread' ? ['changeRequest' => $profileChangeRequest->id] : []
        )->with('profile_status', 'Заявка отменена. Ты можешь отправить новую.');
    }
}
