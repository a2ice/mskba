<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Event\Domain\Models\GameRosterEntry;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum as Privacy;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Tournament\Domain\Models\Tournament;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Explicit public projection: internal account attributes never reach the client. */
final class PublicUserProfileService
{
    public function __construct(private readonly UserPrivacyAccessService $privacy) {}

    public function url(User $user, ?string $role = null, bool $preview = false): string
    {
        $user = $user->canonical();
        $identifier = $user->nickname ?: $user->username;
        $byId = $identifier === null;

        return route(
            'users.'.($byId ? 'id.' : '').($preview ? 'preview' : ($role === null ? 'show' : 'role')),
            ['user' => $identifier ?: $user->id, 'role' => $role],
        );
    }

    public function canListPlayer(User $subject, ?User $viewer): bool
    {
        $subject = $subject->canonical();

        return ! $subject->isBlocked() && ! $subject->trashed() && $subject->hasActiveRole('player')
            && $this->privacy->allows($subject, $viewer, Privacy::PROFILE)
            && $this->privacy->allows($subject, $viewer, Privacy::ROLE_PLAYER)
            && $this->privacy->allows($subject, $viewer, Privacy::PLAYER_SECTIONS);
    }

    public function sections(User $user): Collection
    {
        $user = $user->canonical();
        if (! config('features.sports_sections.enabled') || $user->isBlocked() || $user->trashed() || ! $user->hasActiveRole('coach')) {
            return collect();
        }

        return SportsSection::query()->where('status', 'active')
            ->whereIn('id', $this->memberships($user, 'sports_section', 'coach')->select('scope_id'))
            ->orderBy('name')->get();
    }

    public function preview(User $subject, ?User $viewer): array
    {
        $subject = $subject->canonical();
        abort_if($subject->isBlocked() || $subject->trashed(), 404);
        $sections = $this->sections($subject);
        $publicCoach = $sections->isNotEmpty()
            && $this->privacy->allowsDistribution($subject, Privacy::PROFILE)
            && $this->privacy->allowsDistribution($subject, Privacy::ROLE_COACH)
            && $this->privacy->allowsDistribution($subject, Privacy::COACH_SECTIONS);
        abort_unless($publicCoach || $this->privacy->allows($subject, $viewer, Privacy::PROFILE), 404);
        $subject->loadMissing(['profile.activeAvatar', 'telegramAccount', 'vkAccount']);
        $avatarAllowed = $this->privacy->allows($subject, $viewer, Privacy::AVATAR);

        return [
            'name' => trim(($subject->profile?->first_name ?? '').' '.($subject->profile?->last_name ?? '')) ?: ($subject->nickname ?: $subject->username ?: 'Пользователь'),
            'avatar_url' => $avatarAllowed ? ($subject->profile?->avatarUrl() ?: $subject->telegramAccount?->photo_url ?: $subject->vkAccount?->avatar_url) : null,
            'avatar_restricted' => ! $avatarAllowed,
            'url' => $this->url($subject),
            'public_coach' => $publicCoach,
            'role_label' => $publicCoach ? 'Тренер' : ($subject->hasActiveRole('player') && $this->privacy->allows($subject, $viewer, Privacy::ROLE_PLAYER) ? 'Игрок' : 'Пользователь'),
            'sections' => $sections->map(fn (SportsSection $section) => ['name' => $section->name, 'url' => route('sports-sections.show', $section)])->all(),
        ];
    }

    public function page(User $subject, ?User $viewer, ?string $role): array
    {
        $subject = $subject->canonical();
        $data = $this->preview($subject, $viewer);
        $profileAllowed = $this->privacy->allows($subject, $viewer, Privacy::PROFILE);
        $subject->loadMissing('playerProfile');
        $roles = collect(UserParticipationRoleEnum::cases())->filter(function ($candidate) use ($subject, $viewer, $data, $profileAllowed) {
            return $subject->hasActiveRole($candidate->value) && (
                ($candidate === UserParticipationRoleEnum::COACH && $data['public_coach']) ||
                ($profileAllowed && $this->privacy->allows($subject, $viewer, Privacy::from('role_'.$candidate->value)))
            );
        });
        abort_if($role !== null && ! $roles->contains(fn ($candidate) => $candidate->value === $role), 404);
        $data['roles'] = $roles->map(fn ($candidate) => [
            'value' => $candidate->value,
            'name' => $candidate->label(),
            'description' => $this->roleDescription($subject, $candidate),
            'url' => $this->url($subject, $candidate->value),
        ])->values()->all();
        $data['role'] = $role;
        $data['blocks'] = [];

        if ($role === 'coach' && ($data['public_coach'] || $this->privacy->allows($subject, $viewer, Privacy::COACH_SECTIONS))) {
            $data['blocks'][] = ['title' => 'Открытые секции', 'items' => $data['sections']];
        }
        if ($role === 'player') {
            $data['blocks'] = $this->playerBlocks($subject, $viewer);
        }
        if ($role === 'media' && $this->privacy->allows($subject, $viewer, Privacy::MEDIA_MATERIALS)) {
            $items = ContentItem::query()->published()->whereIn('created_by_user_id', $subject->identityIds())->latest('id')->get();
            $data['blocks'][] = ['title' => 'Опубликованные материалы', 'items' => $items->map(fn ($item) => ['name' => $item->title, 'url' => $item->publicUrl()])->all()];
        }
        if ($role === 'venue_related' && $this->privacy->allows($subject, $viewer, Privacy::VENUE_VENUES)) {
            $venues = Venue::query()->where('status', 'confirmed')->whereIn('id', VenueOwnership::query()->whereIn('owner_user_id', $subject->identityIds())->where('status', 'active')->select('venue_id'))->orderBy('name')->get();
            $data['blocks'][] = ['title' => 'Площадки под управлением', 'items' => $venues->map(fn ($venue) => ['name' => $venue->name, 'url' => route('venues.show', $venue)])->all()];
        }
        if (in_array($role, ['referee', 'statistician'], true) && $this->privacy->allows($subject, $viewer, $role === 'referee' ? Privacy::REFEREE_EVENTS : Privacy::STATISTICIAN_EVENTS)) {
            $participants = EventParticipant::query()->whereIn('user_id', $subject->identityIds())->where('status', 'confirmed')->where('responsibility_status', 'accepted')
                ->whereHas('event', fn (Builder $query) => $query->where('visibility', 'public')->whereIn('status', ['published', 'completed']))->with('event')->latest('id')->get();
            $data['blocks'][] = ['title' => 'Мероприятия с подтверждённой ответственностью', 'items' => $participants->map(fn ($participant) => ['name' => $participant->event->title, 'url' => route('events.show', $participant->event->routeIdentifier())])->all()];
        }

        return $data;
    }

    private function roleDescription(User $subject, UserParticipationRoleEnum $role): string
    {
        if ($role === UserParticipationRoleEnum::PLAYER) {
            $custom = trim((string) ($subject->playerProfile?->comment ?? ''));
            if ($custom !== '') {
                return $custom;
            }
        }

        return $role->description();
    }

    private function memberships(User $user, string $scope, string $role): Builder
    {
        return ContractMembership::query()->whereIn('user_id', $user->identityIds())
            ->where('scope_type', $scope)->whereJsonContains('sport_roles', $role)
            ->whereHas('contract', fn (Builder $query) => $query->where('family', 'membership')->where('status', 'active')
                ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())));
    }

    private function playerBlocks(User $user, ?User $viewer): array
    {
        $blocks = [];
        if ($this->privacy->allows($user, $viewer, Privacy::PLAYER_CHARACTERISTICS)) {
            $profile = $user->playerProfile()->with('positions')->first();
            $values = $profile ? array_filter([
                $profile->height_cm !== null ? 'Рост: '.$profile->height_cm.' см' : null,
                $profile->weight_kg !== null ? 'Вес: '.$profile->weight_kg.' кг' : null,
                $profile->body_type?->label(),
                $profile->experience_years !== null ? 'Стаж: '.$profile->experience_years.' лет' : null,
                $profile->positions->map(fn ($position) => $position->position->label())->implode(', '),
            ]) : [];
            $blocks[] = ['title' => 'Игровые характеристики', 'items' => array_map(fn ($name) => ['name' => $name], array_values($values))];
        }
        if ($this->privacy->allows($user, $viewer, Privacy::PLAYER_TEAMS)) {
            $teams = Team::query()->competitionEligible()->whereIn('id', $this->memberships($user, 'team', 'player')->select('scope_id'))->orderBy('name')->get();
            $blocks[] = ['title' => 'Команды', 'items' => $teams->map(fn ($team) => ['name' => $team->name, 'url' => route('teams.show', $team->routeIdentifier())])->all()];
        }
        if ($this->privacy->allows($user, $viewer, Privacy::PLAYER_SECTIONS)) {
            $sections = SportsSection::query()->where('status', 'active')->whereHas('traineeMemberships', fn (Builder $query) => $query->whereIn('user_id', $user->identityIds())->where('status', 'active'))->orderBy('name')->get();
            $blocks[] = ['title' => 'Секции', 'items' => $sections->map(fn ($section) => ['name' => $section->name, 'url' => route('sports-sections.show', $section)])->all()];
        }
        if ($this->privacy->allows($user, $viewer, Privacy::PLAYER_GAMES)) {
            $entries = GameRosterEntry::query()->whereIn('user_id', $user->identityIds())->where('status', 'played')
                ->whereHas('game', fn (Builder $query) => $query->where('status', 'completed'))
                ->whereHas('game.event', fn (Builder $query) => $query->where('visibility', 'public')->whereIn('status', ['published', 'completed']))
                ->with('game.event')->latest('id')->get()->unique('game_id');
            $blocks[] = ['title' => 'Сыгранные игры', 'items' => $entries->map(fn ($entry) => ['name' => $entry->game->event->title.' · Игра №'.$entry->game_id, 'url' => route('events.games.show', ['event' => $entry->game->event->routeIdentifier(), 'game' => $entry->game_id])])->values()->all()];
        }
        if ($this->privacy->allows($user, $viewer, Privacy::PLAYER_TOURNAMENTS)) {
            $tournaments = Tournament::query()->where('status', 'confirmed')->whereHas('entries', fn (Builder $query) => $query->where('status', 'active')->whereHas('members', fn (Builder $query) => $query->whereIn('user_id', $user->identityIds())))->orderByDesc('starts_on')->get();
            foreach ([false => 'Текущие и предстоящие турниры', true => 'Прошедшие турниры'] as $past => $title) {
                $items = $tournaments->filter(fn ($tournament) => (bool) ($tournament->tournament_closed_at || $tournament->ends_on?->lt(today())) === (bool) $past);
                $blocks[] = ['title' => $title, 'items' => $items->map(fn ($tournament) => ['name' => $tournament->title, 'url' => route('tournaments.show', $tournament)])->values()->all()];
            }
        }

        return $blocks;
    }
}
