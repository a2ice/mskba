<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Application\Services\VenueEventAvailability;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\VenueBooking\Domain\Models\VenueBookingQuote;
use InvalidArgumentException;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueIntegration\Application\Services\FindSportOnDemandRefresh;
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
        FindSportOnDemandRefresh $refresh,
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
            // An open day dialog can cross FindSport's 5-minute freshness
            // boundary before the visitor submits. Recheck/refresh through
            // the same guarded source lock as the day API, then let the
            // canonical quote availability service make the final decision.
            $refresh->forCourt($court);
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
                'pricing_breakdown' => [
                    'provider_amount_minor' => $quote->commissionBreakdown['provider_amount_minor'] ?? $quote->amountMinor,
                    'portal_fee_minor' => $quote->commissionBreakdown['portal_fee_minor'] ?? 0,
                    'agent_fee_minor' => $quote->commissionBreakdown['agent_fee_minor'] ?? 0,
                    'total_minor' => $quote->amountMinor,
                ],
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

    /**
     * Read-only status for an existing price quote. The random quote UUID is
     * the capability; only availability and expiration are returned. No
     * applicant, payment, or other booking details are disclosed.
     */
    public function status(
        Request $request,
        Venue $venue,
        VenueEventAvailability $availability,
        FindSportOnDemandRefresh $refresh,
    ): JsonResponse {
        $input = $request->validate(['quote_id' => ['required', 'uuid']]);
        $quote = VenueBookingQuote::query()
            ->where('venue_id', $venue->id)->where('public_id', $input['quote_id'])
            ->firstOrFail();
        $court = $venue->courts()->find($quote->venue_court_id);
        if ($court === null || $court->trashed()) {
            return response()->json(['available' => false, 'reason' => 'unavailable']);
        }
        if (! $quote->valid_until->isFuture()) {
            return response()->json(['available' => false, 'reason' => 'expired']);
        }

        // Refresh the approved source through the same guarded, shared
        // FindSport lock. A failed refresh never turns a stale slot into free.
        $refresh->forCourt($court);

        try {
            $availability->assertAvailable(
                $venue,
                CarbonImmutable::instance($quote->starts_at)->setTimezone((string) config('app.timezone', 'UTC')),
                CarbonImmutable::instance($quote->ends_at)->setTimezone((string) config('app.timezone', 'UTC')),
                scope: $quote->scope,
                court: $court,
            );
        } catch (InvalidArgumentException) {
            return response()->json(['available' => false, 'reason' => 'unavailable']);
        }

        return response()->json(['available' => true, 'reason' => null]);
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
