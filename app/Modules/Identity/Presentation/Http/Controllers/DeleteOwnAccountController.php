<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\UseCases\DeleteOwnAccountHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DeleteOwnAccountController extends Controller
{
    public function __invoke(Request $request, DeleteOwnAccountHandler $deleteAccount): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null, 403);

        $deleteAccount->handle($user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('welcome')
            ->with('status', 'Аккаунт удалён.');
    }
}
