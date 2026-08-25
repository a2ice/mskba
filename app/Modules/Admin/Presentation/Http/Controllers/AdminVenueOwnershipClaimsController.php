<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Venue\Application\UseCases\ReviewVenueOwnershipClaimHandler;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AdminVenueOwnershipClaimsController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(VenueOwnershipClaimStatusEnum::class)],
        ]);

        $status = VenueOwnershipClaimStatusEnum::tryFrom($validated['status'] ?? '')
            ?? VenueOwnershipClaimStatusEnum::PENDING;

        return ThemeResolver::page('admin.venue-ownership-claims', [
            'claims' => VenueOwnershipClaim::query()
                ->with(['venue', 'applicant.profile', 'reviewer'])
                ->where('status', $status->value)
                ->oldest('submitted_at')
                ->paginate(30)
                ->withQueryString(),
            'selectedStatus' => $status,
            'statuses' => VenueOwnershipClaimStatusEnum::cases(),
        ]);
    }

    public function approve(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        ReviewVenueOwnershipClaimHandler $review,
    ): RedirectResponse {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        try {
            $review->approve($venueOwnershipClaim, $request->user(), $validated['reason'] ?? null);
        } catch (VenueOwnershipClaimException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Заявка одобрена, права владельца выданы.');
    }

    public function reject(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        ReviewVenueOwnershipClaimHandler $review,
    ): RedirectResponse {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        try {
            $review->reject($venueOwnershipClaim, $request->user(), $validated['reason']);
        } catch (VenueOwnershipClaimException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Заявка отклонена.');
    }
}
