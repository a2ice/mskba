<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum as Role;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum as Status;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Identity\Application\Services\PublicUserProfileService;
use App\Modules\Identity\Application\Services\UserPrivacyAccessService;
use App\Modules\Identity\Domain\Enums\UserPrivacySettingTypeEnum as Privacy;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Social\Domain\Models\SocialWallEntry;

final class EventPreviewRoster
{
    public function __construct(
        private readonly UserPrivacyAccessService $privacy,
        private readonly PublicUserProfileService $profiles,
        private readonly EventIdentityParticipationService $participation,
    ) {}

    public function forEvent(Event $event, ?User $viewer): array
    {
        $viewer = $viewer?->canonical();
        $organizer = $event->organizerActor?->user?->canonical();
        $organizerId = $organizer?->id;
        $members = $event->participants()->with('user.profile.activeAvatar')->orderBy('id')->get()
            ->filter(fn (EventParticipant $member): bool => $member->user !== null)
            ->groupBy(fn (EventParticipant $member): int => (int) $member->user->canonical()->id);
        if ($organizer !== null && ! $members->has($organizerId)) {
            // Old events may have an organizer actor but no sporting roster row.
            $placeholder = new EventParticipant(['role' => Role::ORGANIZER, 'status' => Status::LEFT, 'confirmation_version' => $event->participation_confirmation_version]);
            $placeholder->setRelation('user', $organizer);
            $members->put($organizerId, collect([$placeholder]));
        }
        $decisions = SocialWallEntry::query()->where('scope_type', 'event')->where('scope_id', $event->id)
            ->where('kind', 'event_rsvp_changed')->whereNull('hidden_at')->whereNull('deleted_at')
            ->latest('id')->get()->groupBy('actor_user_id');
        $rows = [];

        foreach ($members as $id => $group) {
            $person = $group->first()->user->canonical();
            if ($person->trashed() || $person->isBlocked()) {
                continue;
            }
            // Same effective-identity precedence as the RSVP handler.
            $member = $group->first(fn ($item) => $item->role === Role::ORGANIZER)
                ?? $group->first(fn ($item) => $item->status === Status::CONFIRMED && $item->confirmation_version === $event->participation_confirmation_version)
                ?? $group->first(fn ($item) => $item->status === Status::CONFIRMED)
                ?? $group->first(fn ($item) => (int) $item->user_id === (int) $id)
                ?? $group->first();

            $isOrganizer = (int) $id === (int) $organizerId;
            $status = $member->confirmation_version === $event->participation_confirmation_version ? $member->status : Status::LEFT;
            if (! $isOrganizer && ! in_array($status, [Status::CONFIRMED, Status::TENTATIVE], true)) {
                continue;
            }
            $profileAllowed = $this->privacy->allows($person, $viewer, Privacy::PROFILE);
            $person->loadMissing(['profile.activeAvatar', 'telegramAccount', 'vkAccount']);
            $avatarAllowed = $profileAllowed && $this->privacy->allows($person, $viewer, Privacy::AVATAR);
            $decision = $decisions->get($id)?->first(fn ($entry) =>
                ($entry->metadata['to'] ?? null) === $status->value
                && (int) ($entry->metadata['confirmation_version'] ?? 0) === $event->participation_confirmation_version);
            $decisionAt = $member->status_changed_at ?? $decision?->created_at ?? ($status === Status::LEFT ? $member->left_at : $member->joined_at);
            $rows[] = [
                'name' => $profileAllowed ? ($person->nickname ?: $person->username ?: 'Участник') : 'Участник',
                'url' => $profileAllowed ? $this->profiles->url($person) : null,
                'avatar_url' => $avatarAllowed ? ($person->profile?->avatarUrl() ?: $person->telegramAccount?->photo_url ?: $person->vkAccount?->avatar_url) : null,
                'status' => $status->value,
                'decision_at' => $decisionAt?->setTimezone($event->venue?->schedule?->timezone ?: config('app.timezone'))->format('d.m.Y H:i'),
                'organizer' => $isOrganizer,
                'is_viewer' => $viewer !== null && (int) $id === (int) $viewer->id,
            ];
        }

        $rows = collect($rows)->sortByDesc('organizer')->values()->all();
        $own = $viewer === null ? null : $this->participation->effectiveParticipant($event, $viewer);

        return [
            'members' => $rows,
            'max_participants' => $event->max_participants,
            'viewer_status' => $own !== null && $own->confirmation_version === $event->participation_confirmation_version ? $own->status->value : null,
            'viewer_is_organizer' => $viewer !== null && (int) $viewer->id === (int) $organizerId,
        ];
    }
}
