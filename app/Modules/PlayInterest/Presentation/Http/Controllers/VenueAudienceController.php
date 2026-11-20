<?php

namespace App\Modules\PlayInterest\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\Services\DirectMessageBlockService;
use App\Modules\Notification\Application\Services\DirectMessageRecipientAccess;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Notification\Presentation\Http\Controllers\DirectMessagesController;
use App\Modules\PlayInterest\Application\Services\VenueSubscriptionAudience;
use App\Modules\PlayInterest\Domain\Models\VenueGroupOffer;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueSpecialTermsManager;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class VenueAudienceController extends Controller
{
    public function index(Request $request, Venue $venue, VenueSubscriptionAudience $audience)
    {
        $backoffice = (bool) $request->route('backoffice');
        $summary = $audience->forManagement($venue, $request->user(), $backoffice);
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(array_column(UserParticipationRoleEnum::cases(), 'value'))],
            'activity' => ['nullable', Rule::in(['games', 'organized'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filtered = collect($summary['people'])->filter(fn ($person) =>
            (empty($filters['role']) || in_array($filters['role'], array_column($person['roles'], 'value'), true))
            && (empty($filters['activity']) || (int) $person[$filters['activity']] > 0))->values();
        $page = (int) ($filters['page'] ?? 1);
        $people = new LengthAwarePaginator($filtered->forPage($page, 20), $filtered->count(), 20, $page,
            ['path' => $request->url(), 'query' => $request->except('page')]);

        $ids = $people->getCollection()->where('can_offer', true)->pluck('user_id');
        $offers = $ids->isEmpty() ? collect() : VenueGroupOffer::query()->where('venue_id', $venue->id)
            ->where('audience', 'USER_BOUND')->where('status', 'active')
            ->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->where(fn ($q) => $q->whereNull('max_total_uses')->orWhereColumn('used_count', '<', 'max_total_uses'))
            ->whereHas('recipients', fn ($q) => $q->whereIn('users.id', $ids))
            ->with('recipients:id')->get();

        return ThemeResolver::page('account.venue-audience', [
            'venue' => $venue, 'summary' => $summary, 'people' => $people, 'filters' => $filters,
            'roleOptions' => UserParticipationRoleEnum::cases(), 'backoffice' => $backoffice, 'offers' => $offers,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function message(Request $request, Venue $venue, User $user, VenueSubscriptionAudience $audience,
        DirectMessagesController $direct, DirectMessageRecipientAccess $access,
        CreateUserNotificationHandler $notifications, DirectMessageBlockService $blocks)
    {
        $person = collect($audience->forManagement($venue, $request->user(), (bool) $request->route('backoffice'))['people'])
            ->firstWhere('user_id', $user->canonical()->id);
        abort_unless($person && $person['can_message'], 403);
        // Reuse existing ACL, block checks, transaction, message history and rate limiter.
        $result = $direct->send($request, $user, $access, $notifications, $blocks);
        return redirect($result->getData(true)['conversation_url']);
    }

    public function notify(Request $request, Venue $venue, User $user)
    {
        $actor = $request->user()->canonical();
        $subject = $user->canonical();
        $backoffice = (bool) $request->route('backoffice');
        $audience = app(VenueSubscriptionAudience::class);
        $data = $request->validate(['offer_id' => ['required', 'integer', 'min:1']]);
        abort_unless($audience->canManageAudience($actor, $venue, $backoffice)
            && app(VenueSpecialTermsManager::class)->canManage($actor, $venue, $backoffice), 403);

        $sent = DB::transaction(function () use ($actor, $subject, $venue, $backoffice, $audience, $data): bool {
            $offer = VenueGroupOffer::query()->where('venue_id', $venue->id)->whereKey($data['offer_id'])
                ->where('audience', 'USER_BOUND')->lockForUpdate()->firstOrFail();
            abort_unless($offer->isCurrentlyPublished()
                && ($offer->max_total_uses === null || $offer->used_count < $offer->max_total_uses)
                && $offer->recipients()->whereIn('users.id', $subject->identityIds())->exists(), 403);
            $person = collect($audience->forManagement($venue, $actor, $backoffice)['people'])
                ->firstWhere('user_id', $subject->id);
            abort_unless($person && $person['can_offer'], 403);
            $channels = $audience->offerChannels($venue, $subject);
            abort_if($channels === [], 403);
            // Locking the offer serializes retries; the existing notification is the delivery audit.
            $duplicate = UserNotification::query()->whereIn('user_id', $subject->identityIds())
                ->where('payload->source', 'venue.audience.offer')
                ->where('payload->offer_id', $offer->id)->exists();
            if ($duplicate) {
                return false;
            }
            $body = $offer->title.($offer->description ? ': '.$offer->description : '');
            if (! $offer->auto_apply) {
                $body .= "\nКод: ".$offer->code_ciphertext;
            }
            app(CreateUserNotificationHandler::class)->handle(new CreateUserNotificationDTO(
                userId: $subject->id, type: UserNotificationTypeEnum::SYSTEM,
                title: 'Персональное предложение от '.$venue->name, body: $body,
                actionUrl: route('venues.show', $venue->routeIdentifier()), actionText: 'Открыть площадку',
                payload: ['source' => 'venue.audience.offer', 'offer_id' => $offer->id, 'venue_id' => $venue->id,
                    'actor_user_id' => $actor->id, 'delivery_channels' => $channels],
            ), true);
            $offer->audits()->create(['actor_user_id' => $actor->id, 'action' => 'notify',
                'previous_status' => 'active', 'next_status' => 'active',
                'terms_version' => $offer->terms_version, 'created_at' => now()]);

            return true;
        });
        return back()->with('status', $sent ? 'Предложение отправлено.' : 'Это предложение уже было отправлено.');
    }
}
