<?php

namespace App\Modules\Venue\Infrastructure\Providers;

use App\Modules\Admin\Presentation\Http\Controllers\AdminVenueOwnershipController;
use App\Modules\Admin\Presentation\Http\Controllers\AdminVenueOwnershipDocumentController;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaimConversation;
use App\Modules\Venue\Infrastructure\Http\Middleware\VenueOwnershipIntendedRedirect;
use App\Modules\Venue\Presentation\Http\Controllers\VenueOwnershipClaimController;
use App\Modules\Venue\Presentation\Http\Controllers\VenueOwnershipClaimConversationController;
use App\Modules\Venue\Presentation\Http\Controllers\VenueOwnershipClaimDocumentController;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

final class VenueOwnershipServiceProvider extends RouteServiceProvider
{
    public function boot(Router $router): void
    {
        $router->pushMiddlewareToGroup('web', VenueOwnershipIntendedRedirect::class);

        $this->routes(function (): void {
            Route::middleware('web')->group(function (): void {
                Route::get('/venues/{venue}/management', [VenueOwnershipClaimController::class, 'landing'])
                    ->whereNumber('venue')
                    ->name('venues.management');

                Route::middleware('auth')->group(function (): void {
                    Route::get('/venues/{venue}/management/verify', [VenueOwnershipClaimController::class, 'verify'])
                        ->whereNumber('venue')
                        ->name('venues.management.verify');
                    Route::post('/venues/{venue}/management/claim', [VenueOwnershipClaimController::class, 'store'])
                        ->whereNumber('venue')
                        ->name('venues.management.claim');

                    Route::prefix('account/venue-ownership/{venueOwnershipClaim}')
                        ->group(function (): void {
                            Route::get('/', [VenueOwnershipClaimController::class, 'show'])
                                ->name('account.venue-ownership.show');
                            Route::post('/cancel', [VenueOwnershipClaimController::class, 'cancel'])
                                ->name('account.venue-ownership.cancel');
                            Route::post('/approve', [AdminVenueOwnershipController::class, 'approve'])
                                ->name('account.venue-ownership.approve');
                            Route::post('/reject', [AdminVenueOwnershipController::class, 'reject'])
                                ->name('account.venue-ownership.reject');
                            Route::post('/reject-and-block', [AdminVenueOwnershipController::class, 'rejectAndBlock'])
                                ->name('account.venue-ownership.reject-and-block');
                            Route::get('/documents/{document}', [VenueOwnershipClaimDocumentController::class, 'download'])
                                ->middleware('throttle:60,1')
                                ->name('account.venue-ownership.documents.download');
                            Route::get('/conversation', [VenueOwnershipClaimConversationController::class, 'index'])
                                ->name('account.venue-ownership.conversation.index');
                            Route::post('/conversation/messages', [VenueOwnershipClaimConversationController::class, 'store'])
                                ->middleware('throttle:20,1')
                                ->name('account.venue-ownership.conversation.store');
                            Route::post('/conversation/attachments', [VenueOwnershipClaimConversationController::class, 'attach'])
                                ->middleware('throttle:10,1')
                                ->name('account.venue-ownership.conversation.attach');
                            Route::get('/conversation/messages/{message}/attachment', [VenueOwnershipClaimConversationController::class, 'download'])
                                ->middleware('throttle:60,1')
                                ->name('account.venue-ownership.conversation.attachment');
                        });

                    Route::prefix('admin/venue-ownership')->group(function (): void {
                        Route::get('/', [AdminVenueOwnershipController::class, 'index'])
                            ->name('admin.venue-ownership.index');
                        Route::post('/claims/{venueOwnershipClaim}/approve', [AdminVenueOwnershipController::class, 'approve'])
                            ->name('admin.venue-ownership.claims.approve');
                        Route::post('/claims/{venueOwnershipClaim}/reject', [AdminVenueOwnershipController::class, 'reject'])
                            ->name('admin.venue-ownership.claims.reject');
                        Route::post('/claims/{venueOwnershipClaim}/reject-and-block', [AdminVenueOwnershipController::class, 'rejectAndBlock'])
                            ->name('admin.venue-ownership.claims.reject-and-block');
                        Route::patch('/ownerships/{venueOwnership}/status', [AdminVenueOwnershipController::class, 'updateOwnershipStatus'])
                            ->name('admin.venue-ownership.status');
                        Route::post('/ownerships/{venueOwnership}/claim-documents/{document}', [AdminVenueOwnershipController::class, 'attachClaimDocument'])
                            ->name('admin.venue-ownership.documents.claim');
                        Route::post('/ownerships/{venueOwnership}/messages/{message}', [AdminVenueOwnershipController::class, 'attachMessageDocument'])
                            ->name('admin.venue-ownership.documents.message');
                        Route::get('/documents/{document}', [AdminVenueOwnershipController::class, 'downloadDocument'])
                            ->name('admin.venue-ownership.documents.download');
                        Route::patch('/documents/{document}', [AdminVenueOwnershipDocumentController::class, 'update'])
                            ->name('admin.venue-ownership.documents.update');
                        Route::post('/restrictions/{venueUserRestriction}/revoke', [AdminVenueOwnershipController::class, 'revokeRestriction'])
                            ->name('admin.venue-ownership.restrictions.revoke');
                    });

                    Route::post('/account/venue-bookings/{venueBooking}/block-requester', [AdminVenueOwnershipController::class, 'blockRentalRequester'])
                        ->name('account.venue-bookings.block-requester');
                });
            });
        });

        Broadcast::channel('venue-ownership-claims.{publicId}', function (User $user, string $publicId): bool {
            $claim = VenueOwnershipClaim::query()->where('public_id', $publicId)->first();
            if ($claim === null) {
                return false;
            }

            $user = $user->canonical();

            return $user->isSameIdentity($claim->applicant_user_id)
                || ($user->isConfirmed() && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN));
        });

        Broadcast::channel('venue-ownership-claim-conversations.{publicId}', function (User $user, string $publicId): bool {
            $conversation = VenueOwnershipClaimConversation::query()
                ->with('claim')
                ->where('public_id', $publicId)
                ->first();
            if ($conversation === null) {
                return false;
            }

            $user = $user->canonical();

            return $user->isSameIdentity($conversation->claim->applicant_user_id)
                || ($user->isConfirmed() && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN));
        });
    }
}
