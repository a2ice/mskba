<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Services\PublicUserProfileService;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;

final class ParticipantCatalogController
{
    public function __invoke(Request $request, PublicUserProfileService $profiles): Response
    {
        $presetRoleValue = (string) ($request->route('participant_role') ?? '');
        $presetRole = $presetRoleValue !== '' ? UserParticipationRoleEnum::tryFrom($presetRoleValue) : null;

        $requestedRoleValue = $presetRole?->value ?? trim((string) $request->query('role', ''));
        $role = $requestedRoleValue !== '' ? UserParticipationRoleEnum::tryFrom($requestedRoleValue) : null;
        $search = trim((string) $request->query('q', ''));

        $candidates = User::query()
            ->whereNull('canonical_user_id')
            ->where('status', UserStatusEnum::CONFIRMED->value)
            ->whereHas('participationRoles', fn ($query) => $query
                ->when($role !== null, fn ($roleQuery) => $roleQuery->where('role', $role->value)))
            ->with([
                'profile.activeAvatar',
                'telegramAccount',
                'vkAccount',
                'participationRoles',
            ])
            ->get();

        $viewer = $request->user()?->canonical();
        $items = $candidates
            ->map(fn (User $user) => $profiles->catalogEntry($user, $viewer, $role))
            ->filter()
            ->when($search !== '', function ($items) use ($search) {
                $needle = mb_strtolower($search);

                return $items->filter(function (array $item) use ($needle): bool {
                    $haystack = mb_strtolower(trim($item['name'].' '.($item['nickname'] ?? '')));

                    return str_contains($haystack, $needle);
                });
            })
            ->sortBy(fn (array $item): string => mb_strtolower($item['name']))
            ->values();

        $page = max(1, $request->integer('page', 1));
        $perPage = 18;
        $participants = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return ThemeResolver::page('participants.index', [
            'participants' => $participants,
            'filters' => [
                'q' => $search,
                'role' => $role?->value,
            ],
            'roleOptions' => UserParticipationRoleEnum::cases(),
            'presetRole' => $presetRole,
        ])->header('Cache-Control', 'private, no-store');
    }
}
