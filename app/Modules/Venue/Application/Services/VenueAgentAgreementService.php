<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Contract\Application\Services\ContractAssurance;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Team\Domain\Enums\TeamInvitationStatusEnum;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Models\VenueCommissionPolicy;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueAgentAgreement;
use App\Modules\VenueBooking\Application\Services\VenueCommissionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Negotiation and explicit activation. Proposal, acceptance and review never grant economic or ACL rights. */
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
                ->whereIn('user_id', $candidate->identityIds())->whereIn('status', ['proposed', 'accepted', 'verified'])->exists()) {
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

    /** Record a human review of an existing document or external agreement reference. */
    public function verify(Venue $venue, VenueAgentAgreement $agreement, User $actor, string $evidence, string $reason): VenueAgentAgreement
    {
        $evidence = trim($evidence);
        $reason = trim($reason);
        if (mb_strlen($evidence) < 5 || mb_strlen($evidence) > 500 || mb_strlen($reason) < 10 || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['evidence_reference' => 'Укажите документ или проверенное основание (5–500 символов) и причину проверки (10–2000 символов).']);
        }

        return DB::transaction(function () use ($venue, $agreement, $actor, $evidence, $reason): VenueAgentAgreement {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $this->assertVenue($venue);
            $record = $this->lockAgreement($venue, $agreement);
            $actor = $this->lockActor($actor);
            $this->authorizePortal($actor);
            $this->assertAcceptedTerms($record);
            abort_unless($record->status === 'accepted' && ! $actor->isSameIdentity((int) $record->user_id), 409);
            $record->update(['status' => 'verified', 'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(), 'evidence_reference' => $evidence, 'review_reason' => $reason]);

            return $record;
        }, 3);
    }

    /** Initial activation only. Existing financial appointments require a separate replacement decision. */
    public function activate(Venue $venue, VenueAgentAgreement $agreement, User $actor): VenueAgentAgreement
    {
        return DB::transaction(function () use ($venue, $agreement, $actor): VenueAgentAgreement {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $this->assertVenue($venue);
            $record = $this->lockAgreement($venue, $agreement);
            $actor = $this->lockActor($actor);
            $this->authorizePortal($actor);
            abort_if($actor->isSameIdentity((int) $record->user_id), 403);
            if ($record->status === 'active' && $record->active_marker === true) {
                return $record;
            }
            abort_unless($record->status === 'verified' && $record->reviewed_at !== null
                && filled($record->evidence_reference) && $record->contract_id === null
                && $record->commission_agent_id === null, 409);
            $this->assertAcceptedTerms($record);
            $candidate = $this->lockActor(User::query()->findOrFail($record->user_id));
            if ((int) $candidate->id !== (int) $record->user_id || $record->currency !== $venue->bookingPolicyCurrency()) {
                throw ValidationException::withMessages(['terms' => 'Идентичность агента или валюта изменились. Нужно согласовать новое предложение.']);
            }

            $policy = VenueCommissionPolicy::query()->where('venue_id', $venue->id)->lockForUpdate()->first();
            if ($policy?->selected_agent_id !== null || VenueAgentAgreement::query()
                ->where('venue_id', $venue->id)->where('active_marker', true)->exists()) {
                throw ValidationException::withMessages(['agreement' => 'У площадки уже выбран агент. Используйте отдельную процедуру замены с сохранением обязательств.']);
            }
            $existing = app(VenueMembershipAccess::class)->activeVenueMembershipsFor($candidate)
                ->contains(fn ($m): bool => $m->scope_type->value === 'venue'
                    && (int) $m->scope_id === (int) $venue->id && $m->access_level === 'agent');
            if ($existing) {
                throw ValidationException::withMessages(['agreement' => 'У пользователя уже есть действующий агентский контракт. Требуется отдельное согласование связи договоров.']);
            }

            $permissions = [];
            foreach ($record->proposed_permissions as $value) {
                $permission = VenuePermissionEnum::tryFrom($value);
                if ($permission === null || ! in_array($permission, VenueMembershipAccessLevelEnum::AGENT->allowedPermissions(), true)) {
                    throw ValidationException::withMessages(['permissions' => 'Набор согласованных полномочий больше не поддерживается.']);
                }
                $permissions[] = $permission;
            }

            $commission = app(VenueCommissionManager::class);
            $rate = match ($record->mode) {
                'percent' => sprintf('%d.%02d', intdiv($record->value, 100), $record->value % 100),
                'fixed' => app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class)->format($record->value, $record->currency),
                'none' => '0',
            };
            // The existing finance service owns normalization/versioning.
            $assignment = $commission->saveAgent($venue, $actor, $candidate, $record->mode, $rate);
            $membership = app(ManageVenueMembershipHandler::class)->grant(
                $venue, $candidate, VenueMembershipAccessLevelEnum::AGENT, $actor, $permissions,
            );
            $contract = $membership->contract;
            $contract->update(['comment' => VenueAgentAgreement::CONTRACT_MARKER,
                'legal_nature' => 'legal_agreement', 'verification_requirement' => 'required']);
            $membership->update(['invitation_status' => TeamInvitationStatusEnum::ACCEPTED]);
            app(ContractAssurance::class)->recordVerified($contract, 'venue_agent_agreement',
                $record->id, $record->reviewed_by_user_id, ['decision_reason' => $record->review_reason]);

            // Only choose the new assignment. Preserve all existing MSKBA and hall commission settings.
            $policy ??= new VenueCommissionPolicy(['venue_id' => $venue->id,
                'currency' => $record->currency, 'disable_all_commissions' => false]);
            $policy->fill(['selected_agent_id' => $assignment->id,
                'version' => $policy->exists ? $policy->version + 1 : 1,
                'updated_by_user_id' => $actor->id])->save();
            $record->update(['status' => 'active', 'active_marker' => true,
                'contract_id' => $contract->id, 'commission_agent_id' => $assignment->id,
                'activated_by_user_id' => $actor->id, 'activated_at' => now()]);

            return $record;
        }, 3);
    }

    private function assertAcceptedTerms(VenueAgentAgreement $record): void
    {
        if ($record->accepted_at === null || $record->accepted_by_user_id === null
            || ! hash_equals($record->terms_hash, $record->calculatedTermsHash())) {
            throw ValidationException::withMessages(['terms' => 'Нет действительного акцепта сохранённых условий.']);
        }
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
            abort_unless(in_array($record->status, ['proposed', 'accepted', 'verified'], true)
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
