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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class AttachVenueBookingConversationFileHandler
{
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'application/pdf', 'text/plain'];

    public function __construct(private LockedVenueBooking $lockedBooking, private VenueBookingAuthorization $authorization, private FeatureFlags $features) {}

    public function handle(int $bookingId, Actor $actor, string $clientId, string $contents, string $name, string $mime, int $size, ?string $body = null): VenueBookingMessage
    {
        $this->features->ensureEnabled(VenueRentalFeature::CONVERSATIONS);
        if ($size < 1 || $size > 10 * 1024 * 1024 || ! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new VenueBookingTransitionException('Недопустимый тип или размер вложения.', 'INVALID_CONVERSATION_ATTACHMENT');
        }
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf', 'text/plain' => 'txt',
        };
        $path = 'venue-booking-conversations/'.Str::uuid().'.'.$extension;
        $stored = false;

        try {
            return $this->lockedBooking->run($bookingId, function (VenueBooking $booking, $venue) use ($actor, $clientId, $contents, $name, $mime, $size, $body, $path, &$stored): VenueBookingMessage {
                $this->authorization->assertCanView($actor, $booking, $venue);
                if ($actor->user_id !== null && $actor->user_id !== $booking->requester_user_id && ! $actor->user?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN)) {
                    $booking->parties()->firstOrCreate(['user_id' => $actor->user_id, 'role' => VenueBookingPartyRole::VENUE_REPRESENTATIVE]);
                }
                $conversation = VenueBookingConversation::query()->firstOrCreate(['venue_booking_id' => $booking->id], ['public_id' => (string) Str::uuid()]);
                $existing = VenueBookingMessage::query()->where('conversation_id', $conversation->id)->where('author_actor_id', $actor->id)->where('client_id', $clientId)->first();
                if ($existing !== null) {
                    return $existing;
                }
                if (! Storage::disk('local')->put($path, $contents)) {
                    throw new VenueBookingTransitionException('Не удалось сохранить вложение.', 'ATTACHMENT_STORAGE_FAILED');
                }
                $stored = true;
                $message = VenueBookingMessage::query()->create([
                    'public_id' => (string) Str::uuid(), 'conversation_id' => $conversation->id,
                    'author_actor_id' => $actor->id, 'client_id' => $clientId, 'type' => 'attachment',
                    'body' => $body === null ? null : trim($body), 'attachment_disk' => 'local',
                    'attachment_path' => $path, 'attachment_name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255),
                    'attachment_mime' => $mime, 'attachment_size' => $size,
                ]);
                DB::afterCommit(static function () use ($booking, $conversation, $message): void {
                    event(new VenueBookingMessageSent($booking->id, $conversation->id, $message->id));
                    broadcast(new VenueBookingMessageSentBroadcast($booking->public_id, $conversation->public_id, $message->public_id))->toOthers();
                });

                return $message->fresh('authorActor.user');
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }
}
