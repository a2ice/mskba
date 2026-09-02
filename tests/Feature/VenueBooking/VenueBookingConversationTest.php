<?php

namespace Tests\Feature\VenueBooking;

use App\Modules\Contract\Domain\Enums\ContractFamilyEnum;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Enums\ContractStatusEnum;
use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleAssignerEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Queries\GetVenueBookingConversationSummary;
use App\Modules\VenueBooking\Application\UseCases\AttachVenueBookingConversationFileHandler;
use App\Modules\VenueBooking\Application\UseCases\MarkVenueBookingConversationReadHandler;
use App\Modules\VenueBooking\Application\UseCases\SendVenueBookingMessageHandler;
use App\Modules\VenueBooking\Domain\Enums\VenueBookingPaymentState;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingChannel;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingMessageSentBroadcast;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingUpdatedBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueBookingConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.conversations', true);
    }

    public function test_parties_can_send_idempotently_but_outsider_cannot_and_booking_is_unchanged(): void
    {
        [, $ownerActor, $venue] = $this->ownedVenue();
        [$requester, $requesterActor] = $this->userAndActor();
        [, $outsiderActor] = $this->userAndActor();
        $booking = $this->booking($venue, $requester, $requesterActor);
        $clientId = (string) Str::uuid();
        $handler = app(SendVenueBookingMessageHandler::class);

        $first = $handler->handle($booking->id, $requesterActor, $clientId, '<script>alert(1)</script>');
        $repeat = $handler->handle($booking->id, $requesterActor, $clientId, 'другой текст');
        $handler->handle($booking->id, $ownerActor, (string) Str::uuid(), 'Ответ площадки');

        $this->assertSame($first->id, $repeat->id);
        $this->assertSame(2, $first->conversation->messages()->count());
        $this->assertSame(1, $booking->refresh()->optimistic_version);
        $this->assertDatabaseCount('venue_booking_message_deliveries', 1);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $requester->id, 'title' => 'Новое сообщение по аренде']);
        $this->expectForbidden(fn () => $handler->handle($booking->id, $outsiderActor, (string) Str::uuid(), 'Чужое сообщение'));

        $this->actingAs($requester)->get(route('account.venue-bookings.show', $booking))
            ->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_superadmin_support_view_is_audited(): void
    {
        [, , $venue] = $this->ownedVenue();
        [$requester, $requesterActor] = $this->userAndActor();
        $booking = $this->booking($venue, $requester, $requesterActor);
        app(SendVenueBookingMessageHandler::class)->handle($booking->id, $requesterActor, (string) Str::uuid(), 'Нужна поддержка');
        $superadmin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::SUPERADMIN]);

        $this->actingAs($superadmin)->getJson(route('account.venue-bookings.conversation.index', $booking))->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => VenueBooking::class, 'auditable_id' => $booking->id,
            'event' => 'conversation_viewed_for_support',
        ]);
    }

    public function test_read_markers_are_per_user_and_polling_recovers_messages(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();
        [$requester, $requesterActor] = $this->userAndActor();
        $booking = $this->booking($venue, $requester, $requesterActor);
        $message = app(SendVenueBookingMessageHandler::class)->handle($booking->id, $requesterActor, (string) Str::uuid(), 'Первое');
        $conversation = $message->conversation;

        app(MarkVenueBookingConversationReadHandler::class)->handle($booking->id, $conversation->id, $requesterActor, $message->id);
        $this->assertDatabaseHas('venue_booking_conversation_reads', ['conversation_id' => $conversation->id, 'user_id' => $requester->id]);
        $this->assertDatabaseMissing('venue_booking_conversation_reads', ['conversation_id' => $conversation->id, 'user_id' => $owner->id]);

        $this->actingAs($owner)->getJson(route('account.venue-bookings.conversation.index', [$booking, 'after_id' => 0]))
            ->assertOk()->assertJsonPath('messages.0.body', 'Первое');
    }

    public function test_attachments_are_private_limited_and_broadcast_uses_private_channel(): void
    {
        Storage::fake('local');
        [, , $venue] = $this->ownedVenue();
        [$requester, $actor] = $this->userAndActor();
        $booking = $this->booking($venue, $requester, $actor);
        $handler = app(AttachVenueBookingConversationFileHandler::class);
        $message = $handler->handle($booking->id, $actor, (string) Str::uuid(), 'pdf', '../check.pdf', 'application/pdf', 3);

        Storage::disk('local')->assertExists($message->attachment_path);
        $this->assertSame('check.pdf', $message->attachment_name);
        try {
            $handler->handle($booking->id, $actor, (string) Str::uuid(), '<svg/>', 'x.svg', 'image/svg+xml', 6);
            $this->fail('Active attachment must be rejected.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('INVALID_CONVERSATION_ATTACHMENT', $exception->errorCode);
        }

        $broadcast = new VenueBookingMessageSentBroadcast($booking->public_id, $message->conversation->public_id, $message->public_id);
        $channels = $broadcast->broadcastOn();
        $this->assertContainsOnlyInstancesOf(PrivateChannel::class, $channels);
        $this->assertSame([
            'private-venue-bookings.'.$booking->public_id,
            'private-venue-booking-conversations.'.$message->conversation->public_id,
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $channels));
    }

    public function test_booking_channel_is_private_and_available_only_to_booking_parties(): void
    {
        [$owner, , $venue] = $this->ownedVenue();
        [$requester, $requesterActor] = $this->userAndActor();
        [$outsider] = $this->userAndActor();
        $booking = $this->booking($venue, $requester, $requesterActor);
        $channel = app(VenueBookingChannel::class);

        $this->assertTrue($channel->join($requester, $booking->public_id));
        $this->assertTrue($channel->join($owner, $booking->public_id));
        $this->assertFalse($channel->join($outsider, $booking->public_id));
        $this->assertFalse($channel->join($requester, (string) Str::uuid()));

        $broadcast = new VenueBookingUpdatedBroadcast($booking->public_id, 2);
        $this->assertSame('private-venue-bookings.'.$booking->public_id, $broadcast->broadcastOn()->name);
        $this->assertSame(['booking_id' => $booking->public_id, 'version' => 2], $broadcast->broadcastWith());
    }

    public function test_unread_summary_excludes_own_messages_and_is_exposed_in_booking_details(): void
    {
        [$owner, $ownerActor, $venue] = $this->ownedVenue();
        [$requester, $requesterActor] = $this->userAndActor();
        $booking = $this->booking($venue, $requester, $requesterActor);
        $messages = app(SendVenueBookingMessageHandler::class);
        $first = $messages->handle($booking->id, $requesterActor, (string) Str::uuid(), 'Вопрос заявителя');
        $second = $messages->handle($booking->id, $ownerActor, (string) Str::uuid(), 'Ответ площадки');
        $summary = app(GetVenueBookingConversationSummary::class);

        $this->assertSame(1, $summary->handle($booking, $requesterActor)['unread_count']);
        $this->assertSame(1, $summary->handle($booking, $ownerActor)['unread_count']);

        $this->actingAs($owner)->get(route('account.venue-bookings.show', $booking))
            ->assertOk()
            ->assertSee($requester->username)
            ->assertSee('venue-booking-applicant__button', false)
            ->assertSee('data-modal="venue-booking-conversation"', false);

        app(MarkVenueBookingConversationReadHandler::class)->handle(
            $booking->id,
            $first->conversation_id,
            $ownerActor,
            $second->id,
        );
        $this->assertSame(0, $summary->handle($booking, $ownerActor)['unread_count']);

        $this->actingAs($requester)->getJson(route('account.venue-bookings.show', $booking))
            ->assertOk()
            ->assertJsonPath('requester.name', $requester->username)
            ->assertJsonPath('conversation.unread_count', 1)
            ->assertJsonPath('conversation.latest_message_id', $second->public_id);

        $this->actingAs($requester)->getJson(route('account.venue-bookings.conversation.index', $booking))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('latest_message_id', $second->public_id);

        $this->actingAs($requester)->get(route('account.venue-bookings.show', $booking))
            ->assertOk()
            ->assertSee('Новые сообщения')
            ->assertSee('data-booking-unread-count', false);
    }

    private function booking(Venue $venue, User $requester, Actor $actor): VenueBooking
    {
        return VenueBooking::query()->create([
            'public_id' => (string) Str::uuid(), 'flow' => 'rental', 'venue_id' => $venue->id,
            'created_by_actor_id' => $actor->id, 'requester_user_id' => $requester->id,
            'status' => VenueBookingStatusEnum::HELD, 'scope' => VenueBookingScopeEnum::WHOLE,
            'payment_state' => VenueBookingPaymentState::NOT_REQUIRED, 'optimistic_version' => 1,
            'starts_at' => CarbonImmutable::now()->addDay(), 'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
            'hold_expires_at' => CarbonImmutable::now()->addHour(), 'effective_protection_until' => CarbonImmutable::now()->addHour(),
        ]);
    }

    /** @return array{User, Actor, Venue} */
    private function ownedVenue(): array
    {
        [$owner, $actor] = $this->userAndActor();
        $venue = Venue::factory()->create();
        $venue->characteristics()->create(['hoops_count' => 2]);
        $contract = Contract::query()->create(['family' => ContractFamilyEnum::MEMBERSHIP, 'status' => ContractStatusEnum::ACTIVE, 'starts_at' => now()->subMinute(), 'assigner' => UserParticipationRoleAssignerEnum::OTHER]);
        $contract->membership()->create(['scope_type' => ContractMembershipScopeTypeEnum::VENUE, 'scope_id' => $venue->id, 'user_id' => $owner->id, 'access_level' => VenueMembershipAccessLevelEnum::OWNER]);
        $contract->permissions()->createMany(array_map(static fn (VenuePermissionEnum $permission): array => ['permission' => $permission->value], VenueMembershipAccessLevelEnum::OWNER->defaultPermissions()));

        return [$owner, $actor, $venue];
    }

    /** @return array{User, Actor} */
    private function userAndActor(): array
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);

        return [$user, app(CurrentActorResolver::class)->resolve($user, null)];
    }

    private function expectForbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected access denial.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('BOOKING_FORBIDDEN', $exception->errorCode);
        }
    }
}
