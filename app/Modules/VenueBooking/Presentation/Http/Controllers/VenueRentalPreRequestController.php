<?php

namespace App\Modules\VenueBooking\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Application\Services\VenueRentalPreRequests;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingPolicyException;
use App\Modules\VenueBooking\Domain\Exceptions\VenueBookingTransitionException;
use App\Modules\VenueBooking\Domain\Models\VenueBookingMessage;
use App\Modules\VenueBooking\Domain\Models\VenueRentalPreRequest;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VenueRentalPreRequestController extends Controller
{
    public function __construct(private readonly VenueRentalPreRequests $service, private readonly CurrentActorResolver $actors) {}

    public function store(Request $request, Venue $venue): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'venue_court_id' => ['required', 'integer', Rule::exists('venue_courts', 'id')->where('venue_id', $venue->id)->whereNull('deleted_at')],
            'starts_at' => ['required', 'date'], 'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'scope' => ['required', Rule::enum(VenueBookingScopeEnum::class)],
            'idempotency_key' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:4000'],
        ]);
        try {
            $pre = $this->service->create($this->actors->resolveForRequest($request), $venue,
                $venue->courts()->findOrFail($data['venue_court_id']),
                CarbonImmutable::parse($data['starts_at'], $venue->schedule()->value('timezone') ?: config('app.timezone')),
                (int) $data['duration_minutes'], VenueBookingScopeEnum::from($data['scope']), $data['idempotency_key'], $data['note'] ?? null);
        } catch (VenueBookingPolicyException|VenueBookingTransitionException $exception) {
            return $this->error($request, $exception);
        }
        return $request->expectsJson()
            ? response()->json(['pre_request_id' => $pre->public_id, 'url' => route('account.venue-pre-requests.show', $pre)], 201)
            : redirect()->route('account.venue-pre-requests.show', $pre);
    }

    public function index(Request $request): Response
    {
        $user = $request->user()->canonical();
        // Apply ACL in SQL before pagination; never expose rows or totals from other courts.
        $memberships = app(\App\Modules\Venue\Application\Services\VenueMembershipAccess::class);
        $permissions = [\App\Modules\Venue\Domain\Enums\VenuePermissionEnum::VIEW_EVENT_INTERESTS,
            \App\Modules\Venue\Domain\Enums\VenuePermissionEnum::VIEW_BOOKING_REQUESTS,
            \App\Modules\Venue\Domain\Enums\VenuePermissionEnum::PROPOSE_EVENT_BOOKING];
        $venues = $courts = $managementVenues = [];
        foreach ($permissions as $permission) {
            $venues = [...$venues, ...$memberships->allowedVenueIdsFor($user, $permission)];
            $courts = [...$courts, ...$memberships->allowedCourtIdsFor($user, $permission)];
        }
        foreach ($memberships->activeVenueMembershipsFor($user) as $membership) {
            if ($membership->scope_type === \App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum::VENUE
                && in_array($membership->access_level, [\App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum::OWNER->value,
                    \App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum::ADMIN->value], true)) {
                $managementVenues[] = $membership->scope_id;
            }
        }
        $query = VenueRentalPreRequest::query()->with(['venue', 'court', 'requester'])->latest('id');
        if (! $user->hasSystemRole(\App\Modules\Identity\Domain\Enums\UserSystemRoleEnum::SUPERADMIN)
            && ! app(\App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess::class)->canView($user)) {
            $query->where(fn ($q) => $q->where('requester_user_id', $user->id)
                ->orWhereIn('venue_id', $managementVenues)
                ->orWhere(fn ($q) => $q->whereHas('venue', fn ($v) => $v->whereNull('deleted_at'))
                    ->whereHas('court', fn ($c) => $c->whereNull('deleted_at'))
                    ->where(fn ($q) => $q->whereIn('venue_id', $venues)->orWhereIn('venue_court_id', $courts))));
        }
        if ($request->filled('venue_id')) { $query->where('venue_id', $request->integer('venue_id')); }
        $preRequests = $query->paginate(20);
        // Blocked/unconfirmed accounts cannot inspect historical venue records.
        abort_unless($user->isConfirmed() && ! $user->isBlocked(), 403);
        return ThemeResolver::page('venue-pre-requests.index', compact('preRequests'));
    }

    public function show(Request $request, VenueRentalPreRequest $preRequest): Response
    {
        $actor = $this->actors->resolveForRequest($request);
        $this->service->assertCan($actor, $preRequest);
        $preRequest->load(['venue', 'court', 'requester', 'bookings']);
        if (app(\App\Modules\VenueBooking\Application\Services\VenueBookingBackofficeAccess::class)->canView($request->user())) {
            \App\Modules\Audit\Domain\Models\AuditLog::query()->create([
                'actor_id' => $actor->id, 'auditable_type' => VenueRentalPreRequest::class, 'auditable_id' => $preRequest->id,
                'event' => 'rental_support_conversation_viewed', 'old_values' => [], 'new_values' => [],
                'metadata' => ['route' => $request->route()?->getName(), 'venue_id' => $preRequest->venue_id],
            ]);
        }
        $messages = $preRequest->conversation->messages()->with('authorActor.user')->latest('id')->paginate(50);
        return ThemeResolver::page('venue-pre-requests.show', [
            'pre' => $preRequest, 'messages' => $messages,
            'canWrite' => $this->service->can($request->user(), $preRequest, 'write'),
            'canOffer' => $this->service->can($request->user(), $preRequest, 'offer'),
            'isCustomer' => $actor->user?->canonical()->id === $preRequest->requester_user_id,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function offer(Request $request, VenueRentalPreRequest $preRequest): JsonResponse|RedirectResponse
    {
        try { $this->service->offer($preRequest, $this->actors->resolveForRequest($request)); }
        catch (VenueBookingPolicyException|VenueBookingTransitionException $exception) { return $this->error($request, $exception); }
        return redirect()->route('account.venue-pre-requests.show', $preRequest);
    }

    public function respond(Request $request, VenueRentalPreRequest $preRequest): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['booking_id' => ['required', 'integer'], 'decision' => ['required', Rule::in(['accept', 'decline'])]]);
        try { $this->service->respond($preRequest, $this->actors->resolveForRequest($request), (int) $data['booking_id'], $data['decision'] === 'accept'); }
        catch (VenueBookingTransitionException $exception) { return $this->error($request, $exception); }
        return redirect()->route('account.venue-pre-requests.show', $preRequest);
    }

    public function withdraw(Request $request, VenueRentalPreRequest $preRequest): RedirectResponse
    {
        $this->service->withdraw($preRequest, $this->actors->resolveForRequest($request));
        return redirect()->route('account.venue-pre-requests.show', $preRequest);
    }

    public function send(Request $request, VenueRentalPreRequest $preRequest): RedirectResponse
    {
        $data = $request->validate(['client_id' => ['required', 'uuid'], 'body' => ['nullable', 'required_without:attachment', 'string', 'max:4000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf,txt', 'max:10240']]);
        $this->service->send($preRequest, $this->actors->resolveForRequest($request), $data['client_id'], $data['body'] ?? '', $request->file('attachment'));
        return redirect()->route('account.venue-pre-requests.show', $preRequest);
    }

    public function download(Request $request, VenueRentalPreRequest $preRequest, VenueBookingMessage $message): StreamedResponse
    {
        $this->service->assertCan($this->actors->resolveForRequest($request), $preRequest);
        abort_unless($message->conversation_id === $preRequest->conversation->id && $message->attachment_disk === 'local' && $message->attachment_path !== null, 404);
        return Storage::disk('local')->download($message->attachment_path, $message->attachment_name,
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function error(Request $request, \Throwable $exception): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['message' => $exception->getMessage()], 409)
            : back()->withInput()->with('error', $exception->getMessage());
    }
}
