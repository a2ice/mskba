<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Services\PublicUserProfileService;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;

final class PublicUserProfileController
{
    public function show(Request $request, string $user, PublicUserProfileService $profiles)
    {
        $role = $request->route('role');
        $requestedIdentifier = $user;
        $user = $this->resolve($user, (bool) $request->route('by_id'));
        $canonical = $user->canonical();
        abort_if($user->isBlocked() || $canonical->isBlocked() || $canonical->trashed(), 404);
        $data = $profiles->page($canonical, $request->user(), $role);
        $canonicalIdentifier = $canonical->nickname ?: $canonical->username;
        if (
            $canonical->id !== $user->id
            || ($request->route('by_id') && $canonicalIdentifier)
            || (! $request->route('by_id') && $canonicalIdentifier && strcasecmp($requestedIdentifier, $canonicalIdentifier) !== 0)
        ) {
            return redirect($profiles->url($canonical, $role), 301);
        }

        $viewer = $request->user()?->canonical();
        $isOwnProfile = $viewer !== null && (int) $viewer->id === (int) $canonical->id;

        return ThemeResolver::page('users.show', [
            'publicProfile' => $data,
            'isOwnProfile' => $isOwnProfile,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $user, PublicUserProfileService $profiles)
    {
        $user = $this->resolve($user, (bool) $request->route('by_id'));
        abort_if($user->isBlocked(), 404);

        return response()->json(['user' => $profiles->preview($user, $request->user())])->header('Cache-Control', 'private, no-store');
    }

    private function resolve(string $identifier, bool $byId): User
    {
        if ($byId) {
            return User::query()->findOrFail($identifier);
        }

        $normalized = strtolower($identifier);

        return User::query()->whereRaw('LOWER(nickname) = ?', [$normalized])->first()
            ?? User::query()->whereRaw('LOWER(username) = ?', [$normalized])->firstOrFail();
    }
}
