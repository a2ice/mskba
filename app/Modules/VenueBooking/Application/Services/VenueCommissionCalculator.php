<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Venue\Domain\Models\PortalCommissionPolicy;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCommissionPolicy;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Domain\Models\VenueCourtCommissionPolicy;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;

/**
 * COMMISSION SOURCE: price after published tariff rules.
 * Portal and selected-agent surcharges are independently calculated from the
 * same subtotal (never compounded); they are NOT evidence of received money.
 * No fee on free venues, and no implicit fee from staff memberships.
 */
final class VenueCommissionCalculator
{
    /**
     * @return array{
     *   provider_amount_minor:int,portal_fee_minor:int,agent_fee_minor:int,
     *   total_minor:int,basis:string,portal:array<string,mixed>,agent:array<string,mixed>|null
     * }
     */
    public function calculate(Venue $venue, VenueCourt $court, int $subtotalMinor, string $currency): array
    {
        if ($subtotalMinor < 0 || $subtotalMinor > 1000000000000) {
            throw new VenueBookingPolicyException('Некорректная исходная стоимость аренды.');
        }

        $global = PortalCommissionPolicy::query()->find(1);
        $venuePolicy = VenueCommissionPolicy::query()->where('venue_id', $venue->id)->first();
        $courtPolicy = VenueCourtCommissionPolicy::query()
            ->where('venue_id', $venue->id)->where('venue_court_id', $court->id)->first();

        $portal = [
            'source' => 'global',
            'policy_id' => $global?->id,
            'version' => $global?->version ?? 0,
            'mode' => $global?->mode ?? 'none',
            'value' => $global?->value ?? 0,
            'currency' => $global?->currency ?? 'RUB',
        ];

        if ($venuePolicy?->mode_override !== null) {
            $portal = [
                'source' => 'venue',
                'policy_id' => $venuePolicy->id,
                'version' => $venuePolicy->version,
                'mode' => $venuePolicy->mode_override,
                'value' => $venuePolicy->value_override ?? 0,
                'currency' => $venuePolicy->currency,
            ];
        }
        if ($courtPolicy?->mode_override !== null) {
            $portal = [
                'source' => 'court',
                'policy_id' => $courtPolicy->id,
                'version' => $courtPolicy->version,
                'mode' => $courtPolicy->mode_override,
                'value' => $courtPolicy->value_override ?? 0,
                'currency' => $courtPolicy->currency,
            ];
        }

        $portalFee = $this->fee($subtotalMinor, $currency, $portal);
        $agent = null;
        $agentFee = 0;

        if ($venuePolicy?->selected_agent_id !== null) {
            $assignment = $venuePolicy->selectedAgent()->with('user')->first();
            if ($assignment !== null && (int) $assignment->venue_id === (int) $venue->id
                && $assignment->is_active && $assignment->user !== null
                && $assignment->user->isConfirmed() && ! $assignment->user->isBlocked()
                && ! $assignment->user->trashed()) {
                $agent = [
                    'assignment_id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'version' => $assignment->version,
                    'mode' => $assignment->mode,
                    'value' => $assignment->value,
                    'currency' => $assignment->currency,
                ];
                $agentFee = $this->fee($subtotalMinor, $currency, $agent);
            }
        }

        $total = $subtotalMinor + $portalFee + $agentFee;
        if ($total > 1000000000000) {
            throw new VenueBookingPolicyException('Итоговая стоимость с комиссиями превышает допустимую.');
        }

        return [
            'provider_amount_minor' => $subtotalMinor,
            'portal_fee_minor' => $portalFee,
            'agent_fee_minor' => $agentFee,
            'total_minor' => $total,
            'basis' => 'price_after_tariff_rules',
            'portal' => $portal,
            'agent' => $agent,
        ];
    }

    /** @param array<string,mixed> $rule */
    private function fee(int $amount, string $currency, array $rule): int
    {
        if ($amount === 0 || $rule['mode'] === 'none') {
            return 0;
        }
        if ($rule['mode'] === 'fixed') {
            if ($rule['currency'] !== $currency) {
                throw new VenueBookingPolicyException('Валюта фиксированной комиссии не совпадает с валютой аренды.');
            }

            return (int) $rule['value'];
        }
        if ($rule['mode'] === 'percent') {
            return intdiv($amount * (int) $rule['value'] + 5000, 10000);
        }

        throw new VenueBookingPolicyException('Неизвестный способ расчёта комиссии.');
    }
}
