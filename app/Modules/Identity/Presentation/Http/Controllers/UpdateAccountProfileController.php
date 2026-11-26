<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Presentation\Http\Services\AccountProfileUpdater;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateAccountProfileController
{
    public function __invoke(Request $request, AccountProfileUpdater $updater): RedirectResponse|JsonResponse
    {
        abort_unless(app(ThemeResolver::class)->active() === 'mskba_app', 404);
        $data = $updater->update($request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Данные профиля сохранены.',
                'values' => $data,
            ]);
        }

        return redirect()->route('account.profile')->with('profile_status', 'Данные профиля сохранены.');
    }
}
