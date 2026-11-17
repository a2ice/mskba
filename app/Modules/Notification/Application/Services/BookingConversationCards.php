<?php

namespace App\Modules\Notification\Application\Services;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Enums\UserNotificationStatusEnum;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\VenueBooking\Application\Queries\GetVenueBookingConversationSummary;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess;
use App\Modules\VenueBooking\Application\Services\VenueRentalPreRequests;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Read-only inbox adapter: never copies domain messages or grants new permissions.
 * Bookings derived from pre-requests remain in their original pre-request thread.
 */
final readonly class BookingConversationCards
{
    public function __construct(
        private FeatureFlags $features,
        private VenueMembershipAccess $memberships,
        private VenueBookingAuthorization $authorization,
        private VenueRentalPreRequests $preRequests,
        private GetVenueBookingConversationSummary $summary,
        private VenueBookingBackofficeAccess $backoffice,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function handle(User $viewer, ?Actor $actor): Collection
    {
        if ($actor === null || ! $this->features->enabled(VenueRentalFeature::RENTAL_FLOW)
            || ! $this->features->enabled(VenueRentalFeature::CONVERSATIONS)) {
            return collect();
        }

        $viewer = $viewer->canonical();
        $all = $viewer->hasSystemRole(UserSystemRoleEnum::SUPERADMIN) || $this->backoffice->canView($viewer);
        $bookingVenues = $all ? [] : $this->memberships->allowedVenueIdsFor($viewer, VenuePermissionEnum::VIEW_BOOKING_REQUESTS);
        $bookingCourts = $all ? [] : $this->memberships->allowedCourtIdsFor($viewer, VenuePermissionEnum::VIEW_BOOKING_REQUESTS);
        $preVenues = $bookingVenues;
        $preCourts = $bookingCourts;
        // Venue owner/admin contracts can see pre-requests even without a
        // separately delegated court permission (same rule as the domain).
        if (! $all) {
            foreach ($this->memberships->activeVenueMembershipsFor($viewer) as $membership) {
                if ($membership->scope_type === ContractMembershipScopeTypeEnum::VENUE
                    && in_array($membership->access_level, [
                        VenueMembershipAccessLevelEnum::OWNER->value,
                        VenueMembershipAccessLevelEnum::ADMIN->value,
                    ], true)) {
                    $preVenues[] = $membership->scope_id;
                }
            }
        }
        if (! $all) {
            foreach ([VenuePermissionEnum::VIEW_EVENT_INTERESTS, VenuePermissionEnum::PROPOSE_EVENT_BOOKING] as $permission) {
                $preVenues = [...$preVenues, ...$this->memberships->allowedVenueIdsFor($viewer, $permission)];
                $preCourts = [...$preCourts, ...$this->memberships->allowedCourtIdsFor($viewer, $permission)];
            }
        }

        $bookings = VenueBooking::query()
            ->where('flow', 'rental')->whereNull('pre_request_id')
            ->when(! $all, fn ($query) => $query->where(fn ($query) => $query
                ->whereIn('requester_user_id', $viewer->identityIds())
                ->orWhereIn('venue_id', $bookingVenues)
                ->orWhereIn('venue_court_id', $bookingCourts)
                ->orWhereHas('parties', fn ($party) => $party->whereIn('user_id', $viewer->identityIds()))))
            ->with(['venue', 'conversation'])->latest('updated_at')->limit(100)->get();

        $cards = collect();
        foreach ($bookings as $booking) {
            if ($booking->venue === null) {
                continue;
            }
            try {
                $this->authorization->assertCanView($actor, $booking, $booking->venue);
            } catch (VenueBookingTransitionException|HttpException) {
                continue;
            }

            $conversation = $booking->conversation;
            $latest = $conversation?->messages()->with('authorActor.user')->latest('id')->first();
            $summary = $conversation === null ? ['unread_count' => 0] : $this->summary->handle($booking, $actor);
            $notices = $this->unreadNotifications($viewer, 'booking_id', $booking->public_id);
            $author = $latest?->authorActor?->user;
            $cards->push([
                'id' => 'booking-'.$booking->id,
                'name' => 'Аренда «'.$booking->venue->name.'»',
                'preview' => $latest ? ($author?->username ?? 'Участник').': '.($latest->body ?: 'Вложение')
                    : $booking->status->label(),
                'unread_count' => max((int) $summary['unread_count'], $notices),
                'date' => $latest?->created_at?->greaterThan($booking->updated_at) ? $latest->created_at : $booking->updated_at,
                'status' => $booking->status->label(),
                'url' => route('account.notifications.threads.booking', $booking),
            ]);
        }

        // The pre-request owns the continuous conversation even after a booking proposal.
        $preRequests = VenueRentalPreRequest::query()
            ->when(! $all, fn ($query) => $query->where(fn ($query) => $query
                ->where('requester_user_id', $viewer->id)
                ->orWhereIn('venue_id', $preVenues)
                ->orWhereIn('venue_court_id', $preCourts)))
            ->with(['venue', 'court', 'conversation'])->latest('updated_at')->limit(100)->get();

        foreach ($preRequests as $pre) {
            if (! $this->preRequests->can($viewer, $pre)) {
                continue;
            }
            $conversation = $pre->conversation;
            $latest = $conversation?->messages()->with('authorActor.user')->latest('id')->first();
            $unread = $this->unreadMessages($viewer, $conversation);
            $notices = $this->unreadNotifications($viewer, 'pre_request_id', $pre->id);
            // Lifecycle notifications of linked booking proposals belong to this same thread.
            $linkedBookingIds = $pre->bookings()->pluck('public_id')->all();
            if ($linkedBookingIds !== []) {
                $notices += UserNotification::query()->whereIn('user_id', $viewer->identityIds())
                    ->where('status', UserNotificationStatusEnum::NEW)
                    ->whereIn('payload->booking_id', $linkedBookingIds)->count();
            }
            $cards->push([
                'id' => 'pre-request-'.$pre->id,
                'name' => 'Предзаявка «'.($pre->venue?->name ?? 'Площадка').'»',
                'preview' => $latest ? ($latest->authorActor?->user?->username ?? 'Участник').': '.($latest->body ?: 'Вложение')
                    : $pre->status->label(),
                'unread_count' => max($unread, $notices),
                'date' => $latest?->created_at?->greaterThan($pre->updated_at) ? $latest->created_at : $pre->updated_at,
                'status' => $pre->status->label(),
                'url' => route('account.notifications.threads.pre-request', $pre),
            ]);
        }

        return $cards;
    }

    private function unreadMessages(User $viewer, ?VenueBookingConversation $conversation): int
    {
        if ($conversation === null) {
            return 0;
        }
        $lastRead = (int) ($conversation->readMarkers()->where('user_id', $viewer->id)->value('last_read_message_id') ?? 0);

        return $conversation->messages()->where('id', '>', $lastRead)
            ->whereHas('authorActor', fn ($query) => $query->where('user_id', '!=', $viewer->id))
            ->count();
    }

    private function unreadNotifications(User $viewer, string $field, string|int $value): int
    {
        return UserNotification::query()->whereIn('user_id', $viewer->identityIds())
            ->where('status', UserNotificationStatusEnum::NEW)
            ->where('payload->'.$field, $value)->count();
    }
}
