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
        $user = $this->resolve($user, (bool) $request->route('by_id'));
        $canonical = $user->canonical();
        abort_if($user->isBlocked() || $canonical->isBlocked() || $canonical->trashed(), 404);
        $data = $profiles->page($canonical, $request->user(), $role);
        if ($canonical->id !== $user->id || ($request->route('by_id') && $canonical->username)) {
            return redirect($profiles->url($canonical, $role), 301);
        }

        return ThemeResolver::page('users.show', ['publicProfile' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $user, PublicUserProfileService $profiles)
    {
        $user = $this->resolve($user, (bool) $request->route('by_id'));
        abort_if($user->isBlocked(), 404);

        return response()->json(['user' => $profiles->preview($user, $request->user())])->header('Cache-Control', 'private, no-store');
    }

    private function resolve(string $identifier, bool $byId): User
    {
        return $byId
            ? User::query()->findOrFail($identifier)
            : User::query()->where('username', $identifier)->firstOrFail();
    }
}
