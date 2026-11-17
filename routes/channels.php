<?php

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Infrastructure\Broadcasting\ConversationChannel;
use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationChannel;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingChannel;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingConversationChannel;
use Illuminate\Support\Facades\Broadcast;

// Reserved reusable private channel for notifications and account-scoped updates.
Broadcast::channel('users.{id}', UserNotificationChannel::class);
Broadcast::channel('venue-bookings.{publicId}', VenueBookingChannel::class);
Broadcast::channel('venue-booking-conversations.{publicId}', VenueBookingConversationChannel::class);

// Server-authorized subscriptions; events themselves never include private bodies.
Broadcast::channel('request-threads.{id}', static fn (User $user, string $id): bool => app(ConversationChannel::class)->profile($user, $id));
Broadcast::channel('direct-conversations.{id}', static fn (User $user, string $id): bool => app(ConversationChannel::class)->direct($user, $id));

Broadcast::channel('support-tickets.{id}', static fn (User $user, string $id): bool => app(ConversationChannel::class)->support($user, $id));
Broadcast::channel('operator-conversations.{id}', static fn (User $user, string $id): bool => app(ConversationChannel::class)->operator($user, $id));
// Page-change events contain no URLs. Only the assigned operator may subscribe.
Broadcast::channel('operator-presence.{userId}', \App\Modules\Portal\Infrastructure\Broadcasting\OperatorPresenceChannel::class);
