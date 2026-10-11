<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Services\ProfileChangeRequestService;
use App\Modules\Identity\Application\Services\ProfileChangeRequestNotificationService;
use App\Modules\Identity\Domain\Models\ProfileChangeRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AdminProfileChangeRequestsController
{
    public function index(): Response
    {
        return ThemeResolver::page('admin.profile-change-requests', [
            'requests' => ProfileChangeRequest::query()
                ->with('user')
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'blocked' THEN 1 ELSE 2 END")
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function review(Request $request, ProfileChangeRequest $profileChangeRequest, ProfileChangeRequestService $requests, ProfileChangeRequestNotificationService $notifications): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'block', 'unblock'])],
            'review_reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf(fn () => in_array($request->input('action'), ['reject', 'block'], true))],
        ]);

        if ($data['action'] === 'unblock') {
            $requests->unblock($request->user(), $profileChangeRequest);
        } else {
            $requests->review($request->user(), $profileChangeRequest, $data['action'], trim((string) ($data['review_reason'] ?? '')) ?: null);
        }

        $notifications->reviewed($profileChangeRequest->fresh(), $data['action']);

        return redirect()->route('admin.profile-change-requests')->with('success', 'Решение по заявке сохранено.');
    }
}
