<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Application\Services\EffectiveCourtPolicy;
use App\Modules\VenueBooking\Application\DTO\VenueBookingQuoteDTO;
use App\Modules\VenueBooking\Application\Services\VenueSlotPricing;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class QuoteVenueBookingHandler
{
    public function __construct(
        private VenueEventAvailability $availability,
        private FeatureFlags $features,
        private VenueSlotPricing $pricing,
        private EffectiveCourtPolicy $effectivePolicies,
    ) {}

    public function handle(
        Venue $venue,
        CarbonImmutable $startsAt,
        int $durationMinutes,
        VenueBookingScopeEnum $scope,
        ?User $user = null,
        ?VenueCourt $court = null,
    ): VenueBookingQuoteDTO {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        $court ??= $venue->primaryCourt()->first() ?? $venue->courts()->first();
        if ($court === null || $court->venue_id !== $venue->id || $court->trashed()) {
            throw new VenueBookingPolicyException('Выбранный зал недоступен.');
        }

        $policy = VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)
            ->where('active_marker', true)
            ->first();

        if ($policy === null || ! $policy->is_enabled) {
            throw new VenueBookingPolicyException('Аренда этой площадки сейчас недоступна.');
        }
        $effective = $this->effectivePolicies->resolve($venue, $court, $policy);
        if (! $effective['rental_enabled']) {
            throw new VenueBookingPolicyException('Аренда выбранного зала сейчас недоступна.');
        }

        $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
        $localStart = $startsAt->setTimezone($timezone)->startOfMinute();
        $now = CarbonImmutable::now($timezone);

        if ($localStart->lessThan($now->addMinutes($policy->minimum_lead_time_minutes))) {
            throw new VenueBookingPolicyException('Не соблюдён минимальный срок до начала аренды.');
        }

        if ($localStart->greaterThan($now->addDays($policy->maximum_advance_days))) {
            throw new VenueBookingPolicyException('Дата выходит за допустимый горизонт бронирования.');
        }

        $minutesFromDayStart = $localStart->hour * 60 + $localStart->minute;
        if ($minutesFromDayStart % $policy->time_step_minutes !== 0) {
            throw new VenueBookingPolicyException('Начало аренды должно соответствовать шагу времени политики.');
        }

        if (! $policy->acceptsDuration($durationMinutes)) {
            throw new VenueBookingPolicyException('Длительность не соответствует ограничениям политики.');
        }

        if ($scope === VenueBookingScopeEnum::WHOLE && ! $court->allows_whole) {
            throw new VenueBookingPolicyException('Аренда всего выбранного зала отключена.');
        }

        if ($scope !== VenueBookingScopeEnum::WHOLE && (! $court->supports_halves || ! $court->allows_halves)) {
            throw new VenueBookingPolicyException('Раздельная аренда выбранного зала отключена.');
        }

        $normalizedStart = $localStart->utc();
        $endsAt = $normalizedStart->addMinutes($durationMinutes);
        $databaseTimezone = (string) config('app.timezone', 'UTC');

        try {
            $this->availability->assertAvailable(
                $venue,
                $normalizedStart->setTimezone($databaseTimezone),
                $endsAt->setTimezone($databaseTimezone),
                scope: $scope,
                court: $court,
            );
        } catch (InvalidArgumentException $exception) {
            throw new VenueBookingPolicyException($exception->getMessage(), previous: $exception);
        }

        $fallbackPricePerStep = ! $effective['requires_payment'] ? 0
            : ($scope === VenueBookingScopeEnum::WHOLE
                ? $effective['whole_price_per_step_minor']
                : $effective['half_price_per_step_minor']);
        if ($effective['requires_payment'] && (int) $fallbackPricePerStep < 1) {
            throw new VenueBookingPolicyException('Для выбранного варианта аренды не настроена положительная цена.');
        }
        $price = $this->pricing->calculate($venue, $policy, $localStart, $durationMinutes, $scope, $court, $effective);
        $paymentWindow = $effective['requires_payment']
            ? ((int) ($policy->payment_window_minutes ?: 15))
            : null;
        $steps = count($price['prices_per_step_minor']);
        $amountMinor = $price['amount_minor'];
        $generatedAt = CarbonImmutable::now('UTC');
        $validUntil = $generatedAt->addMinutes($policy->quote_validity_minutes);
        $publicId = (string) Str::uuid();
        $snapshot = [
            'schema_version' => 2,
            'policy' => [
                'id' => $policy->id,
                'version' => $policy->version,
                'allows_whole' => $policy->allows_whole,
                'allows_halves' => $policy->allows_halves,
                'time_step_minutes' => $policy->time_step_minutes,
                'minimum_duration_minutes' => $policy->minimum_duration_minutes,
                'maximum_duration_minutes' => $policy->maximum_duration_minutes,
                'minimum_lead_time_minutes' => $policy->minimum_lead_time_minutes,
                'maximum_advance_days' => $policy->maximum_advance_days,
                'requires_payment' => $effective['requires_payment'],
                'requires_booking' => $effective['requires_booking'],
                'requires_booking_approval' => $effective['requires_booking_approval'],
                'court_rental_enabled' => $effective['rental_enabled'],
                'hold_duration_minutes' => $policy->hold_duration_minutes,
                'allows_hold_extension' => $policy->allows_hold_extension,
                'maximum_hold_extension_minutes' => $policy->maximum_hold_extension_minutes,
                'payment_window_minutes' => $paymentWindow,
                'cancellation_before_minutes' => $policy->cancellation_before_minutes,
            ],
            'request' => [
                'venue_id' => $venue->id,
                'venue_court_id' => $court->id,
                'venue_court_name' => $court->name,
                'venue_court_alias' => $court->alias,
                'court_hoops_count' => $court->hoops_count,
                'court_surface_type' => $court->surface_type?->value,
                'court_allows_whole' => $court->allows_whole,
                'court_allows_halves' => $court->allows_halves,
                'scope' => $scope->value,
                'starts_at' => $normalizedStart->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
                'timezone' => $timezone,
            ],
            'pricing' => [
                'formula' => $price['uses_custom_prices'] ? 'sum(prices_per_step_minor)' : 'steps * price_per_step_minor',
                'steps' => $steps,
                'price_per_step_minor' => (int) $fallbackPricePerStep,
                'prices_per_step_minor' => $price['prices_per_step_minor'],
                'uses_custom_prices' => $price['uses_custom_prices'],
                'amount_minor' => $amountMinor,
                'currency' => $policy->currency,
                'court_id' => $court->id,
            ],
            'generated_at' => $generatedAt->toIso8601String(),
            'valid_until' => $validUntil->toIso8601String(),
        ];

        VenueBookingQuote::query()->create([
            'public_id' => $publicId,
            'venue_id' => $venue->id,
            'venue_court_id' => $court->id,
            'policy_version_id' => $policy->id,
            'quoted_for_user_id' => $user?->canonical()->id,
            'scope' => $scope,
            'starts_at' => $normalizedStart->setTimezone($databaseTimezone),
            'ends_at' => $endsAt->setTimezone($databaseTimezone),
            'amount_minor' => $amountMinor,
            'currency' => $policy->currency,
            'hold_duration_minutes' => $policy->hold_duration_minutes,
            'payment_window_minutes' => $paymentWindow,
            'requires_payment' => $effective['requires_payment'],
            'snapshot' => $snapshot,
            'valid_until' => $validUntil->setTimezone($databaseTimezone),
        ]);

        return new VenueBookingQuoteDTO(
            publicId: $publicId,
            policyVersionId: $policy->id,
            policyVersion: $policy->version,
            scope: $scope,
            startsAt: $normalizedStart,
            endsAt: $endsAt,
            amountMinor: $amountMinor,
            currency: $policy->currency,
            requiresPayment: $effective['requires_payment'],
            holdDurationMinutes: $policy->hold_duration_minutes,
            paymentWindowMinutes: $paymentWindow,
            validUntil: $validUntil,
        );
    }
}
