<?php

use App\Modules\Notification\Infrastructure\Broadcasting\UserNotificationChannel;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingChannel;
use App\Modules\VenueBooking\Infrastructure\Broadcasting\VenueBookingConversationChannel;
use Illuminate\Support\Facades\Broadcast;

// Reserved reusable private channel for notifications and account-scoped updates.
Broadcast::channel('users.{id}', UserNotificationChannel::class);
Broadcast::channel('venue-bookings.{publicId}', VenueBookingChannel::class);
Broadcast::channel('venue-booking-conversations.{publicId}', VenueBookingConversationChannel::class);
