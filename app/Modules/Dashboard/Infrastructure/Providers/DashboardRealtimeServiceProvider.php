<?php

namespace App\Modules\Dashboard\Infrastructure\Providers;

use App\Modules\Dashboard\Infrastructure\Listeners\InvalidateChangedEventWidgets;
use App\Modules\Dashboard\Infrastructure\Observers\DashboardPersonalDataObserver;
use App\Modules\Dashboard\Infrastructure\Observers\DashboardOperationalDataObserver;
use App\Modules\Dashboard\Infrastructure\Observers\DashboardAccessObserver;
use App\Modules\Contract\Domain\Models\Contract;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Contract\Domain\Models\ContractPermission;
use App\Modules\Identity\Domain\Models\UserOperationalPermission;
use App\Modules\Invitation\Domain\Models\AccountInvitation;
use App\Modules\Event\Domain\Events\EventChanged;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Event\Domain\Models\GameAdmission;
use App\Modules\Finance\Domain\Models\Wallet;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\PlayInterest\Domain\Models\VenueSubscription;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class DashboardRealtimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([Wallet::class, PlayIntent::class, VenueSubscription::class,
            EventParticipant::class, GameAdmission::class, VenueBooking::class,
            VenueRentalPreRequest::class] as $model) {
            $model::observe(DashboardPersonalDataObserver::class);
        }

        foreach ([VenueBooking::class, VenueRentalPreRequest::class, AccountInvitation::class] as $model) {
            $model::observe(DashboardOperationalDataObserver::class);
        }
        foreach ([Contract::class, ContractMembership::class, ContractPermission::class, UserOperationalPermission::class] as $model) {
            $model::observe(DashboardAccessObserver::class);
        }

        Event::listen(EventChanged::class, InvalidateChangedEventWidgets::class);
    }
}
