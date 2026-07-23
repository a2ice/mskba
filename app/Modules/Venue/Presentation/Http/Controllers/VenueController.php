<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Location\Application\UseCases\ListMetrostationsHandler;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Venue\Application\Services\VenueGalleryManager;
use App\Modules\Venue\Application\Services\VenueProximityService;
use App\Modules\Venue\Application\UseCases\CreateAccountVenueHandler;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Application\UseCases\SearchVenuesHandler;
use App\Modules\Venue\Application\UseCases\ShowEditableVenueHandler;
use App\Modules\Venue\Application\UseCases\ShowManageableVenueHandler;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Modules\Venue\Application\UseCases\SubmitModerationRequestHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueRevision;
use App\Modules\Venue\Presentation\Http\Requests\CreateVenueRequest;
use App\Modules\Venue\Presentation\Http\Requests\SubmitModerationRequest;
use App\Modules\Venue\Presentation\Http\Requests\UpdateVenueRequest;
use App\Presentation\Theming\ThemeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class VenueController extends Controller
{
    public function proximityCheck(Request $request, VenueProximityService $proximity): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(VenueTypeEnum::class)],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'except_venue_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $radiusMeters = $proximity->strongRadiusMeters();
        $nearest = $proximity->nearestToCoordinates(
            type: VenueTypeEnum::from($validated['type']),
            latitude: (float) $validated['latitude'],
            longitude: (float) $validated['longitude'],
            radiusMeters: $radiusMeters,
            statuses: [VenueStatusEnum::CONFIRMED],
            exceptVenueId: isset($validated['except_venue_id']) ? (int) $validated['except_venue_id'] : null,
        );

        return response()->json([
            'has_conflict' => $nearest !== null,
            'radius_meters' => $radiusMeters,
            'distance_meters' => $nearest['distance_meters'] ?? null,
            'message' => $nearest === null
                ? null
                : 'Рядом уже есть такая площадка.',
        ]);
    }

    public function index(Request $request, ListVenuesHandler $useCase, CurrentActorResolver $actors): Response
    {
        $venues = $useCase->handle($request->user(), $actors->resolveForRequest($request));

        return ThemeResolver::page('venues.index', ['venues' => $venues]);
    }

    public function search(
        Request $request,
        SearchVenuesHandler $searchVenues,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(VenueTypeEnum::class)],
            'status' => ['nullable', Rule::enum(VenueStatusEnum::class)],
            'metro_station_id' => ['nullable', 'integer', 'exists:metro_stations,id'],
            'requires_payment' => ['nullable', 'boolean'],
            'requires_booking_approval' => ['nullable', 'boolean'],
            'confirmed_only' => ['nullable', 'boolean'],
            'operational_status' => ['nullable', Rule::enum(VenueOperationalStatusEnum::class)],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:30', 'max:480', 'required_with:starts_at'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $startsAt = isset($validated['starts_at'])
            ? CarbonImmutable::createFromFormat(
                'Y-m-d\TH:i',
                $validated['starts_at'],
                (string) config('app.timezone', 'Europe/Moscow'),
            )->utc()
            : null;

        $venues = $searchVenues->handle(
            user: $request->user(),
            actor: $actors->resolveForRequest($request),
            query: $validated['query'] ?? null,
            type: isset($validated['type']) ? VenueTypeEnum::from($validated['type']) : null,
            status: isset($validated['status']) ? VenueStatusEnum::from($validated['status']) : null,
            metroStationId: isset($validated['metro_station_id']) ? (int) $validated['metro_station_id'] : null,
            requiresPayment: isset($validated['requires_payment']) ? $request->boolean('requires_payment') : null,
            requiresBookingApproval: isset($validated['requires_booking_approval']) ? $request->boolean('requires_booking_approval') : null,
            confirmedOnly: $request->boolean('confirmed_only'),
            operationalStatus: isset($validated['operational_status'])
                ? VenueOperationalStatusEnum::from($validated['operational_status'])
                : null,
            startsAt: $startsAt,
            durationMinutes: isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null,
            limit: isset($validated['limit']) ? (int) $validated['limit'] : 20,
        );

        return response()->json([
            'venues' => collect($venues)->map(fn ($venue): array => [
                'id' => $venue->id,
                'name' => $venue->name,
                'type' => $venue->type,
                'status' => $venue->status,
                'is_confirmed' => $venue->status === VenueStatusEnum::CONFIRMED->label(),
                'description' => $venue->shortDescription,
                'address' => $venue->displayAddress,
                'raw_address' => $venue->rawAddress,
                'requires_payment' => $venue->requiresPayment,
                'requires_booking_approval' => $venue->requiresBookingApproval,
                'has_free_access' => $venue->hasFreeAccess(),
                'operational_status' => $venue->operationalStatus,
                'metro_stations' => $venue->metroStations,
                'tags' => $venue->tags,
                'latitude' => $venue->latitude,
                'longitude' => $venue->longitude,
                'url' => route('venues.show', $venue->routeIdentifier()),
                'preview_url' => route('venues.preview', $venue->routeIdentifier()),
            ])->all(),
        ]);
    }

    public function preview(
        Request $request,
        string $alias,
        ShowVenueHandler $useCase,
        CurrentActorResolver $actors,
    ): JsonResponse {
        try {
            $venue = $useCase->handle($alias, $request->user(), $actors->resolveForRequest($request));
        } catch (\Exception $exception) {
            $status = in_array($exception->getCode(), [403, 404], true)
                ? $exception->getCode()
                : 404;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json([
            'venue' => [
                'id' => $venue->id,
                'name' => $venue->name,
                'type' => $venue->type,
                'status' => $venue->status,
                'is_open' => $venue->isOpen,
                'today_hours' => $venue->todayHours,
                'description' => $venue->shortDescription ?: $venue->fullDescription,
                'address' => $venue->address?->display ?: $venue->rawAddress,
                'metro_stations' => collect($venue->metroStations)->map(fn ($station): array => [
                    'name' => $station->name,
                    'line_name' => $station->lineName,
                    'line_color' => $station->lineColor,
                ])->all(),
                'image_url' => $venue->featuredMedia[0]['url'] ?? null,
                'url' => route('venues.show', $venue->routeIdentifier()),
            ],
        ]);
    }

    public function create(ListMetrostationsHandler $listMetrostations): Response
    {
        $metros = $listMetrostations->handle();

        return ThemeResolver::page('venues.create', [
            'types' => VenueTypeEnum::cases(),
            'metros' => $metros,
        ]);
    }

    public function store(
        CreateVenueRequest $request,
        CreateAccountVenueHandler $createVenue,
        CurrentActorResolver $actors,
    ): RedirectResponse|JsonResponse {
        try {
            $venue = $createVenue->handle(
                $actors->resolveForRequest($request),
                $request->validated(),
                $request->locationData(),
                $request->tagNames(),
            );
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('venues.create')
                ->withInput()
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Площадка создана. Проверьте данные и отправьте её на модерацию.',
                'venue' => $this->venuePayload($venue),
            ], 201);
        }

        return redirect()
            ->route('account.venues.edit', $venue->routeIdentifier())
            ->with('status', 'Площадка создана. Проверьте и дополните данные.');
    }

    public function show(
        Request $request,
        string $alias,
        ShowVenueHandler $useCase,
        CurrentActorResolver $actors,
    ): Response {
        try {
            $venue = $useCase->handle($alias, $request->user(), $actors->resolveForRequest($request));
        } catch (\Exception $e) {
            return ThemeResolver::page('venues.show', ['error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]]);
        }

        return ThemeResolver::page('venues.show', ['venue' => $venue]);
    }

    public function edit(
        Request $request,
        string $alias,
        ShowEditableVenueHandler $showEditableVenue,
        ListMetrostationsHandler $listMetrostations,
        CurrentActorResolver $actors,
        VenueGalleryManager $gallery,
    ): Response {
        try {
            $venue = $showEditableVenue->handle($alias, $request->user(), $actors->resolveForRequest($request));
        } catch (\Exception $e) {
            return ThemeResolver::page('venues.edit', ['venue' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        return ThemeResolver::page('venues.edit', [
            'venue' => $venue,
            'venueRevision' => $venue->draftRevision,
            'venuePhotos' => $gallery->editableGallery($venue),
            'types' => VenueTypeEnum::cases(),
            'metros' => $listMetrostations->handle(),
        ]);
    }

    public function update(
        UpdateVenueRequest $request,
        string $alias,
        UpdateVenueHandler $updateVenue,
        CurrentActorResolver $actors,
    ): RedirectResponse|JsonResponse {
        try {
            $venue = $updateVenue->handle(
                alias: $alias,
                user: $request->user(),
                actor: $actors->resolveForRequest($request),
                data: $request->validated(),
                locationData: $request->locationData(),
                tagNames: $request->tagNames(),
            );
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('account.venues.edit', $alias)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            $revision = $venue->status === VenueStatusEnum::CONFIRMED
                ? $venue->draftRevision()->first()
                : null;

            return response()->json([
                'message' => $venue->status === VenueStatusEnum::CONFIRMED
                    ? 'Изменения сохранены в черновик. Отправьте их на модерацию.'
                    : 'Площадка сохранена.',
                'venue' => $this->venuePayload($venue, $revision),
            ]);
        }

        $message = $venue->status === VenueStatusEnum::CONFIRMED
            ? 'Изменения сохранены в черновик. Отправьте их на модерацию.'
            : 'Площадка сохранена.';

        return redirect()
            ->route('account.venues.edit', $venue->routeIdentifier())
            ->with('status', $message);
    }

    public function status(
        Request $request,
        string $alias,
        ShowManageableVenueHandler $showManageableVenue,
        CurrentActorResolver $actors,
    ): Response {
        try {
            $venue = $showManageableVenue->handle($alias, $request->user(), $actors->resolveForRequest($request));
        } catch (\Exception $e) {
            return ThemeResolver::page('venues.status', ['venue' => null, 'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ]]);
        }

        return ThemeResolver::page('venues.status', ['venue' => $venue]);
    }

    public function moderationState(
        Request $request,
        string $alias,
        ShowManageableVenueHandler $showManageableVenue,
        CurrentActorResolver $actors,
    ): JsonResponse {
        try {
            $venue = $showManageableVenue->handle($alias, $request->user(), $actors->resolveForRequest($request));
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 404);
        }

        $pending = $venue->moderationRequests
            ->first(fn ($moderationRequest) => $moderationRequest->status === ModerationRequestStatusEnum::PENDING);
        $latest = $venue->moderationRequests->first();
        $canSubmit = $pending === null && (
            $venue->status === VenueStatusEnum::UNCONFIRMED
            || ($venue->status === VenueStatusEnum::CONFIRMED && $venue->draftRevision()->whereNull('applied_at')->exists())
        );
        $displayStatus = $pending?->status
            ?? ($venue->status === VenueStatusEnum::UNCONFIRMED ? $latest?->status : null);

        return response()->json([
            'moderation' => [
                'can_submit' => $canSubmit,
                'state' => $displayStatus?->value ?? $venue->status->value,
                'label' => $displayStatus?->label() ?? $venue->status->label(),
                'history' => $venue->moderationRequests->map(fn ($moderationRequest): array => [
                    'id' => $moderationRequest->id,
                    'status' => $moderationRequest->status->value,
                    'status_label' => $moderationRequest->status->label(),
                    'submitted_at' => $moderationRequest->submitted_at?->format('d.m.Y H:i'),
                    'messages' => $moderationRequest->messages
                        ->sortByDesc('id')
                        ->map(fn ($message): array => [
                            'id' => $message->id,
                            'sender_label' => $message->sender_id === $moderationRequest->submitted_by_actor_id ? 'Вы' : 'Модератор',
                            'sender_username' => $message->sender?->user?->username
                                ?? $message->sender?->user?->email
                                ?? 'гость',
                            'message' => $message->message,
                            'created_at' => $message->created_at?->format('d.m.Y H:i'),
                        ])
                        ->values()
                        ->all(),
                ])->values()->all(),
            ],
        ]);
    }

    public function submitModeration(
        SubmitModerationRequest $request,
        string $alias,
        SubmitModerationRequestHandler $submitModeration,
        CurrentActorResolver $actors,
    ): RedirectResponse|JsonResponse {
        try {
            $submitModeration->handle(
                alias: $alias,
                user: $request->user(),
                actor: $actors->resolveForRequest($request),
                message: $request->messageText(),
            );
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('account.venues.status', $alias)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Площадка отправлена на модерацию.']);
        }

        return redirect()
            ->route('account.venues.status', $alias)
            ->with('status', 'Площадка отправлена на модерацию.');
    }

    public function remove(string $alias): Response
    {
        return ThemeResolver::page('venues.show', ['error' => [
            'message' => 'Удаление площадки будет реализовано отдельно.',
            'code' => 501,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function venuePayload(Venue $venue, ?VenueRevision $revision = null): array
    {
        $venue->loadMissing('location.address', 'location.metroStations.line', 'tags');
        $revisionPayload = $revision?->payload ?? [];
        $details = is_array($revisionPayload['details'] ?? null) ? $revisionPayload['details'] : [];
        $location = is_array($revisionPayload['location'] ?? null) ? $revisionPayload['location'] : [];

        return [
            'alias' => $venue->alias,
            'name' => $details['name'] ?? $venue->name,
            'type' => $details['type'] ?? $venue->type->value,
            'requires_payment' => $venue->requires_payment,
            'requires_booking_approval' => $venue->requires_booking_approval,
            'has_free_access' => $venue->hasFreeAccess(),
            'short_description' => $details['short_description'] ?? $venue->short_description,
            'full_description' => $details['full_description'] ?? $venue->full_description,
            'tags' => is_array($revisionPayload['tags'] ?? null) ? $revisionPayload['tags'] : $venue->tags->pluck('name')->values()->all(),
            'address' => $location['raw_address'] ?? $venue->location?->address?->full_address ?? $venue->raw_address,
            'metro' => collect($venue->location?->metroStations ?? [])
                ->map(fn ($station): array => [
                    'id' => $station->id,
                    'label' => $station->name.($station->line?->name ? ' ('.$station->line->name.')' : ''),
                ])
                ->values()
                ->all(),
            'update_url' => route('account.venues.update', $venue->routeIdentifier()),
            'moderation_url' => route('account.venues.moderation.submit', $venue->routeIdentifier()),
            'moderation_state_url' => route('account.venues.moderation.state', $venue->routeIdentifier()),
            'photos_store_url' => route('account.venues.photos.store', $venue->routeIdentifier()),
        ];
    }
}
