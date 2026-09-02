<?php

namespace App\Modules\VenueBooking\Application\UseCases;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\VenueBooking\Application\Services\LockedVenueBooking;
use App\Modules\VenueBooking\Application\Services\VenueBookingAuthorization;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPartyRole;
use App\Modules\VenueBooking\Domain\Events\VenueBookingMessageSent;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingMessageSentBroadcast;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SendVenueBookingMessageHandler
{
    public function __construct(private LockedVenueBooking $lockedBooking, private VenueBookingAuthorization $authorization, private FeatureFlags $features) {}

    public function handle(int $bookingId, Actor $actor, string $clientId, string $body): VenueBookingMessage
    {
        $this->features->ensureEnabled(VenueRentalFeature::CONVERSATIONS);

        return $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($actor, $clientId, $body): VenueBookingMessage {
            $this->authorization->assertCanView($actor, $booking, $venue);
            if ($booking->flow !== 'rental') {
                throw new VenueBookingTransitionException('Переписка доступна только для заявок нового flow.', 'CONVERSATION_UNAVAILABLE');
            }
            if ($actor->user_id !== null && $actor->user_id !== $booking->requester_user_id && ! $actor->user?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
                $booking->parties()->firstOrCreate(['user_id' => $actor->user_id, 'role' => VenueBookingPartyRole::VENUE_REPRESENTATIVE]);
            }

            $conversation = VenueBookingConversation::query()->firstOrCreate(
                ['venue_booking_id' => $booking->id],
                ['public_id' => (string) Str::uuid()],
            );
            $existing = VenueBookingMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('author_actor_id', $actor->id)
                ->where('client_id', $clientId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $message = VenueBookingMessage::query()->create([
                'public_id' => (string) Str::uuid(),
                'conversation_id' => $conversation->id,
                'author_actor_id' => $actor->id,
                'client_id' => $clientId,
                'type' => 'text',
                'body' => trim($body),
            ]);
            DB::afterCommit(static function () use ($booking, $conversation, $message): void {
                event(new VenueBookingMessageSent($booking->id, $conversation->id, $message->id));
                broadcast(new VenueBookingMessageSentBroadcast($booking->public_id, $conversation->public_id, $message->public_id))->toOthers();
            });

            return $message->fresh('authorActor.user');
        });
    }
}
