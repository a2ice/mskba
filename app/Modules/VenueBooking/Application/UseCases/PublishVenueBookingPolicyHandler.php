<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Events\VenueBookingPolicyPublished;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;

final readonly class PublishVenueBookingPolicyHandler
{
    public function __construct(
        private VenueCommercialAccess $access,
        private FeatureFlags $features,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Venue $venue, User $publisher, array $data): VenueBookingPolicy
    {
        $this->features->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        $publisher = $publisher->canonical();

        return DB::transaction(function () use ($venue, $publisher, $data): VenueBookingPolicy {
            $venue = Venue::query()->with('characteristics')->lockForUpdate()->findOrFail($venue->id);

            if (! $this->access->allows($publisher, $venue, VenuePermissionEnum::MANAGE_BOOKING_POLICY)) {
                throw new VenueBookingPolicyException('Недостаточно прав для публикации условий аренды.');
            }

            $normalized = $this->validate($venue, $data);
            $latestVersion = (int) VenueBookingPolicy::query()
                ->where('venue_id', $venue->id)
                ->max('version');

            VenueBookingPolicy::query()
                ->where('venue_id', $venue->id)
                ->where('active_marker', true)
                ->update(['active_marker' => null]);

            $policy = VenueBookingPolicy::query()->create([
                ...$normalized,
                'venue_id' => $venue->id,
                'version' => $latestVersion + 1,
                'published_by_user_id' => $publisher->id,
                'published_at' => now(),
                'active_marker' => true,
            ]);

            DB::afterCommit(static fn () => event(new VenueBookingPolicyPublished($policy->id, $venue->id)));

            return $policy;
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(Venue $venue, array $data): array
    {
        $normalized = [
            'is_enabled' => (bool) ($data['is_enabled'] ?? false),
            'allows_whole' => (bool) ($data['allows_whole'] ?? false),
            'allows_halves' => (bool) ($data['allows_halves'] ?? false),
            'minimum_duration_minutes' => (int) ($data['minimum_duration_minutes'] ?? 0),
            'maximum_duration_minutes' => (int) ($data['maximum_duration_minutes'] ?? 0),
            'time_step_minutes' => (int) ($data['time_step_minutes'] ?? 0),
            'minimum_lead_time_minutes' => (int) ($data['minimum_lead_time_minutes'] ?? 0),
            'maximum_advance_days' => (int) ($data['maximum_advance_days'] ?? 0),
            'currency' => strtoupper(trim((string) ($data['currency'] ?? ''))),
            'whole_price_per_step_minor' => (int) ($data['whole_price_per_step_minor'] ?? 0),
            'half_price_per_step_minor' => isset($data['half_price_per_step_minor'])
                ? (int) $data['half_price_per_step_minor']
                : null,
            'hold_duration_minutes' => (int) ($data['hold_duration_minutes'] ?? 0),
            'allows_hold_extension' => (bool) ($data['allows_hold_extension'] ?? false),
            'maximum_hold_extension_minutes' => isset($data['maximum_hold_extension_minutes'])
                ? (int) $data['maximum_hold_extension_minutes']
                : null,
            'requires_payment' => (bool) ($data['requires_payment'] ?? false),
            'payment_window_minutes' => isset($data['payment_window_minutes'])
                ? (int) $data['payment_window_minutes']
                : null,
            'quote_validity_minutes' => (int) ($data['quote_validity_minutes'] ?? 15),
            'cancellation_before_minutes' => isset($data['cancellation_before_minutes'])
                ? (int) $data['cancellation_before_minutes']
                : null,
        ];

        if ($normalized['is_enabled'] && ! $normalized['allows_whole'] && ! $normalized['allows_halves']) {
            throw new VenueBookingPolicyException('Включённая политика должна разрешать хотя бы одну область аренды.');
        }

        if ($normalized['allows_halves'] && (int) ($venue->characteristics?->hoops_count ?? 0) < 2) {
            throw new VenueBookingPolicyException('Раздельная аренда доступна только площадке минимум с двумя игровыми зонами.');
        }

        if ($normalized['minimum_duration_minutes'] < 15
            || $normalized['maximum_duration_minutes'] < $normalized['minimum_duration_minutes']
            || $normalized['maximum_duration_minutes'] > 1440) {
            throw new VenueBookingPolicyException('Некорректные границы длительности аренды.');
        }

        if ($normalized['time_step_minutes'] < 5 || $normalized['time_step_minutes'] > 240) {
            throw new VenueBookingPolicyException('Шаг времени должен быть от 5 до 240 минут.');
        }

        if ($normalized['minimum_duration_minutes'] % $normalized['time_step_minutes'] !== 0) {
            throw new VenueBookingPolicyException('Минимальная длительность должна быть кратна шагу времени.');
        }

        if ($normalized['minimum_lead_time_minutes'] < 0
            || $normalized['maximum_advance_days'] < 1
            || $normalized['maximum_advance_days'] > 730) {
            throw new VenueBookingPolicyException('Некорректные ограничения срока бронирования.');
        }

        if (preg_match('/^[A-Z]{3}$/', $normalized['currency']) !== 1) {
            throw new VenueBookingPolicyException('Валюта должна быть указана трёхбуквенным ISO-кодом.');
        }

        if ($normalized['whole_price_per_step_minor'] < 0
            || ($normalized['half_price_per_step_minor'] !== null && $normalized['half_price_per_step_minor'] < 0)) {
            throw new VenueBookingPolicyException('Цена не может быть отрицательной.');
        }

        if ($normalized['hold_duration_minutes'] < 1 || $normalized['hold_duration_minutes'] > 1440) {
            throw new VenueBookingPolicyException('Срок удержания должен быть от 1 минуты до 24 часов.');
        }

        if ($normalized['allows_hold_extension']
            && (($normalized['maximum_hold_extension_minutes'] ?? 0) < 1
                || $normalized['maximum_hold_extension_minutes'] > 1440)) {
            throw new VenueBookingPolicyException('Максимальное продление должно быть от 1 минуты до 24 часов.');
        }

        if (! $normalized['allows_hold_extension']) {
            $normalized['maximum_hold_extension_minutes'] = null;
        }

        if ($normalized['requires_payment'] && ($normalized['payment_window_minutes'] ?? 0) < 1) {
            throw new VenueBookingPolicyException('Для платной аренды требуется положительное платёжное окно.');
        }

        if (! $normalized['requires_payment']) {
            $normalized['payment_window_minutes'] = null;

            if ($normalized['whole_price_per_step_minor'] !== 0
                || ($normalized['half_price_per_step_minor'] ?? 0) !== 0) {
                throw new VenueBookingPolicyException('У бесплатной аренды цена должна быть равна нулю.');
            }
        } elseif (($normalized['allows_whole'] && $normalized['whole_price_per_step_minor'] < 1)
            || ($normalized['allows_halves'] && ($normalized['half_price_per_step_minor'] ?? 0) < 1)) {
            throw new VenueBookingPolicyException('Для каждой платной области должна быть задана положительная цена.');
        }

        if ($normalized['quote_validity_minutes'] < 1 || $normalized['quote_validity_minutes'] > 120) {
            throw new VenueBookingPolicyException('Срок действия расчёта должен быть от 1 до 120 минут.');
        }

        return $normalized;
    }
}
