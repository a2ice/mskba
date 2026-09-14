<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Domain\Enums\SectionContactSourceEnum;
use App\Modules\SportsSection\Domain\Enums\SectionPricingTypeEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionFormatEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionJoinRequestStatusEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Enums\SportsSectionStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TraineeMembershipStatusEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingModeEnum;
use App\Modules\SportsSection\Domain\Enums\TrainingSessionStatusEnum;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\Team\Domain\Enums\TeamStatusEnum;
use App\Modules\Team\Domain\Models\Team;
use App\Modules\Venue\Domain\Models\Venue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SportsSectionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->guardFeature();

        $search = trim((string) $request->query('q', ''));
        $trainingMode = $this->enumFilter((string) $request->query('training_mode', ''), TrainingModeEnum::cases());
        $gameFormat = $this->enumFilter((string) $request->query('game_format', ''), SportsSectionFormatEnum::cases());
        $pricingType = $this->enumFilter((string) $request->query('pricing_type', ''), SectionPricingTypeEnum::cases());
        $venueId = max(0, $request->integer('venue_id'));
        $teamId = max(0, $request->integer('team_id'));
        $acceptsRequests = $request->boolean('accepts_requests');
        $recruiting = $request->boolean('recruiting');

        $query = SportsSection::query()
            ->where('status', SportsSectionStatusEnum::ACTIVE->value)
            ->with([
                'featuredMedia',
                'primaryVenue.location.address',
                'teams' => fn ($teams) => $teams
                    ->whereNull('temporary_for_event_id')
                    ->where('status', TeamStatusEnum::ACTIVE->value)
                    ->orderBy('name'),
            ])
            ->withCount(['traineeMemberships as active_trainees_count' => fn ($query) => $query
                ->where('status', TraineeMembershipStatusEnum::ACTIVE->value)]);

        if ($search !== '') {
            $needle = '%'.$search.'%';
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', $needle)
                ->orWhere('description', 'like', $needle));
        }
        if ($trainingMode !== '') {
            $query->where('training_mode', $trainingMode);
        }
        if ($gameFormat !== '') {
            $query->where('game_format', $gameFormat);
        }
        if ($pricingType !== '') {
            $query->where('pricing_type', $pricingType);
        }
        if ($venueId > 0) {
            $query->where('primary_venue_id', $venueId);
        }
        if ($teamId > 0) {
            $query->whereHas('teams', fn ($teams) => $teams
                ->whereKey($teamId)
                ->whereNull('temporary_for_event_id')
                ->where('status', TeamStatusEnum::ACTIVE->value));
        }
        if ($acceptsRequests) {
            $query->where('accepts_trainee_requests', true);
        }
        if ($recruiting) {
            $query->where('is_recruiting', true);
        }

        $sections = $query->orderBy('name')->paginate(18)->withQueryString();
        $venues = Venue::query()
            ->whereIn('id', SportsSection::query()
                ->where('status', SportsSectionStatusEnum::ACTIVE->value)
                ->whereNotNull('primary_venue_id')
                ->select('primary_venue_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
        $teams = Team::query()
            ->whereNull('temporary_for_event_id')
            ->where('status', TeamStatusEnum::ACTIVE->value)
            ->whereHas('sportsSections', fn ($sections) => $sections
                ->where('sports_sections.status', SportsSectionStatusEnum::ACTIVE->value))
            ->orderBy('name')
            ->get(['teams.id', 'teams.name']);

        return ThemeResolver::page('sports-sections.index', [
            'sections' => $sections,
            'trainingModes' => TrainingModeEnum::cases(),
            'formats' => SportsSectionFormatEnum::cases(),
            'pricingTypes' => SectionPricingTypeEnum::cases(),
            'venues' => $venues,
            'teams' => $teams,
            'filters' => [
                'q' => $search,
                'training_mode' => $trainingMode,
                'game_format' => $gameFormat,
                'pricing_type' => $pricingType,
                'venue_id' => $venueId > 0 ? $venueId : null,
                'team_id' => $teamId > 0 ? $teamId : null,
                'accepts_requests' => $acceptsRequests,
                'recruiting' => $recruiting,
            ],
        ]);
    }

    public function show(Request $request, SportsSection $sportsSection, SportsSectionAccess $access): Response
    {
        $this->guardFeature();
        abort_unless($sportsSection->status === SportsSectionStatusEnum::ACTIVE, 404);

        $activeCoachMembershipIds = $access->activeMemberships($sportsSection)
            ->whereJsonContains('sport_roles', 'coach')
            ->pluck('id');

        $sportsSection->load([
            'featuredMedia',
            'media' => fn ($query) => $query
                ->where('collection', 'sports_section_gallery')
                ->orderByDesc('is_featured')
                ->orderBy('sort_order'),
            'headCoachMembership.user.profile.activeAvatar',
            'headCoachMembership.user.contacts',
            'coachMemberships' => fn ($query) => $query
                ->whereIn('id', $activeCoachMembershipIds)
                ->with('user.profile.activeAvatar'),
            'contacts' => fn ($query) => $query->where('is_public', true),
            'primaryVenue.location.address',
            'primaryVenueCourt',
            'pricingPlans' => fn ($query) => $query->where('is_active', true)->orderBy('amount_minor'),
            'teams' => fn ($query) => $query
                ->whereNull('temporary_for_event_id')
                ->where('status', TeamStatusEnum::ACTIVE->value)
                ->orderBy('name'),
            'trainingSessions' => fn ($query) => $query
                ->with(['venue', 'venueCourt', 'event'])
                ->where('status', TrainingSessionStatusEnum::CONFIRMED->value)
                ->where('ends_at', '>=', now())
                ->orderBy('starts_at')
                ->limit(20),
        ])->loadCount([
            'traineeMemberships as active_trainees_count' => fn ($query) => $query
                ->where('status', TraineeMembershipStatusEnum::ACTIVE->value),
        ]);

        $contacts = $sportsSection->contact_source === SectionContactSourceEnum::HEAD_COACH
            ? $sportsSection->headCoachMembership?->user?->contacts?->where('is_public', true) ?? collect()
            : $sportsSection->contacts;

        $user = $request->user()?->canonical();
        $identityIds = $user?->identityIds() ?? [];
        $currentJoinRequest = $identityIds === [] ? null : $sportsSection->joinRequests()
            ->whereIn('user_id', $identityIds)
            ->where('status', SportsSectionJoinRequestStatusEnum::PENDING->value)
            ->latest('id')
            ->first();
        $isActiveTrainee = $identityIds !== [] && $sportsSection->traineeMemberships()
            ->whereIn('user_id', $identityIds)
            ->where('status', TraineeMembershipStatusEnum::ACTIVE->value)
            ->exists();
        $hasCapacity = $sportsSection->max_trainees === null
            || (int) $sportsSection->active_trainees_count < $sportsSection->max_trainees;
        $canApply = $user !== null
            && ! $isActiveTrainee
            && $currentJoinRequest === null
            && $sportsSection->accepts_trainee_requests
            && $hasCapacity
            && $user->isConfirmed()
            && ! $user->isBlocked()
            && ! $user->trashed()
            && $user->hasActiveRole(UserParticipationRoleEnum::PLAYER->value);

        $canManageSection = $user !== null && $access->allows($user, $sportsSection, SportsSectionPermissionEnum::MANAGE);
        $canManageTrainees = $user !== null && $access->allows($user, $sportsSection, SportsSectionPermissionEnum::MANAGE_TRAINEES);
        $canManageSessions = $user !== null && $access->allows($user, $sportsSection, SportsSectionPermissionEnum::MANAGE_SESSIONS);

        return ThemeResolver::page('sports-sections.show', [
            'section' => $sportsSection,
            'contacts' => $contacts,
            'currentJoinRequest' => $currentJoinRequest,
            'isActiveTrainee' => $isActiveTrainee,
            'canApply' => $canApply,
            'hasCapacity' => $hasCapacity,
            'canManageSection' => $canManageSection,
            'canManageTrainees' => $canManageTrainees,
            'canManageSessions' => $canManageSessions,
        ]);
    }

    /** @param array<int, \BackedEnum> $cases */
    private function enumFilter(string $value, array $cases): string
    {
        $allowed = array_map(static fn (\BackedEnum $item): string => (string) $item->value, $cases);

        return in_array($value, $allowed, true) ? $value : '';
    }

    private function guardFeature(): void
    {
        abort_unless(config('features.sports_sections.enabled'), 404);
    }
}
