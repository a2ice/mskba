<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class VenueRentalQuoteController extends Controller
{
    public function show(Request $request, Venue $venue): Response
    {
        $policy = VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)
            ->where('active_marker', true)
            ->where('is_enabled', true)
            ->firstOrFail();
        $court = $this->resolveCourt($venue, (string) $request->query('court', ''));

        return ThemeResolver::page('venues.rental-quote', [
            'venue' => $venue,
            'policy' => $policy,
            'court' => $court,
            'courts' => $venue->courts()->get(),
            'quote' => null,
        ]);
    }

    public function quote(
        Request $request,
        Venue $venue,
        QuoteVenueBookingHandler $quotes,
    ): JsonResponse|Response {
        $validated = $request->validate([
            'venue_court_id' => [
                'nullable',
                'integer',
                Rule::exists('venue_courts', 'id')->where(fn ($query) => $query
                    ->where('venue_id', $venue->id)
                    ->whereNull('deleted_at')),
            ],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'scope' => ['required', Rule::enum(VenueBookingScopeEnum::class)],
        ]);
        $court = isset($validated['venue_court_id'])
            ? VenueCourt::query()->where('venue_id', $venue->id)->findOrFail((int) $validated['venue_court_id'])
            : $this->resolveCourt($venue, '');

        try {
            $timezone = $venue->schedule()->value('timezone') ?: config('app.timezone', 'Europe/Moscow');
            $quote = $quotes->handle(
                $venue,
                CarbonImmutable::parse($validated['starts_at'], $timezone),
                (int) $validated['duration_minutes'],
                VenueBookingScopeEnum::from($validated['scope']),
                $request->user(),
                $court,
            );
        } catch (VenueBookingPolicyException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage(), 'code' => 'QUOTE_UNAVAILABLE'], 422);
            }

            return ThemeResolver::page('venues.rental-quote', [
                'venue' => $venue,
                'policy' => VenueBookingPolicy::query()->where('venue_id', $venue->id)->where('active_marker', true)->first(),
                'court' => $court,
                'courts' => $venue->courts()->get(),
                'quote' => null,
                'error' => ['code' => 422, 'message' => $exception->getMessage()],
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'quote_id' => $quote->publicId,
                'policy_version_id' => $quote->policyVersionId,
                'policy_version' => $quote->policyVersion,
                'venue_court_id' => $court->id,
                'venue_court_name' => $court->name,
                'scope' => $quote->scope->value,
                'starts_at' => $quote->startsAt->toIso8601String(),
                'ends_at' => $quote->endsAt->toIso8601String(),
                'amount_minor' => $quote->amountMinor,
                'currency' => $quote->currency,
                'requires_payment' => $quote->requiresPayment,
                'hold_duration_minutes' => $quote->holdDurationMinutes,
                'payment_window_minutes' => $quote->paymentWindowMinutes,
                'valid_until' => $quote->validUntil->toIso8601String(),
            ]);
        }

        return ThemeResolver::page('venues.rental-quote', [
            'venue' => $venue,
            'policy' => VenueBookingPolicy::query()->findOrFail($quote->policyVersionId),
            'court' => $court,
            'courts' => $venue->courts()->get(),
            'quote' => $quote,
        ]);
    }

    private function resolveCourt(Venue $venue, string $identifier): VenueCourt
    {
        if ($identifier !== '') {
            return VenueCourt::query()
                ->where('venue_id', $venue->id)
                ->whereRouteIdentifier($identifier)
                ->firstOrFail();
        }

        return $venue->primaryCourt()->first() ?? $venue->courts()->firstOrFail();
    }
}
