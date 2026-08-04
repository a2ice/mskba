<?php

namespace App\Modules\Event\Infrastructure\Http\Middleware;

use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Team\Domain\Enums\TeamMemberTypeEnum;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureGameRosterContainsPlayers
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route()?->getName() !== 'events.game.roster') {
            return $next($request);
        }

        $identifier = (string) $request->route('event');
        $game = Event::query()
            ->whereRouteIdentifier($identifier)
            ->with(['gameSides.team'])
            ->firstOrFail();

        // A mini-game roster is formed from confirmed event participants rather
        // than permanent team memberships, so team sport roles do not apply.
        if ($game->parent_event_id !== null) {
            return $next($request);
        }

        $sides = $game->gameSides->keyBy('slot');
        foreach (['A', 'B'] as $slot) {
            $side = $sides->get($slot);
            if ($side?->team === null) {
                continue;
            }

            $allowedUserIds = $side->team->memberships()
                ->whereHas('contract', fn ($query) => $query->where('status', ContractStatusEnum::ACTIVE->value))
                ->where(function ($query): void {
                    $query->whereNull('member_type')
                        ->orWhere('member_type', TeamMemberTypeEnum::PLAYER->value);
                })
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id);

            $selectedUserIds = collect($request->input('side_'.strtolower($slot).'_user_ids', []))
                ->map(fn ($id) => (int) $id);

            if ($selectedUserIds->diff($allowedUserIds)->isNotEmpty()) {
                return $this->reject(
                    $request,
                    'В игровой состав можно включать только участников команды с типом «Игрок».',
                );
            }
        }

        return $next($request);
    }

    private function reject(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->withInput()->with('error', $message);
    }
}
