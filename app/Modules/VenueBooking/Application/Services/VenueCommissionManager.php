<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\PortalCommissionPolicy;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCommissionAgent;
use App\Modules\Venue\Domain\Models\VenueCommissionPolicy;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Domain\Models\VenueCourtCommissionPolicy;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Economic commission settings are separate from venue staff/contract access.
 * Superadmin-only until contractual authorization for commission changes exists.
 * No payments are collected or distributed by this service.
 */
final readonly class VenueCommissionManager
{
    public function __construct(private MinorAmountParser $money) {}

    public function setPortal(User $actor, string $mode, string $input = '0', string $currency = 'RUB'): PortalCommissionPolicy
    {
        $actor = $this->superadmin($actor);
        [$mode, $value] = $this->parse($mode, $input, $currency, false);

        return DB::transaction(static function () use ($actor, $mode, $value, $currency): PortalCommissionPolicy {
            $policy = PortalCommissionPolicy::query()->lockForUpdate()->find(1)
                ?? new PortalCommissionPolicy(['id' => 1]);

            $policy->fill([
                'mode' => $mode,
                'value' => $value,
                'currency' => $currency,
                'version' => $policy->exists ? $policy->version + 1 : 1,
                'updated_by_user_id' => $actor->id,
            ])->save();

            return $policy;
        }, 3);
    }

    public function setVenue(
        Venue $venue, User $actor, string $mode,
        string $input = '0', ?int $selectedAgentId = null,
    ): VenueCommissionPolicy {
        $actor = $this->superadmin($actor);
        $currency = $venue->bookingPolicyCurrency();
        [$mode, $value] = $this->parse($mode, $input, $currency, true);

        return DB::transaction(function () use (
            $venue, $actor, $currency, $mode, $value, $selectedAgentId,
        ): VenueCommissionPolicy {
            Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            if ($selectedAgentId !== null) {
                $agent = VenueCommissionAgent::query()->where('venue_id', $venue->id)
                    ->where('is_active', true)->findOrFail($selectedAgentId);
                $user = $agent->user;
                if ($user === null || ! $user->isConfirmed() || $user->isBlocked() || $user->trashed()) {
                    throw ValidationException::withMessages(['selected_agent_id' => 'Выбранный агент неактивен.']);
                }
            }
            $policy = VenueCommissionPolicy::query()->where('venue_id', $venue->id)->lockForUpdate()->first()
                ?? new VenueCommissionPolicy(['venue_id' => $venue->id]);
            $policy->fill([
                'mode_override' => $mode,
                'value_override' => $value,
                'currency' => $currency,
                'selected_agent_id' => $selectedAgentId,
                'version' => $policy->exists ? $policy->version + 1 : 1,
                'updated_by_user_id' => $actor->id,
            ])->save();

            return $policy;
        }, 3);
    }

    public function setCourt(
        Venue $venue, VenueCourt $court, User $actor, string $mode, string $input = '0',
    ): VenueCourtCommissionPolicy {
        $actor = $this->superadmin($actor);
        abort_unless((int) $court->venue_id === (int) $venue->id && ! $court->trashed(), 404);
        $currency = $venue->bookingPolicyCurrency();
        [$mode, $value] = $this->parse($mode, $input, $currency, true);

        return DB::transaction(function () use (
            $venue, $court, $actor, $currency, $mode, $value,
        ): VenueCourtCommissionPolicy {
            Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            $policy = VenueCourtCommissionPolicy::query()
                ->where('venue_id', $venue->id)->where('venue_court_id', $court->id)
                ->lockForUpdate()->first() ?? new VenueCourtCommissionPolicy([
                    'venue_id' => $venue->id,
                    'venue_court_id' => $court->id,
                ]);
            $policy->fill([
                'mode_override' => $mode,
                'value_override' => $value,
                'currency' => $currency,
                'version' => $policy->exists ? $policy->version + 1 : 1,
                'updated_by_user_id' => $actor->id,
            ])->save();

            return $policy;
        }, 3);
    }

    public function saveAgent(
        Venue $venue, User $actor, User $candidate,
        string $mode, string $input = '0', bool $active = true,
    ): VenueCommissionAgent {
        $actor = $this->superadmin($actor);
        $candidate = $candidate->canonical();
        if (! $candidate->isConfirmed() || $candidate->isBlocked() || $candidate->trashed()) {
            throw ValidationException::withMessages([
                'user_id' => 'Коммерческим агентом может быть только подтверждённый активный пользователь.',
            ]);
        }
        $currency = $venue->bookingPolicyCurrency();
        [$mode, $value] = $this->parse($mode, $input, $currency, false);

        return DB::transaction(function () use (
            $venue, $actor, $candidate, $mode, $value, $currency, $active,
        ): VenueCommissionAgent {
            Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            $agent = VenueCommissionAgent::query()
                ->where('venue_id', $venue->id)->where('user_id', $candidate->id)
                ->lockForUpdate()->first() ?? new VenueCommissionAgent([
                    'venue_id' => $venue->id, 'user_id' => $candidate->id,
                ]);
            $agent->fill([
                'is_active' => $active,
                'mode' => $mode,
                'value' => $value,
                'currency' => $currency,
                'version' => $agent->exists ? $agent->version + 1 : 1,
                'updated_by_user_id' => $actor->id,
            ])->save();

            if (! $active) {
                VenueCommissionPolicy::query()->where('venue_id', $venue->id)
                    ->where('selected_agent_id', $agent->id)->update([
                        'selected_agent_id' => null, 'updated_by_user_id' => $actor->id,
                        'updated_at' => now(),
                    ]);
            }

            return $agent;
        }, 3);
    }

    /** @return array{?string,?int} */
    private function parse(string $mode, string $input, string $currency, bool $allowInherit): array
    {
        if ($allowInherit && $mode === 'inherit') {
            return [null, null];
        }
        if ($mode === 'none') {
            return ['none', 0];
        }
        if ($mode === 'percent') {
            $value = str_replace(',', '.', trim($input));
            if (! preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,2}))?$/D', $value, $matches)) {
                throw ValidationException::withMessages(['value' => 'Процент должен быть числом от 0 до 100.']);
            }
            $basisPoints = (int) $matches[1] * 100
                + (int) str_pad($matches[2] ?? '', 2, '0');
            if ($basisPoints > 10000) {
                throw ValidationException::withMessages(['value' => 'Процент не может превышать 100%.']);
            }

            return ['percent', $basisPoints];
        }
        if ($mode === 'fixed') {
            if ($currency !== 'RUB') {
                throw ValidationException::withMessages(['value' => 'Фиксированная комиссия для этой валюты пока не поддерживается.']);
            }
            try {
                $amount = $this->money->parse(trim($input), $currency);
            } catch (VenueBookingTransitionException $exception) {
                throw ValidationException::withMessages(['value' => $exception->getMessage()]);
            }
            if ($amount > 1000000000000) {
                throw ValidationException::withMessages(['value' => 'Превышен максимально допустимый размер комиссии.']);
            }

            return ['fixed', $amount];
        }

        throw ValidationException::withMessages(['mode' => 'Выберите допустимый тип комиссии.']);
    }

    private function superadmin(User $actor): User
    {
        $actor = $actor->canonical();
        abort_unless($actor->isConfirmed() && $actor->hasSystemRole(UserSystemRoleEnum::SUPERADMIN), 403);

        return $actor;
    }
}
