<?php

namespace App\Modules\Team\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Team\Application\Services\TeamManagementAccess;
use App\Modules\Team\Domain\Enums\TeamPermissionEnum;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TeamSettingsController extends Controller
{
    private const array COLOR_KEYS = [
        'home_primary',
        'home_secondary',
        'away_primary',
        'away_secondary',
    ];

    public function updateApplications(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): RedirectResponse {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::EDIT_SETTINGS), 403);

        $data = $request->validate([
            'accepts_join_requests' => ['required', 'boolean'],
            'accepts_competition_invitations' => ['sometimes', 'boolean'],
        ]);
        $updates = ['accepts_join_requests' => (bool) $data['accepts_join_requests']];
        if (array_key_exists('accepts_competition_invitations', $data)) {
            $updates['accepts_competition_invitations'] = (bool) $data['accepts_competition_invitations'];
        }
        $item->update($updates);

        return back()->with('status', 'Настройки команды обновлены.');
    }

    public function updateColors(
        string $team,
        Request $request,
        CurrentActorResolver $actors,
        TeamManagementAccess $access,
    ): RedirectResponse {
        $item = Team::query()->whereRouteIdentifier($team)->firstOrFail();
        $actor = $actors->resolveForRequest($request);
        abort_if($actor === null || ! $access->allows($item, $actor, TeamPermissionEnum::EDIT_SETTINGS), 403);

        $rules = ['colors' => ['nullable', 'array']];
        foreach (self::COLOR_KEYS as $key) {
            $rules["colors.{$key}"] = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        }

        $data = $request->validate($rules);
        $input = $data['colors'] ?? [];
        $colors = [];

        foreach (self::COLOR_KEYS as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $colors[$key] = strtolower($value);
            }
        }

        $item->update(['colors' => $colors === [] ? null : $colors]);

        return back()->with('status', 'Цвета команды обновлены.');
    }
}
