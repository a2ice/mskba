<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Venue\Domain\Models\PortalCommissionPolicy;

/**
 * A single global rate is inherited by entity/action scopes. A missing or NULL
 * scoped mode means "inherit"; percent with value 0 is an explicit zero rate.
 * Only venue/rental is currently connected to a payable quote.
 */
final class PortalCommissionScopes
{
    public const GLOBAL_ENTITY = 'global';
    public const GLOBAL_ACTION = 'default';

    /** @return list<array{entity_type:string,entity_label:string,action_key:string,action_label:string,connected:bool}> */
    public static function catalog(): array
    {
        return [
            ['entity_type' => 'venue', 'entity_label' => 'Площадки', 'action_key' => 'rental', 'action_label' => 'Аренда', 'connected' => true],
            ['entity_type' => 'event', 'entity_label' => 'Мероприятия', 'action_key' => 'participation', 'action_label' => 'Участие', 'connected' => false],
            ['entity_type' => 'tournament', 'entity_label' => 'Турниры', 'action_key' => 'participation', 'action_label' => 'Участие', 'connected' => false],
            ['entity_type' => 'section', 'entity_label' => 'Секции', 'action_key' => 'subscription', 'action_label' => 'Абонемент', 'connected' => false],
            ['entity_type' => 'shop', 'entity_label' => 'Магазин', 'action_key' => 'purchase', 'action_label' => 'Покупка', 'connected' => false],
        ];
    }

    /** @return array{policy:?PortalCommissionPolicy,source:string} */
    public static function resolve(string $entityType, string $actionKey): array
    {
        $scope = PortalCommissionPolicy::query()
            ->where('entity_type', $entityType)->where('action_key', $actionKey)->first();

        if ($scope !== null && $scope->mode !== null) {
            return ['policy' => $scope, 'source' => 'scope'];
        }

        $global = PortalCommissionPolicy::query()
            ->where('entity_type', self::GLOBAL_ENTITY)
            ->where('action_key', self::GLOBAL_ACTION)->first();

        return ['policy' => $global, 'source' => 'global'];
    }

    public static function format(?PortalCommissionPolicy $policy): string
    {
        return match ($policy?->mode) {
            'percent' => number_format(($policy->value ?? 0) / 100, 2, ',', ' ').'%',
            'fixed' => number_format(($policy->value ?? 0) / 100, 2, ',', ' ').' ₽',
            default => '0%',
        };
    }
}
