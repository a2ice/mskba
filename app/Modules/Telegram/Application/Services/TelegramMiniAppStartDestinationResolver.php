<?php

namespace App\Modules\Telegram\Application\Services;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Coordination\Domain\Models\CoordinationSession;
use App\Modules\Coordination\Domain\Models\VenueRentalCoordination;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\UserNotification;
use App\Support\Features\FeatureFlags;
use App\Support\Features\VenueRentalFeature;

final readonly class TelegramMiniAppStartDestinationResolver
{
    public function __construct(private FeatureFlags $features) {}

    public function resolve(?string $startParam, ?int $userId = null): ?string
    {
        if ($startParam === null) {
            return null;
        }

        if (preg_match('/\Aevent_(\d+)\z/D', $startParam, $matches) === 1) {
            $event = Event::query()->find((int) $matches[1]);

            return $event === null
                ? null
                : route('events.show', $event->routeIdentifier(), false);
        }

        if (preg_match('/\Acoordination_(\d+)\z/D', $startParam, $matches) === 1) {
            $coordination = CoordinationSession::query()->find((int) $matches[1]);

            return $coordination === null
                ? null
                : route('coordination.show', $coordination, false);
        }

        if ($this->features->enabled(VenueRentalFeature::COORDINATION)
            && preg_match('/\Arental_coordination_([0-9a-f-]{36})\z/Di', $startParam, $matches) === 1) {
            $coordination = VenueRentalCoordination::query()
                ->where('public_id', $matches[1])
                ->first();
            if ($coordination === null) {
                return null;
            }
            if ($coordination->visibility === 'private') {
                $user = $userId === null ? null : User::query()->find($userId)?->canonical();
                $allowed = $user !== null && ($coordination->organizer_user_id === $user->id
                    || $coordination->participants()
                        ->where('user_id', $user->id)
                        ->whereNull('left_at')
                        ->exists());
                if (! $allowed) {
                    return null;
                }
            }

            return route('venue-rental-coordinations.show', $coordination, false);
        }

        if (preg_match('/\Acontent_(\d+)\z/D', $startParam, $matches) === 1) {
            $content = ContentItem::query()
                ->publishedInFeed()
                ->find((int) $matches[1]);

            return $content === null
                ? null
                : route('news.show', $content->alias, false);
        }

        if ($userId !== null && preg_match('/\Anotification_(\d+)\z/D', $startParam, $matches) === 1) {
            $user = User::query()->find($userId);
            if ($user === null) {
                return null;
            }

            $notification = UserNotification::query()
                ->whereKey((int) $matches[1])
                ->whereIn('user_id', $user->canonical()->identityIds())
                ->first();

            if ($notification === null) {
                return null;
            }

            return is_string($notification->action_url)
                && str_starts_with($notification->action_url, '/')
                    ? $notification->action_url
                    : route('account.notifications', [], false);
        }

        return null;
    }
}
