<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Team\Domain\Models\Team;
use Illuminate\Support\Collection;

/** Server-owned source allowlist. Never accept SQL or a model name from HTML. */
final class PageBuilderSources
{
    public const DESCRIPTIONS = [
        'upcoming_games' => 'Ближайшие публичные игры и тренировки',
        'my_games' => 'Мои предстоящие игры (только авторизованный пользователь)',
        'featured_teams' => 'Активные публичные команды',
    ];

    public function isRegistered(string $name): bool
    {
        return array_key_exists($name, self::DESCRIPTIONS);
    }

    public function requiresLogin(string $name): bool
    {
        return $name === 'my_games';
    }

    /** @return list<array{title:string,subtitle:string,url:string}> */
    public function load(string $name, ?User $user, int $limit): array
    {
        $limit = max(1, min(12, $limit));
        if ($name === 'featured_teams') {
            return Team::query()->competitionEligible()
                ->latest('updated_at')->limit($limit)->get(['id','name','alias','updated_at'])
                ->map(fn (Team $team): array => [
                    'title' => $team->name,
                    'subtitle' => 'Баскетбольная команда',
                    'url' => route('teams.show', $team->routeIdentifier()),
                ])->values()->all();
        }
        if ($name !== 'upcoming_games' && $name !== 'my_games') {
            abort(404);
        }
        if ($name === 'my_games' && ! $user) {
            abort(401);
        }
        $query = Event::query()->where('status', EventStatusEnum::PUBLISHED->value)
            ->where('starts_at', '>=', now());
        if ($name === 'upcoming_games') {
            $query->where('visibility', EventVisibilityEnum::PUBLIC->value)
                ->whereIn('type', [EventTypeEnum::GAME->value, EventTypeEnum::GAME_TRAINING->value]);
        } else {
            $query->whereHas('participants', fn ($q) => $q->whereIn('user_id', $user->canonical()->identityIds()));
        }
        return $query->orderBy('starts_at')->limit($limit)->get(['id','alias','title','starts_at'])
            ->map(fn (Event $event): array => [
                'title' => $event->title,
                'subtitle' => $event->starts_at?->format('d.m.Y H:i') ?? '',
                'url' => route('events.show', $event->routeIdentifier()),
            ])->values()->all();
    }
}
