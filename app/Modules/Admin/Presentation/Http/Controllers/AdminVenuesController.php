<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminVenuesHandler;
use App\Modules\Admin\Presentation\Http\Requests\ReviewVenueModerationRequest as ReviewVenueModerationHttpRequest;
use App\Modules\Venue\Application\UseCases\ReviewVenueModerationRequestHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\VenueModerationRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminVenuesController extends Controller
{
    public function index(Request $request, ListAdminVenuesHandler $venues): Response
    {
        return ThemeResolver::page('admin.venues', [
            'venues' => $venues->handle($request->query()),
            'filters' => $request->query(),
            'statuses' => VenueStatusEnum::cases(),
            'types' => VenueTypeEnum::cases(),
        ]);
    }

    public function approve(
        VenueModerationRequest $venueModerationRequest,
        ReviewVenueModerationRequestHandler $review,
    ): RedirectResponse {
        try {
            $review->approve($venueModerationRequest, request()->user());
        } catch (\Exception $e) {
            return redirect()->route('admin.venues')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.venues')->with('success', 'Площадка подтверждена.');
    }

    public function reject(
        ReviewVenueModerationHttpRequest $request,
        VenueModerationRequest $venueModerationRequest,
        ReviewVenueModerationRequestHandler $review,
    ): RedirectResponse {
        try {
            $review->reject($venueModerationRequest, $request->user(), $request->messageText());
        } catch (\Exception $e) {
            return redirect()->route('admin.venues')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.venues')->with('success', 'Заявка отклонена.');
    }

    public function block(
        ReviewVenueModerationHttpRequest $request,
        VenueModerationRequest $venueModerationRequest,
        ReviewVenueModerationRequestHandler $review,
    ): RedirectResponse {
        try {
            $review->block($venueModerationRequest, $request->user(), $request->messageText());
        } catch (\Exception $e) {
            return redirect()->route('admin.venues')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.venues')->with('success', 'Площадка заблокирована.');
    }
}
