<?php

namespace App\Modules\Event\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Models\EventVenueBookingProposal;
use App\Modules\Event\Domain\Models\EventVenueInterest;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class EventVenueBookingProposals
{
    public function __construct(
        private EventVenueInterestService $access,
        private EventVenueInterestChat $chat,
        private QuoteVenueBookingHandler $quotes,
    ) {}

    public function offer(EventVenueInterest $reference, Actor $actor): EventVenueBookingProposal
    {
        $this->chat->assertView($reference, $actor);
        abort_unless($actor->user !== null && $this->access->canPropose($actor->user, $reference), 403);

        return DB::transaction(function () use ($reference, $actor): EventVenueBookingProposal {
            // Preserve venue -> event -> interest locking order.
            $venue = Venue::query()->whereKey($reference->venue_id)->lockForUpdate()->firstOrFail();
            $event = $reference->event()->lockForUpdate()->firstOrFail();
            $interest = EventVenueInterest::query()->lockForUpdate()->findOrFail($reference->id);

            if ($interest->status !== 'open' || $event->booking_id !== null
                || ! $event->booking_deferred || $event->status->value !== 'published'
                || $interest->revision !== $event->participation_confirmation_version
                || $event->ends_at->isPast()) {
                throw new InvalidArgumentException('Предзаявка устарела или по мероприятию уже оформлена заявка.');
            }
            $existing = $interest->proposals()->where('status', 'pending')->first();
            if ($existing !== null) {
                return $existing;
            }
            $client = $event->organizerActor?->user?->canonical();
            if ($client === null || ! $client->isConfirmed()) {
                throw new InvalidArgumentException('Для получения предложения организатор должен подтвердить аккаунт.');
            }
            $quote = $this->quotes->handle(
                $venue, $event->starts_at, (int) $event->starts_at->diffInMinutes($event->ends_at),
                $event->planned_booking_scope ?? VenueBookingScopeEnum::WHOLE, $client, $event->court,
            );
            $proposal = $interest->proposals()->create([
                'initiated_by_actor_id' => $actor->id,
                'event_confirmation_version' => $event->participation_confirmation_version,
                'quoted_amount_minor' => $quote->amountMinor,
                'currency' => $quote->currency,
                'status' => 'pending',
            ]);
            $this->chat->system($interest, $actor,
                'Представитель площадки подготовил предложение аренды. Ориентировочная цена: '.
                number_format($quote->amountMinor / 100, 2, ',', ' ').' '.
                ($quote->currency === 'RUB' ? '₽' : $quote->currency).
                '. Организатор должен подтвердить актуальный расчёт, чтобы отправить заявку.');
            DB::afterCommit(fn () => $this->chat->notify($interest, $actor,
                'Вам предложили оформить аренду', 'По мероприятию «'.$event->title.'» подготовлено предложение аренды. Подтвердите или отклоните его в переписке.'));

            return $proposal;
        });
    }

    public function decline(EventVenueBookingProposal $reference, Actor $actor): void
    {
        $interest = $reference->interest;
        $this->chat->assertView($interest, $actor);
        abort_unless($this->chat->isOrganizer($interest, $actor), 403);
        DB::transaction(function () use ($reference, $interest, $actor): void {
            $proposal = EventVenueBookingProposal::query()->lockForUpdate()->findOrFail($reference->id);
            if ($proposal->status !== 'pending') {
                throw new InvalidArgumentException('Предложение уже обработано.');
            }
            $proposal->forceFill(['status' => 'declined', 'responded_at' => now()])->save();
            $this->chat->system($interest, $actor, 'Организатор отклонил предложение аренды.');
            DB::afterCommit(fn () => $this->chat->notify($interest, $actor,
                'Предложение аренды отклонено', 'Организатор мероприятия «'.$interest->event->title.'» отказался от предложенной аренды.'));
        });
    }

    public function assertPendingForOrganizer(EventVenueBookingProposal $proposal, Actor $actor): EventVenueInterest
    {
        $interest = $proposal->interest;
        $this->chat->assertView($interest, $actor);
        abort_unless($this->chat->isOrganizer($interest, $actor), 403);
        $event = $interest->event;

        if ($proposal->status !== 'pending'
            || $interest->status !== 'open'
            || $proposal->event_confirmation_version !== $event->participation_confirmation_version
            || $event->booking_id !== null
            || ! $event->booking_deferred) {
            throw new InvalidArgumentException('Предложение уже неактуально. Обновите переписку.');
        }

        return $interest;
    }
}
