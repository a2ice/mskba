<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\VenueGroupOffer;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** One final non-stackable promotion stage, shared by quote and preview. */
final class VenuePersonalPricing
{
    public function evaluate(Venue $venue, VenueCourt $court, ?User $user, CarbonImmutable $start,
        int $duration, VenueBookingScopeEnum $scope, int $amount, string $currency, ?string $code = null): ?array
    {
        $code = strtoupper(trim($code ?? ''));
        if (! config('features.venue_rental.special_terms', false)) {
            if ($code !== '') {
                throw new VenueBookingPolicyException('Промокоды пока недоступны.');
            }

            return null;
        }
        $user = $user?->canonical();
        if ($user === null || ! $user->isConfirmed()) {
            if ($code !== '') {
                throw new VenueBookingPolicyException('Для промокода нужен подтверждённый аккаунт.');
            }

            return null;
        }
        $start = $start->setTimezone($venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow'));
        $offers = VenueGroupOffer::query()->where('venue_id', $venue->id)
            ->whereIn('audience', ['PUBLIC', 'USER_BOUND'])
            ->where(function ($query) use ($code, $user): void {
                $query->where(fn ($q) => $q->where('auto_apply', true)->where('audience', 'USER_BOUND')
                    ->whereHas('recipients', fn ($r) => $r->where('users.id', $user->id)));
                if ($code !== '') {
                    $query->orWhere('code_hash', hash('sha256', $code));
                }
            })->orderBy('id')->get();
        $explicitValid = $code === '';
        $best = null;
        foreach ($offers as $offer) {
            $explicit = $code !== '' && hash_equals($offer->code_hash, hash('sha256', $code));
            if (! $this->eligible($offer, $user, $court, $start, $duration, $scope, $amount, $currency)) {
                continue;
            }
            $discount = match ($offer->discount_type) {
                'percent' => intdiv($amount * (int) $offer->discount_value + 50, 100),
                'fixed' => (int) $offer->discount_value,
                'fixed_total' => $amount - (int) $offer->discount_value,
                default => 0,
            };
            if ($discount <= 0 || $discount >= $amount) {
                continue; // Never silently clamp a discount into a different agreement.
            }
            $explicitValid = $explicitValid || $explicit;
            if ($best === null || $discount > $best['discount_minor']) {
                $best = [
                    'offer_id' => $offer->id, 'terms_version' => $offer->terms_version,
                    'recipient_user_id' => $user->id, 'audience' => $offer->audience,
                    'title' => $offer->title, 'reason' => $offer->description,
                    'discount_type' => $offer->discount_type, 'discount_value' => $offer->discount_value,
                    'original_amount_minor' => $amount, 'discount_minor' => $discount,
                    'final_amount_minor' => $amount - $discount,
                    'funded_by' => 'venue', 'funding_venue_id' => $venue->id,
                    'combination_policy' => 'best_non_stackable',
                ];
            }
        }
        if (! $explicitValid) {
            // Deliberately identical for unknown, private, exhausted and mismatched codes.
            throw new VenueBookingPolicyException('Промокод недоступен для этого расчёта.');
        }

        return $best;
    }

    private function eligible(VenueGroupOffer $offer, User $user, VenueCourt $court, CarbonImmutable $start,
        int $duration, VenueBookingScopeEnum $scope, int $amount, string $currency): bool
    {
        if (! $offer->isCurrentlyPublished() || $offer->currency !== $currency
            || (int) $offer->venue_id !== (int) $court->venue_id
            || ($offer->venue_court_id !== null && (int) $offer->venue_court_id !== (int) $court->id)
            || ($offer->scope !== null && $offer->scope !== $scope->value)
            || ($offer->minimum_amount_minor !== null && $amount < $offer->minimum_amount_minor)
            || ($offer->audience === 'USER_BOUND' && ! $offer->recipients()->where('users.id', $user->id)->exists())
            || ($offer->max_total_uses !== null && $offer->used_count >= $offer->max_total_uses)
            || ($offer->max_uses_per_account !== null && DB::table('venue_offer_redemptions')
                ->where('offer_id', $offer->id)->where('user_id', $user->id)->count() >= $offer->max_uses_per_account)) {
            return false;
        }
        $c = $offer->booking_conditions ?? [];
        $end = $start->addMinutes($duration);
        // Whole interval must fit, not just its first minute. Midnight is an exclusive boundary.
        $last = $end->subSecond();
        if ((! empty($c['booking_date_from']) && $start->toDateString() < $c['booking_date_from'])
            || (! empty($c['booking_date_to']) && $last->toDateString() > $c['booking_date_to'])
            || (! empty($c['weekdays']) && (! in_array($start->isoWeekday(), array_map('intval', $c['weekdays']), true)
                || ! in_array($last->isoWeekday(), array_map('intval', $c['weekdays']), true)))
            || (! empty($c['time_from']) && ($start->format('H:i') < $c['time_from']
                || $last->toDateString() !== $start->toDateString()
                || $end->format('H:i') > $c['time_to'] || ! $start->isSameDay($end)))) {
            return false;
        }

        return true;
    }

    /** Called inside RequestVenueBookingHandler's transaction, after its venue/quote locks.
     * Quote price is never recalculated. Revocation/expiry/limits can reject redemption.
     * Usage is consumed on request (no automatic refund on cancellation).
     */
    public function redeem(VenueBookingQuote $quote, VenueBooking $booking, User $user): void
    {
        $special = data_get($quote->snapshot, 'pricing.personal_discount');
        if ($special === null) {
            return;
        }
        if (DB::table('venue_offer_redemptions')->where('quote_id', $quote->id)->exists()) {
            return;
        }
        $offer = VenueGroupOffer::query()->lockForUpdate()->find($special['offer_id']);
        $court = $booking->court;
        $start = CarbonImmutable::instance($quote->starts_at)->setTimezone(data_get($quote->snapshot, 'request.timezone', 'UTC'));
        if ($offer === null || $court === null || (int) $special['recipient_user_id'] !== (int) $user->id
            || (int) $offer->terms_version !== (int) $special['terms_version']
            || ! $this->eligible($offer, $user, $court, $start, (int) $start->diffInMinutes($quote->ends_at),
                $quote->scope, (int) $special['original_amount_minor'], $quote->currency)) {
            throw new VenueBookingTransitionException('Специальные условия больше недоступны. Выполни новый расчёт.', 'PROMOTION_UNAVAILABLE');
        }
        DB::table('venue_offer_redemptions')->insert([
            'offer_id' => $offer->id, 'quote_id' => $quote->id, 'booking_id' => $booking->id,
            'user_id' => $user->id, 'discount_minor' => $special['discount_minor'], 'created_at' => now(),
        ]);
        $offer->increment('used_count');
        $offer->audits()->create([
            'actor_user_id' => $user->id, 'action' => 'redeem', 'previous_status' => $offer->status,
            'next_status' => $offer->status, 'terms_version' => $offer->terms_version, 'created_at' => now(),
        ]);
    }
}
