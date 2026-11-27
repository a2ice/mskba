<?php

namespace App\Modules\VenueBooking\Application\Services;

use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\Actor;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Application\DTO\CreateUserNotificationDTO;
use App\Modules\Notification\Application\UseCases\CreateUserNotificationHandler;
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use App\Modules\Venue\Application\Services\VenueCommercialAccess;
use App\Modules\Venue\Application\Services\VenueMembershipAccess;
use App\Modules\Venue\Application\Services\VenueUserRestrictionService;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Enums\VenueUserRestrictionTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Application\UseCases\AcceptVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\CancelVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\QuoteVenueBookingHandler;
use App\Modules\VenueBooking\Application\UseCases\RequestVenueBookingHandler;
use App\Modules\VenueBooking\Domain\Enums\VenueRentalPreRequestStatus;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Non-occupying demand and a persistent thread; proposals use the ordinary booking aggregate. */
final readonly class VenueRentalPreRequests
{
    public function __construct(private VenueCommercialAccess $commercial, private VenueMembershipAccess $memberships) {}

    public function can(User $user, VenueRentalPreRequest $pre, string $action = 'view'): bool
    {
        $user = $user->canonical();
        if (! $user->isConfirmed() || $user->isBlocked() || $user->trashed()) {
            return false;
        }
        if ($user->id === $pre->requester_user_id && $action !== 'offer') {
            return true;
        }
        if ($action === 'view' && app(VenueBookingBackofficeAccess::class)->canView($user)) {
            return true;
        }
        $court = $pre->court;
        if ($court === null || $pre->venue === null || $court->venue_id !== $pre->venue_id) {
            return false;
        }
        // Existing owner/admin contracts keep their management rights. Staff and
        // selected agents get no implicit grant; delegated contracts are checked per court.
        if ($this->memberships->hasActiveVenueManagementRole($user, $pre->venue)) {
            return true;
        }
        $permission = match ($action) {
            'offer' => VenuePermissionEnum::PROPOSE_EVENT_BOOKING,
            'write' => VenuePermissionEnum::VIEW_BOOKING_REQUESTS,
            default => VenuePermissionEnum::VIEW_EVENT_INTERESTS,
        };

        return $this->commercial->allowsForCourt($user, $court, $permission)
            || ($action === 'view' && ($this->commercial->allowsForCourt($user, $court, VenuePermissionEnum::VIEW_BOOKING_REQUESTS)
                || $this->commercial->allowsForCourt($user, $court, VenuePermissionEnum::PROPOSE_EVENT_BOOKING)));
    }

    public function assertCan(Actor $actor, VenueRentalPreRequest $pre, string $action = 'view'): void
    {
        abort_unless($actor->user !== null && $this->can($actor->user, $pre, $action), 403);
    }

    public function create(Actor $actor, Venue $venue, VenueCourt $court, CarbonImmutable $start, int $minutes,
        VenueBookingScopeEnum $scope, string $key, ?string $note = null): VenueRentalPreRequest
    {
        app(FeatureFlags::class)->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        $user = $actor->user?->canonical();
        abort_unless($user !== null && $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed(), 403);
        abort_unless($venue->isPubliclyVisible() && $court->venue_id === $venue->id && ! $court->trashed(), 404);
        $note = trim((string) $note) ?: null;
        $hash = hash('sha256', json_encode([$venue->id, $court->id, $start->utc()->toIso8601String(), $minutes, $scope->value, $note], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $user, $venue, $court, $start, $minutes, $scope, $key, $note, $hash): VenueRentalPreRequest {
            // Serialize all keys of one customer, including retries against a different venue.
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed(), 403);
            $existing = VenueRentalPreRequest::query()->where('requester_user_id', $user->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Ключ уже использован с другими параметрами.');
                return $existing;
            }
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            abort_unless($venue->isPubliclyVisible(), 404);
            $court = VenueCourt::query()->where('venue_id', $venue->id)->lockForUpdate()->findOrFail($court->id);
            if (app(VenueUserRestrictionService::class)->active($venue, $user, VenueUserRestrictionTypeEnum::RENTAL_REQUEST) !== null) {
                abort(403, 'Подача заявок на эту площадку ограничена.');
            }
            // Reuse canonical policy, court, time and scope validation. A quote never holds inventory.
            $quote = app(QuoteVenueBookingHandler::class)->handle($venue, $start, $minutes, $scope, $user, $court);
            $pre = VenueRentalPreRequest::query()->create([
                'public_id' => (string) Str::uuid(), 'venue_id' => $venue->id, 'venue_court_id' => $court->id,
                'requester_user_id' => $user->id, 'created_by_actor_id' => $actor->id,
                'idempotency_key' => $key, 'payload_hash' => $hash, 'scope' => $scope,
                'starts_at' => $quote->startsAt, 'ends_at' => $quote->endsAt, 'note' => $note,
                'status' => VenueRentalPreRequestStatus::ACTIVE,
            ]);
            $pre->conversation()->create(['public_id' => (string) Str::uuid()]);
            $this->message($pre, $actor, 'Запрос на аренду отправлен. Время ещё не забронировано.'.($note ? "\n".$note : ''), 'system');
            return $pre;
        });
    }

    public function offer(VenueRentalPreRequest $reference, Actor $actor): VenueBooking
    {
        return $this->locked($reference, function (VenueRentalPreRequest $pre) use ($actor): VenueBooking {
            $this->assertCan($actor, $pre, 'offer');
            abort_unless($pre->status === VenueRentalPreRequestStatus::ACTIVE, 409, 'Запрос на аренду закрыт.');
            $existing = $pre->bookings()->whereIn('status', [VenueBookingStatusEnum::REQUESTED->value, VenueBookingStatusEnum::HELD->value, VenueBookingStatusEnum::CONFIRMED->value])->latest('id')->first();
            if ($existing !== null) {
                if ($existing->status !== VenueBookingStatusEnum::REQUESTED || $existing->proposal_expires_at->isFuture()) {
                    return $existing;
                }
                app(CancelVenueBookingHandler::class)->handle($existing->id, app(CurrentActorResolver::class)->resolve($pre->requester, null), 'Срок предложения истёк.');
            }
            $customer = app(CurrentActorResolver::class)->resolve($pre->requester, null);
            $quote = app(QuoteVenueBookingHandler::class)->handle($pre->venue, $pre->starts_at,
                (int) $pre->starts_at->diffInMinutes($pre->ends_at), $pre->scope, $pre->requester, $pre->court);
            $booking = app(RequestVenueBookingHandler::class)->handle($customer, $quote->publicId,
                preRequest: $pre, representative: $actor);
            // Keep all earlier messages and attachments in the same thread.
            $pre->conversation->forceFill(['venue_booking_id' => $booking->id])->save();
            $this->message($pre, $actor, 'Предложение аренды создано. Подтверди или отклони его до '.$booking->proposal_expires_at->toIso8601String().'.', 'system');
            return $booking;
        });
    }

    public function respond(VenueRentalPreRequest $reference, Actor $actor, int $bookingId, bool $accept): VenueBooking
    {
        return $this->locked($reference, function (VenueRentalPreRequest $pre) use ($actor, $bookingId, $accept): VenueBooking {
            abort_unless($actor->user?->canonical()->id === $pre->requester_user_id, 403);
            $this->assertCan($actor, $pre);
            $booking = $pre->bookings()->lockForUpdate()->findOrFail($bookingId);
            if ($accept && $booking->proposal_accepted_at !== null) {
                return $booking;
            }
            if (! $accept && $booking->status === VenueBookingStatusEnum::CANCELLED) {
                return $booking;
            }
            abort_unless($pre->status === VenueRentalPreRequestStatus::ACTIVE && $booking->status === VenueBookingStatusEnum::REQUESTED, 409, 'Предложение уже обработано.');
            if (! $accept) {
                $booking = app(CancelVenueBookingHandler::class)->handle($booking->id, $actor, 'Клиент отклонил предложение.');
                $this->message($pre, $actor, 'Предложение отклонено. Запрос на аренду остаётся активным.', 'system');
                return $booking;
            }
            abort_unless($booking->proposal_expires_at->isFuture(), 409, 'Срок предложения истёк. Запроси новое предложение.');
            $representative = Actor::query()->findOrFail($booking->created_by_representative_id);
            $this->assertCan($representative, $pre, 'offer');
            abort_if(app(VenueUserRestrictionService::class)->active($pre->venue, $pre->requester, VenueUserRestrictionTypeEnum::RENTAL_REQUEST) !== null,
                403, 'Подача заявок на эту площадку ограничена.');
            abort_unless($pre->court !== null && ! $pre->court->trashed()
                && $pre->court->venue_id === $pre->venue_id && $pre->court->allowsScope($pre->scope === VenueBookingScopeEnum::WHOLE), 409, 'Зал больше недоступен.');
            $effective = app(\App\Modules\Venue\Application\Services\EffectiveCourtPolicy::class)->resolve($pre->venue, $pre->court, $booking->policyVersion);
            abort_unless($effective['rental_enabled'], 409, 'Аренда зала отключена.');
            try {
                app(\App\Modules\Event\Application\Services\VenueEventAvailability::class)->assertAvailable(
                    $pre->venue, $pre->starts_at->setTimezone((string) config('app.timezone')),
                    $pre->ends_at->setTimezone((string) config('app.timezone')), scope: $pre->scope,
                    court: $pre->court, allowEndAtMidnight: true,
                );
            } catch (\InvalidArgumentException $exception) {
                throw new VenueBookingTransitionException($exception->getMessage(), 'BOOKING_CONFLICT');
            }
            // Consent and ordinary hold are atomic. The hold handler rechecks conflicts.
            $booking->forceFill(['proposal_accepted_at' => now()])->save();
            $booking = app(AcceptVenueBookingHandler::class)->handle($booking->id, $representative);
            $pre->forceFill(['status' => VenueRentalPreRequestStatus::RESOLVED, 'resolved_at' => now()])->save();
            $this->message($pre, $actor, 'Предложение принято. Продолжи обычное оформление аренды.', 'system');
            return $booking;
        });
    }

    public function withdraw(VenueRentalPreRequest $reference, Actor $actor): void
    {
        $this->locked($reference, function (VenueRentalPreRequest $pre) use ($actor): void {
            abort_unless($actor->user?->canonical()->id === $pre->requester_user_id, 403);
            if ($pre->status === VenueRentalPreRequestStatus::WITHDRAWN) { return; }
            abort_unless($pre->status === VenueRentalPreRequestStatus::ACTIVE, 409, 'Используй отмену связанной брони.');
            foreach ($pre->bookings()->where('status', VenueBookingStatusEnum::REQUESTED->value)->get() as $booking) {
                app(CancelVenueBookingHandler::class)->handle($booking->id, $actor, 'Запрос на аренду отозван.');
            }
            $pre->forceFill(['status' => VenueRentalPreRequestStatus::WITHDRAWN, 'withdrawn_at' => now()])->save();
            $this->message($pre, $actor, 'Запрос на аренду отозван.', 'system');
        });
    }

    public function send(VenueRentalPreRequest $reference, Actor $actor, string $clientId, string $body, ?\Illuminate\Http\UploadedFile $file = null): VenueBookingMessage
    {
        app(FeatureFlags::class)->ensureEnabled(VenueRentalFeature::CONVERSATIONS);
        $path = null;
        try {
            return $this->locked($reference, function (VenueRentalPreRequest $pre) use ($actor, $clientId, $body, $file, &$path): VenueBookingMessage {
                $this->assertCan($actor, $pre, 'write');
                $existing = $pre->conversation->messages()->where('author_actor_id', $actor->id)->where('client_id', $clientId)->first();
                if ($existing !== null) { return $existing; }
                abort_if(trim($body) === '' && $file === null, 422, 'Сообщение не может быть пустым.');
                $attachment = [];
                if ($file !== null) {
                    abort_unless($file->getSize() > 0 && $file->getSize() <= 10 * 1024 * 1024
                        && in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'application/pdf', 'text/plain'], true), 422);
                    $path = $file->store('venue-booking-conversations', 'local');
                    abort_unless($path, 503, 'Не удалось сохранить вложение.');
                    $attachment = ['attachment_disk' => 'local', 'attachment_path' => $path,
                        'attachment_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                        'attachment_mime' => $file->getMimeType(), 'attachment_size' => $file->getSize()];
                }
                return $this->message($pre, $actor, trim($body), $file === null ? 'text' : 'attachment', $clientId, $attachment);
            });
        } catch (\Throwable $exception) {
            if ($path) { \Illuminate\Support\Facades\Storage::disk('local')->delete($path); }
            throw $exception;
        }
    }

    private function locked(VenueRentalPreRequest $reference, \Closure $callback): mixed
    {
        app(FeatureFlags::class)->ensureEnabled(VenueRentalFeature::RENTAL_FLOW);
        return DB::transaction(function () use ($reference, $callback): mixed {
            Venue::query()->lockForUpdate()->findOrFail($reference->venue_id);
            return $callback(VenueRentalPreRequest::query()->lockForUpdate()->findOrFail($reference->id));
        });
    }

    public function expireProposal(VenueBooking $reference): void
    {
        if ($reference->pre_request_id === null) { return; }
        $this->locked($reference->preRequest, function (VenueRentalPreRequest $pre) use ($reference): void {
            $booking = $pre->bookings()->lockForUpdate()->findOrFail($reference->id);
            if ($booking->status !== VenueBookingStatusEnum::REQUESTED || $booking->proposal_accepted_at !== null
                || $booking->proposal_expires_at === null || $booking->proposal_expires_at->isFuture()) { return; }
            app(\App\Modules\VenueBooking\Domain\Services\VenueBookingLifecycle::class)->cancel(
                $booking, app(CurrentActorResolver::class)->system(), CarbonImmutable::now(), 'Срок предложения истёк.',
            );
            app(VenueBookingOutbox::class)->record($booking->id, \App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled::class);
            $this->message($pre, app(CurrentActorResolver::class)->system(), 'Срок предложения истёк. Можно запросить новое предложение.', 'system');
        });
    }

    public function message(VenueRentalPreRequest $pre, Actor $actor, string $body, string $type = 'system', ?string $clientId = null, array $attachment = []): VenueBookingMessage
    {
        $message = $pre->conversation->messages()->firstOrCreate([
            'author_actor_id' => $actor->id, 'client_id' => $clientId ?? (string) Str::uuid(),
        ], ['public_id' => (string) Str::uuid(), 'body' => $body, 'type' => $type, ...$attachment]);
        if ($message->wasRecentlyCreated) {
            DB::afterCommit(static function () use ($message): void {
                event(new \App\Modules\VenueBooking\Domain\Events\VenueRentalPreRequestMessageSent($message->id));
                broadcast(new \App\Modules\VenueBooking\Infrastructure\Broadcasting\EventVenueInterestMessageSentBroadcast(
                    $message->conversation->public_id, $message->public_id,
                ));
            });
        }
        return $message;
    }

    public function notifyMessage(int $messageId): void
    {
        $message = VenueBookingMessage::query()->with(['conversation.preRequest', 'authorActor'])->findOrFail($messageId);
        $pre = $message->conversation->preRequest;
        if ($pre === null) { return; }
        $actor = $message->authorActor;
        $ids = [$pre->requester_user_id, ...$this->memberships->activeVenueManagementUserIds($pre->venue)];
        foreach ([VenuePermissionEnum::VIEW_EVENT_INTERESTS, VenuePermissionEnum::VIEW_BOOKING_REQUESTS, VenuePermissionEnum::PROPOSE_EVENT_BOOKING] as $permission) {
            $ids = [...$ids, ...$this->memberships->allowedUserIdsForCourt($pre->court, $permission)];
        }
        if ($owner = $this->memberships->activeOwner($pre->venue)) { $ids[] = $owner->id; }
        foreach (User::query()->whereIn('id', array_unique($ids))->get() as $user) {
            if ($user->id !== $actor->user_id && $this->can($user, $pre)) {
                DB::transaction(function () use ($user, $pre, $message): void {
                    $inserted = DB::table('venue_booking_message_deliveries')->insertOrIgnore([
                        'message_id' => $message->id, 'recipient_user_id' => $user->id,
                        'channel' => 'in_app', 'status' => 'processing', 'attempts' => 1,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (! $inserted) { return; }
                    app(CreateUserNotificationHandler::class)->handle(new CreateUserNotificationDTO(
                        userId: $user->id, type: UserNotificationTypeEnum::MESSAGE,
                        title: 'Запрос на аренду', body: 'Обновление переписки по площадке «'.$pre->venue->name.'».',
                        actionUrl: route('account.notifications.threads.pre-request', $pre, false), actionText: 'Переписка',
                        payload: ['source' => 'venue.rental.pre-request', 'delivery_category' => 'request', 'pre_request_id' => $pre->id, 'venue_id' => $pre->venue_id],
                    ));
                    DB::table('venue_booking_message_deliveries')->where('message_id', $message->id)
                        ->where('recipient_user_id', $user->id)->where('channel', 'in_app')
                        ->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()]);
                });
            }
        }
    }
}
