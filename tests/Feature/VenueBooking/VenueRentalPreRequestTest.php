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
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueRentalPreRequests;
use App\Modules\VenueBooking\Application\UseCases\AcceptVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\ConfirmVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\PublishVenueBookingPolicyHandler;
use App\Modules\VenueBooking\Application\UseCases\SendVenueBookingMessageHandler;
use App\Modules\VenueBooking\Domain\Enums\VenueRentalPreRequestStatus;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingConversation;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class VenueRentalPreRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
        config()->set('features.venue_rental.conversations', true);
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-16 10:00:00', 'Europe/Moscow'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_customer_creates_idempotent_non_occupying_pre_request_without_event_or_payment(): void
    {
        [$owner, $representative, $venue] = $this->venue();
        [$customer, $actor] = $this->person();
        $key = (string) Str::uuid();
        $pre = $this->pre($venue, $actor, $key);
        $this->assertSame($pre->id, $this->pre($venue, $actor, $key)->id);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('venue_bookings', 0);
        $this->assertDatabaseCount('venue_booking_payment_attempts', 0);
        $this->assertDatabaseCount('venue_booking_conversations', 1);
        $this->assertTrue(app(VenueRentalPreRequests::class)->can($owner, $pre, 'offer'));
        $this->assertHttpFailure(409, fn () => app(VenueRentalPreRequests::class)->create($actor, $venue,
            $venue->primaryCourt()->firstOrFail(), $pre->starts_at, 120, VenueBookingScopeEnum::WHOLE, $key));
        $this->assertDatabaseHas('user_notifications', ['user_id' => $owner->id, 'title' => 'Предзаявка на аренду']);
        $notificationCount = \App\Modules\Notification\Domain\Models\UserNotification::query()->count();
        app(VenueRentalPreRequests::class)->notifyMessage($pre->conversation->messages()->firstOrFail()->id);
        $this->assertSame($notificationCount, \App\Modules\Notification\Domain\Models\UserNotification::query()->count());
        $this->actingAs($customer)->get(route('account.venue-pre-requests.show', $pre))->assertOk();
    }

    public function test_owner_proposal_is_an_ordinary_booking_with_provenance_and_requires_customer_consent(): void
    {
        [, $representative, $venue] = $this->venue();
        [$customer, $actor] = $this->person();
        $pre = $this->pre($venue, $actor);
        $thread = $pre->conversation->id;
        $service = app(VenueRentalPreRequests::class);
        $booking = $service->offer($pre, $representative);
        $this->assertSame($booking->id, $service->offer($pre, $representative)->id);
        $this->assertSame('rental', $booking->flow);
        $this->assertSame($customer->id, $booking->requester_user_id);
        $this->assertSame($representative->id, $booking->created_by_representative_id);
        $this->assertSame($representative->id, $booking->created_by_actor_id);
        $this->assertSame($pre->id, $booking->pre_request_id);
        $this->assertNull($booking->event_id);
        $this->assertNull($booking->hold_expires_at);
        $this->assertSame(VenueBookingStatusEnum::REQUESTED, $booking->status);
        try {
            app(AcceptVenueBookingHandler::class)->handle($booking->id, $representative);
            $this->fail('Ordinary accept must not bypass customer consent.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('PROPOSAL_NOT_ACCEPTED', $exception->errorCode);
        }
        $snapshot = $booking->quote_snapshot;
        $accepted = $service->respond($pre, $actor, $booking->id, true);
        $this->assertSame(VenueBookingStatusEnum::HELD, $accepted->status);
        $this->assertNotNull($accepted->proposal_accepted_at);
        $this->assertNotNull($accepted->hold_expires_at);
        $this->assertSame($snapshot, $accepted->quote_snapshot);
        $this->assertSame(VenueRentalPreRequestStatus::RESOLVED, $pre->refresh()->status);
        $this->assertSame($accepted->optimistic_version, $service->respond($pre, $actor, $booking->id, true)->optimistic_version);
        $this->assertSame($thread, $accepted->conversation->id);
        app(ConfirmVenueBookingHandler::class)->handle($booking->id, $representative);
        $cancelled = app(CancelVenueBookingHandler::class)->handle($booking->id, $actor);
        $this->assertSame(VenueBookingStatusEnum::CANCELLED, $cancelled->status);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('venue_booking_payment_attempts', 0);
    }

    public function test_decline_and_new_offer_keep_one_thread_and_old_booking_access(): void
    {
        [, $representative, $venue] = $this->venue();
        [, $actor] = $this->person();
        $pre = $this->pre($venue, $actor);
        $service = app(VenueRentalPreRequests::class);
        $first = $service->offer($pre, $representative);
        $service->respond($pre, $actor, $first->id, false);
        $this->assertSame(VenueBookingStatusEnum::CANCELLED, $first->refresh()->status);
        $this->assertNull($first->effective_protection_until);
        $this->assertSame(VenueRentalPreRequestStatus::ACTIVE, $pre->refresh()->status);
        $second = $service->offer($pre, $representative);
        $this->assertNotSame($first->id, $second->id);
        $message = app(SendVenueBookingMessageHandler::class)->handle($first->id, $actor, (string) Str::uuid(), 'Сохранилась ли переписка?');
        $this->assertSame($pre->conversation->id, $message->conversation_id);
        $first->load('preRequestConversation.messages');
        $this->assertSame($message->conversation_id, $first->preRequestConversation->id);
        $this->assertDatabaseCount('venue_booking_conversations', 1);
        $service->withdraw($pre, $actor);
        $this->assertSame(VenueBookingStatusEnum::CANCELLED, $second->refresh()->status);
        $this->assertSame(VenueRentalPreRequestStatus::WITHDRAWN, $pre->refresh()->status);
    }

    public function test_proposal_uses_current_customer_pricing_and_preserves_it_for_normal_payment_window(): void
    {
        [$owner, $representative, $venue] = $this->venue();
        [$customer, $actor] = $this->person();
        $pre = $this->pre($venue, $actor);
        $publisher = app(PublishVenueBookingPolicyHandler::class);
        $policy = $this->policyData(true);
        $publisher->handle($venue, $owner, $policy);
        $service = app(VenueRentalPreRequests::class);
        $booking = $service->offer($pre, $representative);
        $this->assertSame($customer->id, $booking->quote->quoted_for_user_id);
        $snapshot = $booking->quote_snapshot;
        $amount = (int) data_get($snapshot, 'pricing.amount_minor');
        $this->assertGreaterThan(0, $amount);
        $this->assertDatabaseCount('venue_booking_payment_attempts', 0);
        $publisher->handle($venue, $owner, array_replace($policy, ['whole_price_per_step_minor' => 900]));
        $accepted = $service->respond($pre, $actor, $booking->id, true);
        $this->assertSame($snapshot, $accepted->quote_snapshot);
        $this->assertDatabaseCount('venue_booking_payment_attempts', 0);
        config()->set('features.venue_rental.external_payment', true);
        $attempt = app(\App\Modules\VenueBooking\Application\UseCases\OpenVenueBookingPaymentWindowHandler::class)
            ->handle($booking->id, $representative, 'manual', 'Инструкции по оплате');
        $this->assertSame($amount, $attempt->amount_minor);
        $this->assertSame($snapshot, $booking->refresh()->quote_snapshot);
    }

    public function test_only_customer_can_respond_and_revoked_representative_or_disabled_court_cannot_hold(): void
    {
        [, , $venue] = $this->venue();
        [, $customer] = $this->person();
        [$agent, $representative] = $this->person();
        [, $outsider] = $this->person();
        $this->grant($agent, $venue, [VenuePermissionEnum::PROPOSE_EVENT_BOOKING]);
        $pre = $this->pre($venue, $customer);
        $service = app(VenueRentalPreRequests::class);
        $booking = $service->offer($pre, $representative);
        $this->assertHttpFailure(403, fn () => $service->respond($pre, $outsider, $booking->id, true));
        $this->assertHttpFailure(404, fn () => $service->respond($pre, $customer, $booking->id + 1000, true));
        $venue->primaryCourt()->firstOrFail()->update(['rental_enabled' => false]);
        $this->assertHttpFailure(409, fn () => $service->respond($pre, $customer, $booking->id, true));
        $this->assertNull($booking->refresh()->proposal_accepted_at);
        $venue->primaryCourt()->firstOrFail()->update(['rental_enabled' => true]);
        $agent->update(['status' => UserStatusEnum::BLOCKED]);
        $this->assertHttpFailure(403, fn () => $service->respond($pre, $customer, $booking->id, true));
        $this->assertNull($booking->refresh()->hold_expires_at);
    }

    public function test_read_write_offer_grants_are_independent_and_scoped_to_the_court(): void
    {
        [, , $venue] = $this->venue();
        [, $actor] = $this->person();
        $pre = $this->pre($venue, $actor);
        [$reader, $readerActor] = $this->person();
        [$writer, $writerActor] = $this->person();
        [$agent, $agentActor] = $this->person();
        [$outsider] = $this->person();
        $this->grant($reader, $venue, [VenuePermissionEnum::VIEW_EVENT_INTERESTS]);
        $this->grant($writer, $venue, [VenuePermissionEnum::VIEW_BOOKING_REQUESTS]);
        $this->grant($agent, $venue, [VenuePermissionEnum::PROPOSE_EVENT_BOOKING], court: true);
        $service = app(VenueRentalPreRequests::class);
        $this->assertTrue($service->can($reader, $pre));
        $this->assertFalse($service->can($reader, $pre, 'write'));
        $this->assertFalse($service->can($reader, $pre, 'offer'));
        $this->assertTrue($service->can($writer, $pre, 'write'));
        $this->assertFalse($service->can($writer, $pre, 'offer'));
        $this->assertTrue($service->can($agent, $pre, 'offer'));
        $this->assertFalse($service->can($outsider, $pre));
        $this->assertHttpFailure(403, fn () => $service->send($pre, $readerActor, (string) Str::uuid(), 'Нет прав'));
        $this->assertHttpFailure(403, fn () => $service->offer($pre, $writerActor));
        $message = $service->send($pre, $writerActor, (string) Str::uuid(), 'Ответ площадки');
        $this->assertNotNull($message->id);
        $booking = $service->offer($pre, $agentActor);
        $this->assertSame($agentActor->id, $booking->created_by_representative_id);
        $superadmin = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => UserSystemRoleEnum::SUPERADMIN]);
        $this->assertTrue($service->can($superadmin, $pre, 'offer'));
        [, , $otherVenue] = $this->venue();
        $this->assertFalse($service->can($agent, $this->pre($otherVenue, $actor), 'offer'));
    }

    public function test_expired_proposal_cannot_be_accepted_and_stale_expiry_does_not_cancel_accepted_booking(): void
    {
        [, $representative, $venue] = $this->venue();
        [, $actor] = $this->person();
        $pre = $this->pre($venue, $actor);
        $service = app(VenueRentalPreRequests::class);
        $booking = $service->offer($pre, $representative);
        Carbon::setTestNow($booking->proposal_expires_at);
        $this->assertHttpFailure(409, fn () => $service->respond($pre, $actor, $booking->id, true));
        $service->expireProposal($booking);
        $service->expireProposal($booking);
        $this->assertSame(VenueBookingStatusEnum::CANCELLED, $booking->refresh()->status);
        $new = $service->offer($pre, $representative);
        $service->respond($pre, $actor, $new->id, true);
        Carbon::setTestNow($new->proposal_expires_at->addMinute());
        $service->expireProposal($new);
        $this->assertSame(VenueBookingStatusEnum::HELD, $new->refresh()->status);
    }

    public function test_competing_customer_acceptance_rechecks_availability_and_rolls_back_consent(): void
    {
        [, $representative, $venue] = $this->venue();
        [, $firstActor] = $this->person();
        [, $secondActor] = $this->person();
        $first = $this->pre($venue, $firstActor);
        $second = $this->pre($venue, $secondActor);
        $service = app(VenueRentalPreRequests::class);
        $one = $service->offer($first, $representative);
        $two = $service->offer($second, $representative);
        $service->respond($first, $firstActor, $one->id, true);
        try {
            $service->respond($second, $secondActor, $two->id, true);
            $this->fail('The slot must be rechecked under the venue mutex.');
        } catch (VenueBookingTransitionException $exception) {
            $this->assertSame('BOOKING_CONFLICT', $exception->errorCode);
        }
        $this->assertNull($two->refresh()->proposal_accepted_at);
        $this->assertSame(VenueRentalPreRequestStatus::ACTIVE, $second->refresh()->status);
    }

    public function test_attachments_remain_private_and_court_mismatch_and_unconfirmed_customer_are_rejected(): void
    {
        [, , $venue] = $this->venue();
        [$customer, $actor] = $this->person();
        [$outsider] = $this->person();
        $pre = $this->pre($venue, $actor);
        Storage::fake('local');
        $key = (string) Str::uuid();
        $message = app(VenueRentalPreRequests::class)->send($pre, $actor, $key, 'Файл', UploadedFile::fake()->createWithContent('note.txt', 'private'));
        $this->assertSame($message->id, app(VenueRentalPreRequests::class)->send($pre, $actor, $key, 'Повтор')->id);
        $this->actingAs($customer)->get(route('account.venue-pre-requests.download', [$pre, $message]))->assertOk();
        $this->actingAs($outsider)->get(route('account.venue-pre-requests.download', [$pre, $message]))->assertForbidden();
        $this->actingAs($outsider)->get(route('account.venue-pre-requests.show', $pre))->assertForbidden();
        [, , $otherVenue] = $this->venue();
        $this->assertHttpFailure(404, fn () => app(VenueRentalPreRequests::class)->create($actor, $venue,
            $otherVenue->primaryCourt()->firstOrFail(), $pre->starts_at, 60, VenueBookingScopeEnum::WHOLE, (string) Str::uuid()));
        $customer->update(['status' => UserStatusEnum::UNCONFIRMED]);
        $actor->unsetRelation('user');
        $this->assertHttpFailure(403, fn () => $this->pre($venue, $actor));
    }

    private function pre(Venue $venue, Actor $actor, ?string $key = null): VenueRentalPreRequest
    {
        return app(VenueRentalPreRequests::class)->create($actor, $venue, $venue->primaryCourt()->firstOrFail(),
            CarbonImmutable::parse('2026-11-18 12:00:00', 'Europe/Moscow'), 60, VenueBookingScopeEnum::WHOLE, $key ?? (string) Str::uuid());
    }

    private function person(): array
    {
        $user = User::factory()->withVerifiedContact()->create(['status' => UserStatusEnum::CONFIRMED]);
        return [$user, app(CurrentActorResolver::class)->resolve($user, null)];
    }

    private function venue(): array
    {
        [$owner, $actor] = $this->person();
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED, 'operational_status' => VenueOperationalStatusEnum::ACTIVE]);
        $venue->characteristics()->create(['hoops_count' => 2]);
        $venue->primaryCourt()->firstOrFail()->update(['hoops_count' => 2, 'supports_halves' => true, 'allows_whole' => true, 'allows_halves' => true]);
        $venue->schedule()->create(['timezone' => 'Europe/Moscow']);
        $this->grant($owner, $venue, VenueMembershipAccessLevelEnum::OWNER->defaultPermissions(), owner: true);
        app(PublishVenueBookingPolicyHandler::class)->handle($venue, $owner, $this->policyData(false));
        return [$owner, $actor, $venue];
    }

    private function policyData(bool $paid): array
    {
        return [
            'is_enabled' => true, 'allows_whole' => true, 'allows_halves' => true,
            'minimum_duration_minutes' => 60, 'maximum_duration_minutes' => 180, 'time_step_minutes' => 30,
            'minimum_lead_time_minutes' => 120, 'maximum_advance_days' => 90, 'currency' => 'RUB',
            'whole_price_per_step_minor' => $paid ? 500 : 0, 'half_price_per_step_minor' => $paid ? 300 : 0, 'hold_duration_minutes' => 30,
            'requires_payment' => $paid, 'payment_window_minutes' => $paid ? 30 : null, 'quote_validity_minutes' => 15,
            'cancellation_before_minutes' => 1440,
        ];
    }

    private function grant(User $user, Venue $venue, array $permissions, bool $court = false, bool $owner = false): void
    {
        $contract = Contract::query()->create(['family' => ContractFamilyEnum::MEMBERSHIP,
            'status' => ContractStatusEnum::ACTIVE, 'starts_at' => now()->subMinute(), 'assigner' => UserParticipationRoleAssignerEnum::OTHER]);
        $contract->membership()->create(['scope_type' => $court ? ContractMembershipScopeTypeEnum::VENUE_COURT : ContractMembershipScopeTypeEnum::VENUE,
            'scope_id' => $court ? $venue->primaryCourt()->firstOrFail()->id : $venue->id, 'user_id' => $user->id,
            'access_level' => $owner ? VenueMembershipAccessLevelEnum::OWNER : VenueMembershipAccessLevelEnum::AGENT]);
        $contract->permissions()->createMany(array_map(fn ($permission) => ['permission' => $permission->value], $permissions));
    }

    private function assertHttpFailure(int $status, callable $callback): void
    {
        try { $callback(); $this->fail('Expected denied operation.'); }
        catch (HttpExceptionInterface $exception) { $this->assertSame($status, $exception->getStatusCode()); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { $this->assertSame(404, $status); }
    }
}
