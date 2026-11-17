<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\HomepagePreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateHomepagePreferenceController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'homepage_preference' => ['required', 'string', Rule::in(array_keys(HomepagePreference::OPTIONS))],
        ]);

        $user = $request->user()->canonical();
        $user->forceFill(['homepage_preference' => $data['homepage_preference']])->save();

        return redirect()->route('account.settings')
            ->with('status', 'Главная страница сохранена.');
    }
}
