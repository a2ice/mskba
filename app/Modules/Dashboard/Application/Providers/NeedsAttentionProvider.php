<?php

namespace App\Modules\Dashboard\Application\Providers;

use App\Modules\Dashboard\Application\Contracts\DashboardDataProvider;
use App\Modules\Dashboard\Application\Data\DashboardWidgetData;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\PersonalDataDistributionConsentService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\VenueBooking\Application\Queries\ListRequesterBookings;
use App\Modules\VenueBooking\Domain\Enums\VenueRentalPreRequestStatus;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use Illuminate\Database\Eloquent\Builder;

final readonly class NeedsAttentionProvider implements DashboardDataProvider
{
    public function __construct(
        private PersonalDataDistributionConsentService $consent,
        private MyRentalRequestsProvider $rental,
        private ListRequesterBookings $bookings,
    ) {}

    public function isAvailable(User $user, array $settings): bool
    {
        $user = $user->canonical();

        return ! $user->isBlocked() && ! $user->trashed();
    }

    public function getData(User $user, array $settings): DashboardWidgetData
    {
        $user = $user->canonical();
        abort_unless($this->isAvailable($user, $settings), 404);
        $limit = max(1, min(10, is_numeric($settings['limit'] ?? null) ? (int) $settings['limit'] : 5));
        $items = [];
        if (! $user->isConfirmed()) {
            $items[] = [
                'id' => 'account_confirmation', 'kind' => 'account_confirmation',
                'title' => 'Подтверди аккаунт',
                'description' => 'Заверши подтверждение аккаунта.',
                'url' => route('account.confirmation', [], false),
            ];
        }
        if ($this->consent->requiresSetup($user)) {
            $items[] = [
                'id' => 'privacy_setup', 'kind' => 'privacy_setup',
                'title' => 'Настрой публичность профиля',
                'description' => 'Заверши настройки регистрации.',
                'url' => route('account.privacy.distribution', [], false),
            ];
        }

        $total = count($items);
        if ($this->rental->isAvailable($user, [])) {
            // Mirror the customer's pending-response state. Do not include
            // ordinary requests awaiting a venue or expired/accepted offers.
            $offers = $this->bookings->forUser($user)
                ->where('requester_user_id', $user->id)
                ->where('status', VenueBookingStatusEnum::REQUESTED->value)
                ->whereNotNull('pre_request_id')->whereNotNull('created_by_representative_id')
                ->whereNull('proposal_accepted_at')->where('proposal_expires_at', '>', now())
                ->whereHas('preRequest', fn (Builder $q) => $q
                    ->where('requester_user_id', $user->id)
                    ->where('status', VenueRentalPreRequestStatus::ACTIVE->value));
            $total += (clone $offers)->count();
            $remaining = $limit - count($items);
            if ($remaining > 0) {
                foreach ($offers->with(['preRequest', 'venue:id,name'])
                    ->orderBy('proposal_expires_at')->orderBy('id')->limit($remaining)->get() as $offer) {
                    /** @var VenueBooking $offer */
                    $items[] = [
                        'id' => 'rental_proposal:'.$offer->public_id, 'kind' => 'rental_proposal',
                        'title' => 'Ответь на предложение аренды',
                        'description' => $offer->venue?->name ?? 'Площадка',
                        'deadline_at' => $offer->proposal_expires_at->utc()->toIso8601String(),
                        'url' => route('account.venue-pre-requests.show', $offer->preRequest, false),
                    ];
                }
            }
        }

        return new DashboardWidgetData($total, array_slice($items, 0, $limit));
    }
}
