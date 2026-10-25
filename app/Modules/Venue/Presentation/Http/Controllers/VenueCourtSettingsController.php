<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VenueCourtSettingsController extends Controller
{
    public function updatePolicy(
        Request $request, string $venue, string $court,
        VenueCommercialAccess $access, MinorAmountParser $amounts,
        VenueAccessResolver $venueAccess, CurrentActorResolver $actors,
    ): RedirectResponse {
        [$venueModel, $courtModel] = $this->target($venue, $court);
        abort_unless($request->user() !== null && $access->allowsForCourt(
            $request->user(), $courtModel, VenuePermissionEnum::MANAGE_BOOKING_POLICY
        ), 403);

        $validated = $request->validate([
            'operational_status' => ['sometimes', Rule::in(['inherit', 'active', 'temporarily_closed'])],
            'requires_payment' => ['sometimes', Rule::in(['inherit', 'yes', 'no'])],
            'requires_booking' => ['sometimes', Rule::in(['inherit', 'yes', 'no'])],
            'requires_booking_approval' => ['sometimes', Rule::in(['inherit', 'yes', 'no'])],
            'rental_enabled' => ['sometimes', Rule::in(['inherit', 'yes', 'no'])],
            'whole_price_per_step' => ['sometimes', 'nullable', 'string', 'max:32'],
            'half_price_per_step' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        if (array_key_exists('operational_status', $validated)) {
            abort_unless($venueAccess->canEditCourtSchedule(
                $request->user(), $courtModel, $actors->resolveForRequest($request)
            ), 403);
        }
        $values = [];
        foreach (['requires_payment', 'requires_booking', 'requires_booking_approval', 'rental_enabled'] as $field) {
            if (array_key_exists($field, $validated)) {
                $values[$field] = match ($validated[$field]) {
                    'yes' => true, 'no' => false, default => null,
                };
            }
        }
        if (array_key_exists('operational_status', $validated)) {
            $values['operational_status'] = $validated['operational_status'] === 'inherit'
                ? null : $validated['operational_status'];
        }
        $currency = $venueModel->bookingPolicyCurrency();
        foreach (['whole', 'half'] as $scope) {
            $key = $scope.'_price_per_step';
            if (! array_key_exists($key, $validated)) {
                continue;
            }
            $raw = trim((string) ($validated[$key] ?? ''));
            try {
                $values[$key.'_minor'] = $raw === '' ? null : $amounts->parse($raw, $currency);
            } catch (VenueBookingTransitionException $exception) {
                throw ValidationException::withMessages([$key => $exception->getMessage()]);
            }
        }
        $courtModel->update($values);

        return back()->with('status', 'Индивидуальные условия зала сохранены.');
    }

    public function storePrice(
        Request $request, string $venue, string $court,
        VenueCommercialAccess $access, MinorAmountParser $amounts,
    ): RedirectResponse {
        [$venueModel, $courtModel] = $this->target($venue, $court);
        abort_unless($request->user() !== null && $access->allowsForCourt(
            $request->user(), $courtModel, VenuePermissionEnum::MANAGE_BOOKING_POLICY
        ), 403);
        $data = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'whole_price_per_step' => ['nullable', 'string', 'max:32'],
            'half_price_per_step' => ['nullable', 'string', 'max:32'],
        ]);
        $currency = $venueModel->bookingPolicyCurrency();
        $values = [];
        foreach (['whole', 'half'] as $scope) {
            $key = $scope.'_price_per_step';
            $raw = trim((string) ($data[$key] ?? ''));
            try {
                $values[$key.'_minor'] = $raw === '' ? null : $amounts->parse($raw, $currency);
            } catch (VenueBookingTransitionException $exception) {
                throw ValidationException::withMessages([$key => $exception->getMessage()]);
            }
        }
        if ($values['whole_price_per_step_minor'] === null && $values['half_price_per_step_minor'] === null) {
            throw ValidationException::withMessages(['whole_price_per_step' => 'Укажите хотя бы одну цену.']);
        }
        $courtModel->slotPrices()->updateOrCreate([
            'day_of_week' => (int) $data['day_of_week'],
            'starts_at' => $data['starts_at'].':00',
        ], $values);

        return back()->with('status', 'Тарифный интервал зала сохранён.');
    }

    public function destroyPrice(
        Request $request, string $venue, string $court, int $price,
        VenueCommercialAccess $access,
    ): RedirectResponse {
        [, $courtModel] = $this->target($venue, $court);
        abort_unless($request->user() !== null && $access->allowsForCourt(
            $request->user(), $courtModel, VenuePermissionEnum::MANAGE_BOOKING_POLICY
        ), 403);
        $courtModel->slotPrices()->findOrFail($price)->delete();

        return back()->with('status', 'Тарифный интервал удалён.');
    }

    public function updateSchedule(
        Request $request, string $venue, string $court,
        CurrentActorResolver $actors, VenueAccessResolver $access,
    ): RedirectResponse {
        [, $courtModel] = $this->target($venue, $court);
        abort_unless($access->canEditCourtSchedule(
            $request->user(), $courtModel, $actors->resolveForRequest($request)
        ), 403);
        $data = $request->validate([
            'inherit' => ['required', 'boolean'],
            'weekly_intervals' => ['array', 'max:70'],
            'weekly_intervals.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'weekly_intervals.*.starts_at' => ['required', 'date_format:H:i'],
            'weekly_intervals.*.ends_at' => ['required', 'date_format:H:i'],
            'exceptions' => ['array', 'max:365'],
            'exceptions.*.date' => ['required', 'date_format:Y-m-d'],
            'exceptions.*.is_closed' => ['required', 'boolean'],
            'exceptions.*.starts_at' => ['nullable', 'date_format:H:i'],
            'exceptions.*.ends_at' => ['nullable', 'date_format:H:i'],
        ]);
        if ($data['inherit']) {
            $courtModel->schedule()->delete();

            return back()->with('status', 'Зал наследует график площадки.');
        }

        foreach ($data['weekly_intervals'] ?? [] as $interval) {
            if ($interval['starts_at'] >= $interval['ends_at']) {
                throw ValidationException::withMessages([
                    'weekly_intervals' => 'Конец рабочего интервала должен быть позже начала.',
                ]);
            }
        }
        foreach ($data['exceptions'] ?? [] as $exception) {
            if (! $exception['is_closed'] && (empty($exception['starts_at'])
                || empty($exception['ends_at']) || $exception['starts_at'] >= $exception['ends_at'])) {
                throw ValidationException::withMessages([
                    'exceptions' => 'У исключения часы должны быть заданы корректно.',
                ]);
            }
        }
        $courtModel->schedule()->updateOrCreate(
            ['venue_court_id' => $courtModel->id],
            [
                'weekly_intervals' => array_values($data['weekly_intervals'] ?? []),
                'exceptions' => array_values($data['exceptions'] ?? []),
            ],
        );

        return back()->with('status', 'Индивидуальный график сохранён.');
    }

    /** @return array{Venue, VenueCourt} */
    private function target(string $venue, string $court): array
    {
        $venueModel = Venue::query()->whereRouteIdentifier($venue)->firstOrFail();
        $courtModel = $venueModel->courts()->whereRouteIdentifier($court)->firstOrFail();

        return [$venueModel, $courtModel];
    }
}
