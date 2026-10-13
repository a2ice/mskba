<?php

use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationChannel;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingChannel;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingConversationChannel;
use Illuminate\Support\Facades\Broadcast;

// Reserved reusable private channel for notifications and account-scoped updates.
Broadcast::channel('users.{id}', UserNotificationChannel::class);
Broadcast::channel('venue-bookings.{publicId}', VenueBookingChannel::class);
Broadcast::channel('venue-booking-conversations.{publicId}', VenueBookingConversationChannel::class);

// Server-authorized subscriptions; events themselves never include private bodies.
Broadcast::channel('request-threads.{id}', [\App\Modules\Notification\Infrastructure\Broadcasting\ConversationChannel::class, 'profile']);
Broadcast::channel('direct-conversations.{id}', [\App\Modules\Notification\Infrastructure\Broadcasting\ConversationChannel::class, 'direct']);
