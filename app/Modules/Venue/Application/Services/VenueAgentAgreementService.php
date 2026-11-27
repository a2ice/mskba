<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueAgentAgreement;
use App\Modules\VenueBooking\Application\Services\VenueCommissionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Negotiation only. Neither proposal nor acceptance writes permissions or economic settings. */
final class VenueAgentAgreementService
{
    /** @param list<VenuePermissionEnum> $permissions */
    public function propose(Venue $venue, User $candidate, User $actor, string $mode, string $input,
        array $permissions, string $terms): VenueAgentAgreement
    {
        $terms = trim($terms);
        if (mb_strlen($terms) < 10 || mb_strlen($terms) > 4000) {
            throw ValidationException::withMessages(['terms' => 'Укажите условия договора (10–4000 символов).']);
        }
        $values = [];
        foreach ($permissions as $permission) {
            if (! $permission instanceof VenuePermissionEnum
                || ! in_array($permission, VenueMembershipAccessLevelEnum::AGENT->allowedPermissions(), true)) {
                throw ValidationException::withMessages(['permissions' => 'Полномочие не входит в допустимый набор агентской роли.']);
            }
            $values[$permission->value] = $permission->value;
        }
        $values = array_values($values);
        sort($values, SORT_STRING);

        return DB::transaction(function () use ($venue, $candidate, $actor, $mode, $input, $values, $terms): VenueAgentAgreement {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $actor = $this->lockActor($actor);
            $this->authorizePortal($actor);
            $candidate = $this->lockActor($candidate);
            abort_unless(! $actor->isSameIdentity($candidate), 403);
            $this->assertVenue($venue);

            if (VenueAgentAgreement::query()->where('venue_id', $venue->id)
                ->whereIn('user_id', $candidate->identityIds())->whereIn('status', ['proposed', 'accepted'])->exists()) {
                throw ValidationException::withMessages(['user_id' => 'У пользователя уже есть незавершённое предложение. Сначала закройте его.']);
            }

            $currency = $venue->bookingPolicyCurrency();
            [$normalizedMode, $value] = app(VenueCommissionManager::class)->parseAgentTerms($mode, $input, $currency);
            $record = new VenueAgentAgreement([
                'venue_id' => $venue->id, 'user_id' => $candidate->id,
                'proposed_by_user_id' => $actor->id, 'status' => 'proposed',
                'mode' => $normalizedMode, 'value' => $value, 'currency' => $currency,
                'proposed_permissions' => $values, 'terms' => $terms, 'proposed_at' => now(),
            ]);
            $record->terms_hash = $record->calculatedTermsHash();
            $record->save();

            return $record;
        }, 3);
    }

    public function accept(Venue $venue, VenueAgentAgreement $agreement, User $actor, string $termsHash): VenueAgentAgreement
    {
        return DB::transaction(function () use ($venue, $agreement, $actor, $termsHash): VenueAgentAgreement {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $this->assertVenue($venue);
            $record = $this->lockAgreement($venue, $agreement);
            $actor = $this->lockActor($actor);
            abort_unless($actor->isSameIdentity((int) $record->user_id), 403);
            if (! hash_equals($record->terms_hash, $termsHash)
                || ! hash_equals($record->terms_hash, $record->calculatedTermsHash())) {
                throw ValidationException::withMessages(['terms' => 'Условия договора изменились. Откройте актуальное предложение.']);
            }
            if ($record->status === 'accepted') {
                return $record;
            }
            abort_unless($record->status === 'proposed', 409);
            $record->update(['status' => 'accepted', 'accepted_at' => now(), 'accepted_by_user_id' => $actor->id]);

            return $record;
        }, 3);
    }

    /** Close only pending negotiations, never an effective financial agreement. */
    public function close(Venue $venue, VenueAgentAgreement $agreement, User $actor, string $reason): VenueAgentAgreement
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Укажите причину (10–500 символов).']);
        }
        return DB::transaction(function () use ($venue, $agreement, $actor, $reason): VenueAgentAgreement {
            $venue = Venue::query()->withTrashed()->lockForUpdate()->findOrFail($venue->id);
            $record = $this->lockAgreement($venue, $agreement);
            $actor = $this->lockActor($actor);
            $recipient = $actor->isSameIdentity((int) $record->user_id);
            if (! $recipient) {
                $this->authorizePortal($actor);
            }
            abort_unless(in_array($record->status, ['proposed', 'accepted'], true)
                && $record->contract_id === null && $record->commission_agent_id === null, 409);
            $record->update(['status' => $recipient ? 'declined' : 'withdrawn',
                'closed_at' => now(), 'closed_by_user_id' => $actor->id, 'closure_reason' => $reason]);

            return $record;
        }, 3);
    }

    private function lockAgreement(Venue $venue, VenueAgentAgreement $agreement): VenueAgentAgreement
    {
        return VenueAgentAgreement::query()->where('venue_id', $venue->id)->lockForUpdate()->findOrFail($agreement->id);
    }

    private function lockActor(User $user): User
    {
        $user = User::query()->lockForUpdate()->findOrFail($user->canonical()->id);
        abort_unless($user->canonical_user_id === null && $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed(), 403);

        return $user;
    }

    private function authorizePortal(User $actor): void
    {
        // Explicit delegated financial authority is not inferred from staff
        // roles or venue permissions. Start with superadmin only.
        abort_unless($actor->system_role === UserSystemRoleEnum::SUPERADMIN, 403);
    }

    private function assertVenue(Venue $venue): void
    {
        abort_unless($venue->allowsOperationalChanges() && $venue->canonical_venue_id === null, 409);
    }
}
