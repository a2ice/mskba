<?php

namespace App\Filament\Backoffice\Resources\Venues\Support;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Support\Features\FeatureDisabledException;
use Illuminate\Validation\ValidationException;

final class VenueRentalPolicyEditor
{
    private const FIELDS = [
        'is_enabled' => 'rental_policy_enabled',
        'allows_whole' => 'rental_policy_allows_whole',
        'allows_halves' => 'rental_policy_allows_halves',
        'minimum_duration_minutes' => 'rental_policy_minimum_duration_minutes',
        'maximum_duration_minutes' => 'rental_policy_maximum_duration_minutes',
        'time_step_minutes' => 'rental_policy_time_step_minutes',
        'minimum_lead_time_minutes' => 'rental_policy_minimum_lead_time_minutes',
        'maximum_advance_days' => 'rental_policy_maximum_advance_days',
        'currency' => 'rental_policy_currency',
        'hold_duration_minutes' => 'rental_policy_hold_duration_minutes',
        'allows_hold_extension' => 'rental_policy_allows_hold_extension',
        'maximum_hold_extension_minutes' => 'rental_policy_maximum_hold_extension_minutes',
        'requires_payment' => 'rental_policy_requires_payment',
        'payment_window_minutes' => 'rental_policy_payment_window_minutes',
        'quote_validity_minutes' => 'rental_policy_quote_validity_minutes',
        'cancellation_before_minutes' => 'rental_policy_cancellation_before_minutes',
    ];

    private const DEFAULTS = [
        'is_enabled' => false,
        'allows_whole' => true,
        'allows_halves' => false,
        'minimum_duration_minutes' => 60,
        'maximum_duration_minutes' => 180,
        'time_step_minutes' => 30,
        'minimum_lead_time_minutes' => 120,
        'maximum_advance_days' => 90,
        'currency' => 'RUB',
        'hold_duration_minutes' => 30,
        'allows_hold_extension' => false,
        'maximum_hold_extension_minutes' => null,
        'requires_payment' => false,
        'payment_window_minutes' => 30,
        'quote_validity_minutes' => 15,
        'cancellation_before_minutes' => null,
        'whole_price_per_step_minor' => 0,
        'half_price_per_step_minor' => null,
    ];

    public function canManage(Venue $venue): bool
    {
        $user = auth('web')->user();

        return $user instanceof User
            && app(VenueCommercialAccess::class)->allows(
                $user,
                $venue,
                VenuePermissionEnum::MANAGE_BOOKING_POLICY,
            );
    }

    public function description(Venue $venue): string
    {
        $policy = $this->activePolicy($venue);

        return $policy === null
            ? 'Условия аренды ещё не опубликованы. Заполните настройки, включите аренду и сохраните площадку — будет создана первая версия политики.'
            : 'Активная версия №'.$policy->version.'. Изменения условий публикуют новую версию; уже оформленные бронирования не переписываются.';
    }

    /** @return array<string, mixed> */
    public function fields(Venue $venue): array
    {
        if (! $this->canManage($venue)) {
            return [];
        }

        $policy = $this->activePolicy($venue);
        $values = $policy?->only(array_keys(self::DEFAULTS)) ?? self::DEFAULTS;
        if ($policy === null) {
            $values['requires_payment'] = (bool) ($venue->requires_payment ?? false);
        }

        $fields = [];
        foreach (self::FIELDS as $source => $target) {
            $fields[$target] = $values[$source] ?? null;
        }

        $parser = app(MinorAmountParser::class);
        $currency = (string) ($values['currency'] ?? 'RUB');
        $fields['rental_policy_whole_price_rub'] = $parser->format(
            (int) ($values['whole_price_per_step_minor'] ?? 0), $currency,
        );
        $fields['rental_policy_half_price_rub'] = ($values['half_price_per_step_minor'] ?? null) === null
            ? null : $parser->format((int) $values['half_price_per_step_minor'], $currency);

        return $fields;
    }

    /**
     * Part of the existing Filament venue save transaction. Ignore forms that
     * have no rental fields, and do not publish an empty policy on unrelated edits.
     *
     * @param array<string, mixed> $data
     */
    public function save(Venue $venue, User $actor, array $data): void
    {
        if (! array_key_exists('rental_policy_enabled', $data)) {
            return;
        }

        abort_unless($this->canManage($venue), 403);

        $current = $this->activePolicy($venue);
        if ($current === null && ! (bool) $data['rental_policy_enabled']) {
            return;
        }

        $values = [];
        foreach (self::FIELDS as $source => $target) {
            $values[$source] = $data[$target] ?? self::DEFAULTS[$source];
        }

        foreach (['is_enabled', 'allows_whole', 'allows_halves', 'allows_hold_extension', 'requires_payment'] as $key) {
            $values[$key] = filter_var($values[$key], FILTER_VALIDATE_BOOLEAN);
        }

        foreach ([
            'minimum_duration_minutes', 'maximum_duration_minutes', 'time_step_minutes',
            'minimum_lead_time_minutes', 'maximum_advance_days', 'hold_duration_minutes',
            'quote_validity_minutes',
        ] as $key) {
            $values[$key] = (int) $values[$key];
        }

        foreach (['payment_window_minutes', 'maximum_hold_extension_minutes', 'cancellation_before_minutes'] as $key) {
            $values[$key] = $values[$key] === null || $values[$key] === ''
                ? null : (int) $values[$key];
        }

        $values['currency'] = strtoupper(trim((string) $values['currency']));
        if (! $values['requires_payment']) {
            $values['whole_price_per_step_minor'] = 0;
            // Existing free policies may store 0 rather than NULL; preserve
            // the snapshot and avoid publishing a new version on an unrelated edit.
            $values['half_price_per_step_minor'] = $current?->half_price_per_step_minor === 0 ? 0 : null;
            $values['payment_window_minutes'] = null;
        } else {
            $parser = app(MinorAmountParser::class);
            foreach ([
                'whole_price_per_step_minor' => 'rental_policy_whole_price_rub',
                'half_price_per_step_minor' => 'rental_policy_half_price_rub',
            ] as $field => $input) {
                $raw = trim((string) ($data[$input] ?? ''));
                if ($raw === '' && $field === 'half_price_per_step_minor') {
                    $values[$field] = null;
                    continue;
                }

                try {
                    $values[$field] = $parser->parse($raw, $values['currency']);
                } catch (VenueBookingTransitionException $exception) {
                    throw ValidationException::withMessages([
                        'data.'.$input => $exception->getMessage(),
                    ]);
                }
            }

            $values['payment_window_minutes'] ??= 30;
        }

        if (! $values['allows_hold_extension']) {
            $values['maximum_hold_extension_minutes'] = null;
        } else {
            $values['maximum_hold_extension_minutes'] ??= 30;
        }

        if ($current !== null && $this->unchanged($current, $values)) {
            return;
        }

        try {
            app(PublishVenueBookingPolicyHandler::class)->handle($venue, $actor, $values);
        } catch (FeatureDisabledException) {
            // Rental activation is separately controlled by deployment flags.
            // An administrator should see a form error, not a Livewire 500.
            throw ValidationException::withMessages([
                'data.rental_policy_enabled' => 'Функция аренды отключена в настройках окружения. Включение аренды требует разрешения администратора.',
            ]);
        } catch (VenueBookingPolicyException $exception) {
            throw ValidationException::withMessages([
                'data.rental_policy_enabled' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $values */
    private function unchanged(VenueBookingPolicy $policy, array $values): bool
    {
        foreach ($values as $key => $value) {
            if ($policy->{$key} !== $value) {
                return false;
            }
        }

        return true;
    }

    private function activePolicy(Venue $venue): ?VenueBookingPolicy
    {
        return VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)
            ->where('active_marker', true)
            ->first();
    }
}
