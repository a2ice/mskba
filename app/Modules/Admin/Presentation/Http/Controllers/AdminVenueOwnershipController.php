<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Application\Services\VenueUserRestrictionService;
use App\Modules\Venue\Application\UseCases\AttachVenueOwnershipDocumentHandler;
use App\Modules\Venue\Application\UseCases\ReviewVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueOwnershipStatusHandler;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueOwnershipDocumentTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueUserRestrictionTypeEnum;
use App\Modules\Venue\Domain\Exceptions\VenueOwnershipClaimException;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaimMessage;
use App\Modules\Venue\Domain\Models\VenueOwnershipDocument;
use App\Modules\Venue\Domain\Models\VenueUserRestriction;
use App\Modules\VenueBooking\Domain\Models\VenueBooking;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminVenueOwnershipController extends Controller
{
    public function index(Request $request): Response
    {
        $this->administrator($request);
        $queue = in_array($request->query('queue'), ['new', 'in_work', 'completed'], true)
            ? (string) $request->query('queue')
            : 'new';
        $ownershipStatus = VenueOwnershipStatusEnum::tryFrom((string) $request->query('ownership_status'))
            ?? VenueOwnershipStatusEnum::ACTIVE;

        $claims = VenueOwnershipClaim::query()
            ->with(['venue', 'applicant.profile', 'reviewer.profile', 'conversation'])
            ->when($queue === 'new', fn ($query) => $query
                ->where('status', VenueOwnershipClaimStatusEnum::PENDING->value)
                ->whereDoesntHave('conversation'))
            ->when($queue === 'in_work', fn ($query) => $query
                ->where('status', VenueOwnershipClaimStatusEnum::PENDING->value)
                ->whereHas('conversation'))
            ->when($queue === 'completed', fn ($query) => $query
                ->where('status', '!=', VenueOwnershipClaimStatusEnum::PENDING->value))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->oldest('submitted_at')
            ->paginate(30, ['*'], 'claimsPage')
            ->withQueryString();

        $ownerships = VenueOwnership::query()
            ->with(['venue', 'owner.profile', 'sourceClaim', 'documents', 'statusChangedBy.profile'])
            ->where('status', $ownershipStatus->value)
            ->latest('status_changed_at')
            ->latest('id')
            ->paginate(30, ['*'], 'ownershipsPage')
            ->withQueryString();

        $activeRestrictions = VenueUserRestriction::query()
            ->with(['venue', 'user.profile', 'imposedBy.profile'])
            ->where('active_marker', true)
            ->latest('imposed_at')
            ->paginate(30, ['*'], 'restrictionsPage')
            ->withQueryString();

        return ThemeResolver::page('admin.venue-ownership-management', [
            'claims' => $claims,
            'queue' => $queue,
            'ownerships' => $ownerships,
            'ownershipStatus' => $ownershipStatus,
            'ownershipStatuses' => VenueOwnershipStatusEnum::cases(),
            'documentTypes' => VenueOwnershipDocumentTypeEnum::cases(),
            'activeRestrictions' => $activeRestrictions,
        ]);
    }

    public function approve(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        ReviewVenueOwnershipClaimHandler $review,
    ): RedirectResponse {
        $this->administrator($request);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        try {
            $review->approve($venueOwnershipClaim, $request->user(), $validated['reason'] ?? null);
        } catch (VenueOwnershipClaimException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Управление площадкой подтверждено.');
    }

    public function reject(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        ReviewVenueOwnershipClaimHandler $review,
    ): RedirectResponse {
        $this->administrator($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        try {
            $review->reject($venueOwnershipClaim, $request->user(), $validated['reason']);
        } catch (VenueOwnershipClaimException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Заявка отклонена.');
    }

    public function rejectAndBlock(
        Request $request,
        VenueOwnershipClaim $venueOwnershipClaim,
        ReviewVenueOwnershipClaimHandler $review,
        VenueUserRestrictionService $restrictions,
    ): RedirectResponse {
        $this->administrator($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        try {
            DB::transaction(function () use ($venueOwnershipClaim, $review, $restrictions, $request, $validated): void {
                $claim = VenueOwnershipClaim::query()
                    ->with(['venue', 'applicant'])
                    ->lockForUpdate()
                    ->findOrFail($venueOwnershipClaim->id);

                if ($claim->status === VenueOwnershipClaimStatusEnum::PENDING) {
                    $review->reject($claim, $request->user(), $validated['reason']);
                    $claim->refresh()->loadMissing(['venue', 'applicant']);
                }

                $restrictions->impose(
                    $claim->venue,
                    $claim->applicant,
                    VenueUserRestrictionTypeEnum::OWNERSHIP_CLAIM,
                    $validated['reason'],
                    $request->user(),
                );
            });
        } catch (VenueOwnershipClaimException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Заявка закрыта, повторная подача этим пользователем заблокирована.');
    }

    public function updateOwnershipStatus(
        Request $request,
        VenueOwnership $venueOwnership,
        UpdateVenueOwnershipStatusHandler $handler,
    ): RedirectResponse {
        $this->administrator($request);
        $validated = $request->validate([
            'status' => ['required', Rule::enum(VenueOwnershipStatusEnum::class)],
            'reason' => ['required', 'string', 'min:5', 'max:3000'],
        ]);

        try {
            $handler->handle(
                $venueOwnership,
                VenueOwnershipStatusEnum::from($validated['status']),
                $validated['reason'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Статус владения обновлён.');
    }

    public function attachMessageDocument(
        Request $request,
        VenueOwnership $venueOwnership,
        VenueOwnershipClaimMessage $message,
        AttachVenueOwnershipDocumentHandler $handler,
    ): RedirectResponse {
        $this->administrator($request);
        $validated = $request->validate([
            'type' => ['required', Rule::enum(VenueOwnershipDocumentTypeEnum::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $handler->fromMessage(
                $venueOwnership,
                $message,
                VenueOwnershipDocumentTypeEnum::from($validated['type']),
                $request->user(),
                $validated['note'] ?? null,
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Вложение переписки сохранено как основание владения.');
    }

    public function downloadDocument(Request $request, VenueOwnershipDocument $document): StreamedResponse
    {
        $this->administrator($request);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return response()->streamDownload(
            static fn () => print Storage::disk($document->disk)->get($document->path),
            $document->name,
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function rentalRequesterRestriction(
        Request $request,
        VenueBooking $venueBooking,
        VenueUserRestrictionService $restrictions,
    ): JsonResponse {
        $this->administrator($request);
        $venueBooking->loadMissing(['venue', 'requester']);
        abort_unless($venueBooking->requester !== null, 422);

        $restriction = $restrictions->active(
            $venueBooking->venue,
            $venueBooking->requester,
            VenueUserRestrictionTypeEnum::RENTAL_REQUEST,
        );

        return response()->json([
            'restricted' => $restriction !== null,
            'reason' => $restriction?->reason,
            'imposed_at' => $restriction?->imposed_at?->toIso8601String(),
            'block_url' => route('account.venue-bookings.block-requester', $venueBooking, false),
            'revoke_url' => $restriction === null
                ? null
                : route('admin.venue-ownership.restrictions.revoke', $restriction, false),
        ]);
    }

    public function blockRentalRequester(
        Request $request,
        VenueBooking $venueBooking,
        VenueUserRestrictionService $restrictions,
    ): RedirectResponse {
        $this->administrator($request);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $venueBooking->loadMissing(['venue', 'requester']);
        abort_unless($venueBooking->requester !== null, 422);

        $restrictions->impose(
            $venueBooking->venue,
            $venueBooking->requester,
            VenueUserRestrictionTypeEnum::RENTAL_REQUEST,
            $validated['reason'],
            $request->user(),
        );

        return back()->with('status', 'Повторные заявки на аренду этой площадки от пользователя заблокированы.');
    }

    public function revokeRestriction(
        Request $request,
        VenueUserRestriction $venueUserRestriction,
        VenueUserRestrictionService $restrictions,
    ): RedirectResponse {
        $this->administrator($request);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $restrictions->revoke($venueUserRestriction, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', 'Ограничение снято.');
    }

    private function administrator(Request $request): void
    {
        $user = $request->user()?->canonical();
        abort_unless(
            $user !== null && $user->isConfirmed() && $user->system_role->atLeast(UserSystemRoleEnum::ADMIN),
            403,
        );
    }
}
