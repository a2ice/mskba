<?php

namespace App\Modules\Coordination\Infrastructure\Providers;

use App\Modules\Coordination\Domain\Events\VenueBookingAttendanceRoundOpened;
use App\Modules\Coordination\Domain\Events\VenueRentalCoordinationJoined;
use App\Modules\Coordination\Infrastructure\Listeners\CloseAttendanceRoundAfterBookingEnds;
use App\Modules\Coordination\Infrastructure\Listeners\NotifyOrganizerAboutVenueRentalCoordinationJoin;
use App\Modules\Coordination\Infrastructure\Listeners\NotifyVenueBookingAttendanceInvitees;
use App\Modules\Coordination\Presentation\Http\Controllers\CoordinationManagementController;
use App\Modules\VenueBooking\Domain\Events\VenueBookingCancelled;
use App\Modules\VenueBooking\Domain\Events\VenueBookingConfirmed;
use App\Modules\VenueBooking\Domain\Events\VenueBookingExpired;
use App\Modules\VenueBooking\Domain\Events\VenueBookingRejected;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class CoordinationInterfaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(VenueRentalCoordinationJoined::class, NotifyOrganizerAboutVenueRentalCoordinationJoin::class);
        Event::listen(VenueBookingAttendanceRoundOpened::class, NotifyVenueBookingAttendanceInvitees::class);
        Event::listen(VenueBookingConfirmed::class, CloseAttendanceRoundAfterBookingEnds::class);
        Event::listen(VenueBookingCancelled::class, CloseAttendanceRoundAfterBookingEnds::class);
        Event::listen(VenueBookingExpired::class, CloseAttendanceRoundAfterBookingEnds::class);
        Event::listen(VenueBookingRejected::class, CloseAttendanceRoundAfterBookingEnds::class);

        Route::middleware(['web', 'auth'])
            ->get('/coordination/{coordination}/management', CoordinationManagementController::class)
            ->name('coordination.management')
            ->defaults('breadcrumb', 'Управление опросом');

        View::composer('theme::pages.coordination.show', function ($view): void {
            $data = $view->getData();
            $canManage = (bool) ($data['canManage'] ?? false);

            $view->with('eventRequestId', $data['eventRequestId'] ?? null);

            if (Route::currentRouteName() === 'coordination.show') {
                $view->with([
                    'contextManagementUrl' => $canManage
                        ? route('coordination.management', $data['coordination'])
                        : null,
                    'contextManagementLabel' => 'Управление опросом',
                    'canManage' => false,
                ]);
            }
        });
    }
}
