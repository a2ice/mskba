<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Services\NicknameSuggestionService;
use App\Modules\Identity\Application\Services\PublicUserProfileService;
use App\Modules\Identity\Domain\Enums\UserGenderEnum;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AccountProfileController
{
    public function __invoke(
        Request $request,
        PublicUserProfileService $publicProfiles,
        NicknameSuggestionService $suggestions,
    ): Response|RedirectResponse {
        if (app(ThemeResolver::class)->active() !== 'mskba_app') {
            return redirect()->route('account');
        }

        $user = $request->user()->canonical()->load('profile.avatars', 'profile.activeAvatar');

        return ThemeResolver::page('account.profile', [
            'user' => $user,
            'profile' => $user->profile,
            'avatars' => $user->profile?->avatars ?? collect(),
            'publicProfileUrl' => $publicProfiles->url($user),
            'nicknameSuggestion' => $suggestions->suggest($user),
            'genders' => UserGenderEnum::cases(),
            'profileConfirmed' => $user->isConfirmed(),
        ]);
    }
}
