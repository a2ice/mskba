<?php

namespace App\Modules\Venue\Application\Services;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\VenueAgentAgreement;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class AccountAgentAgreements
{
    /** A private, independent list: negotiations have no membership before activation. */
    public function forRecipient(User $viewer): LengthAwarePaginator
    {
        $viewer = $viewer->canonical();
        abort_unless(! $viewer->isBlocked() && ! $viewer->trashed(), 403);

        return VenueAgentAgreement::query()
            ->whereIn('user_id', $viewer->identityIds())
            ->with(['venue', 'contract'])
            ->orderByDesc('id')
            ->paginate(12, ['*'], 'agent_page')
            ->withQueryString()
            ->through(fn (VenueAgentAgreement $record): array => $this->present($record, $viewer));
    }

    private function present(VenueAgentAgreement $record, User $viewer): array
    {
        $money = app(MinorAmountParser::class);
        $venue = $record->venue;
        $canRespond = $viewer->isConfirmed() && $venue !== null
            && $venue->allowsOperationalChanges() && $venue->canonical_venue_id === null;

        return [
            'id' => $record->id,
            'venue_name' => $venue?->name ?? 'Площадка недоступна',
            'status' => $record->status,
            'status_label' => match ($record->status) {
                'proposed' => 'Ожидает вашего ответа',
                'accepted' => 'Ожидает проверки',
                'verified' => 'Ожидает активации',
                'active' => 'Действует',
                'declined' => 'Отклонено',
                'withdrawn' => 'Отозвано',
                'replaced' => 'Заменён',
                'deactivated' => 'Прекращён',
                default => 'Недоступно',
            },
            'rate_label' => match ($record->mode) {
                'percent' => $money->format($record->value, 'RUB').'%',
                'fixed' => $money->format($record->value, $record->currency).' '.$money->currencyLabel($record->currency),
                default => 'Без комиссии',
            },
            'proposed_at' => $record->proposed_at?->format('d.m.Y'),
            'terms' => $record->terms,
            'permissions' => array_map(static fn (string $code): string =>
                VenuePermissionEnum::tryFrom($code)?->label() ?? $code, $record->proposed_permissions),
            'can_accept' => $canRespond && $record->status === 'proposed',
            'can_decline' => $canRespond && in_array($record->status, ['proposed','accepted','verified'], true),
            'terms_hash' => $record->terms_hash,
            'accept_url' => route('account.agent-agreements.accept', $record->id),
            'decline_url' => route('account.agent-agreements.decline', $record->id),
            'contract_url' => $record->contract === null ? null : route('account.contracts.show', $record->contract->id),
        ];
    }
}
