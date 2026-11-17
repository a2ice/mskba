<?php

namespace App\Modules\Event\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventTypeEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionDirectionEnum;
use App\Modules\Event\Domain\Enums\GameAdmissionStatusEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Account projection: one Event per row even if aliases, admissions and mini-games overlap.
 * No new participation commands are introduced; every action leads to the canonical Event.
 */
final class AccountMyGamesController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if (app(ThemeResolver::class)->active() !== 'mskba_app') {
            return redirect()->route('account');
        }

        $input = $request->validate([
            'scope' => ['nullable', Rule::in(['all', 'requests', 'playing', 'history'])],
        ]);
        $scope = $input['scope'] ?? 'all';
        $user = $request->user()->canonical();
        $identities = $user->identityIds();
        $now = now();

        $query = Event::query()
            ->whereIn('type', [EventTypeEnum::GAME->value, EventTypeEnum::GAME_TRAINING->value])
            ->where(function (Builder $query) use ($identities): void {
                $query->whereHas('participants', fn (Builder $q) => $q->whereIn('user_id', $identities))
                    ->orWhereHas('primaryGame.admissions', fn (Builder $q) => $q->whereIn('user_id', $identities))
                    ->orWhereHas('organizerActor', fn (Builder $q) => $q->whereIn('user_id', $identities));
            });

        // Use DB filtering for pagination, not a filtered subset of one page.
        if ($scope === 'requests') {
            $query->where('ends_at', '>', $now)
                ->whereNotIn('status', [EventStatusEnum::CANCELLED->value, EventStatusEnum::COMPLETED->value])
                ->where(function (Builder $q) use ($identities): void {
                    $q->whereHas('participants', fn (Builder $p) => $p
                        ->whereIn('user_id', $identities)->where('status', EventParticipantStatusEnum::TENTATIVE->value))
                        ->orWhereHas('primaryGame.admissions', fn (Builder $a) => $a
                            ->whereIn('user_id', $identities)->where('status', GameAdmissionStatusEnum::PENDING->value));
                });
        } elseif ($scope === 'playing') {
            $query->where('ends_at', '>', $now)
                ->whereNotIn('status', [EventStatusEnum::CANCELLED->value, EventStatusEnum::COMPLETED->value])
                ->where(function (Builder $q) use ($identities): void {
                    $q->whereHas('participants', fn (Builder $p) => $p
                        ->whereIn('user_id', $identities)->where('status', EventParticipantStatusEnum::CONFIRMED->value)
                        ->whereColumn('confirmation_version', 'events.participation_confirmation_version'))
                        ->orWhereHas('primaryGame.admissions', fn (Builder $a) => $a
                            ->whereIn('user_id', $identities)->where('status', GameAdmissionStatusEnum::ACCEPTED->value))
                        ->orWhereHas('organizerActor', fn (Builder $o) => $o->whereIn('user_id', $identities));
                });
        } elseif ($scope === 'history') {
            $query->where(function (Builder $q) use ($now, $identities): void {
                $q->where('ends_at', '<=', $now)
                    ->orWhereIn('status', [EventStatusEnum::CANCELLED->value, EventStatusEnum::COMPLETED->value])
                    ->orWhereHas('participants', fn (Builder $p) => $p
                        ->whereIn('user_id', $identities)->where('status', EventParticipantStatusEnum::LEFT->value))
                    ->orWhereHas('primaryGame.admissions', fn (Builder $a) => $a
                        ->whereIn('user_id', $identities)
                        ->whereIn('status', [GameAdmissionStatusEnum::DECLINED->value, GameAdmissionStatusEnum::REVOKED->value]));
            });
        }

        $events = $query
            ->with([
                'venue:id,name',
                'organizerActor:id,user_id',
                'participants' => fn ($q) => $q->whereIn('user_id', $identities),
                'primaryGame.admissions' => fn ($q) => $q->whereIn('user_id', $identities),
                'primaryGame.tournamentMatch',
            ])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        $items = $events->getCollection()->map(function (Event $event) use ($identities, $now): array {
            $participant = $event->participants->sortByDesc('updated_at')->first();
            $admission = $event->type === EventTypeEnum::GAME
                ? $event->primaryGame?->admissions?->sortByDesc('updated_at')->first()
                : null;
            $isOrganizer = in_array((int) $event->organizerActor?->user_id, $identities, true);
            $past = $event->ends_at->lessThanOrEqualTo($now)
                || in_array($event->status, [EventStatusEnum::COMPLETED, EventStatusEnum::CANCELLED], true);

            $confirmed = $participant?->status === EventParticipantStatusEnum::CONFIRMED
                && $participant->confirmation_version === $event->participation_confirmation_version;
            $accepted = $admission?->status === GameAdmissionStatusEnum::ACCEPTED;
            $pending = $participant?->status === EventParticipantStatusEnum::TENTATIVE
                || $admission?->status === GameAdmissionStatusEnum::PENDING;
            $group = $past ? 'history' : (($isOrganizer || $confirmed || $accepted)
                ? 'playing' : ($pending ? 'requests' : 'history'));

            $label = match (true) {
                $event->status === EventStatusEnum::CANCELLED => 'Отменено',
                $past => 'Прошло',
                $isOrganizer => 'Организатор',
                $confirmed || $accepted => 'Участвую',
                $admission?->status === GameAdmissionStatusEnum::PENDING
                    && $admission->direction === GameAdmissionDirectionEnum::INVITATION => 'Приглашение',
                $admission?->status === GameAdmissionStatusEnum::PENDING => 'Заявка отправлена',
                $participant?->status === EventParticipantStatusEnum::TENTATIVE => 'Думаю / ожидаю решения',
                $participant?->status === EventParticipantStatusEnum::CONFIRMED => 'Требуется переподтверждение',
                $admission?->status === GameAdmissionStatusEnum::DECLINED => 'Отклонено',
                $admission?->status === GameAdmissionStatusEnum::REVOKED
                    || $participant?->status === EventParticipantStatusEnum::LEFT => 'Участие отменено',
                default => 'В истории',
            };
            return [
                'event' => $event,
                'group' => $group,
                'label' => $label,
                'tournament' => $event->primaryGame?->tournamentMatch !== null,
            ];
        });
        $events->setCollection($items);

        return ThemeResolver::page('account.my-games', [
            'games' => $events,
            'scope' => $scope,
            'groups' => [
                'requests' => 'Заявки и приглашения',
                'playing' => 'Участвую',
                'history' => 'История',
            ],
        ]);
    }
}
