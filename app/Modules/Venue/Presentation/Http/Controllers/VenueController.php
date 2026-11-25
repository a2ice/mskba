<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\PageSeoResolver;
use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\PlayInterest\Domain\Models\PlayIntent;
use App\Modules\Location\Application\UseCases\ListMetrostationsHandler;
use App\Modules\Location\Domain\Models\City;
use App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum;
use App\Modules\Venue\Application\Services\VenueGalleryManager;
use App\Modules\Venue\Application\Services\AccountVenueEditorAccess;
use App\Modules\Venue\Application\Services\VenueProximityService;
use App\Modules\Venue\Application\UseCases\CreateAccountVenueHandler;
use App\Modules\Venue\Application\UseCases\FindNearbyVenuesHandler;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Application\UseCases\SearchVenuesHandler;
use App\Modules\Venue\Application\UseCases\ShowEditableVenueHandler;
use App\Modules\Venue\Application\UseCases\ShowManageableVenueHandler;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Modules\Venue\Application\UseCases\SubmitModerationRequestHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnershipClaim;
use App\Modules\Venue\Domain\Models\VenueRevision;
use App\Modules\Venue\Domain\Models\Amenity;
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
use Illuminate\Validation\ValidationException;

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

    public function index(Request $request, ListVenuesHandler $useCase, CurrentActorResolver $actors): Response|JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'city_id' => ['nullable', 'integer', 'min:1', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', 'min:1', 'exists:districts,id'],
            'type' => ['nullable', Rule::enum(VenueTypeEnum::class)],
            'operational_status' => ['nullable', Rule::enum(VenueOperationalStatusEnum::class)],
            'access' => ['nullable', Rule::in(['free', 'paid', 'approval'])],
            'rental_configured' => ['nullable', 'boolean'],
            'has_upcoming_events' => ['nullable', 'boolean'],
            'my_interests' => ['nullable', 'boolean'],
            'interest_id' => ['nullable', 'integer', 'min:1'],
            'event_days' => ['nullable', 'integer', 'between:1,14'],
            'latitude' => ['nullable', 'required_with:longitude,radius_km', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude,radius_km', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'required_with:latitude,longitude', 'integer', 'between:1,10'],
            'view' => ['nullable', Rule::in(['list', 'map'])],
            'sort' => ['nullable', Rule::in(['new', 'name_asc', 'name_desc'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'fragment' => ['nullable', Rule::in(['items'])],
        ]);
        // A selected interest is strictly owned by the current canonical user.
        // An empty selection filters against ANY active, non-expired interest.
        $viewer = $request->user()?->canonical();
        $interestsQuery = $viewer
            ? PlayIntent::query()->with('venue')->where('user_id', $viewer->id)
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->where('favorite_generated', false)
            : null;
        $myInterests = $interestsQuery?->orderByDesc('updated_at')->get() ?? collect();
        $chosenInterest = isset($filters['interest_id']) ? (int) $filters['interest_id'] : null;
        if ($chosenInterest !== null) {
            abort_unless(($filters['my_interests'] ?? false) && $viewer, 404);
            abort_unless($myInterests->contains('id', $chosenInterest), 404);
        }
        if ($request->boolean('my_interests')) {
            abort_unless($viewer, 403);
        }
        $matchingInterests = $request->boolean('my_interests')
            ? ($chosenInterest !== null ? $myInterests->where('id', $chosenInterest)->values() : $myInterests)
            : null;
        $venues = $useCase->handle(
            $request->user(), $actors->resolveForRequest($request), $filters, $matchingInterests,
            configuredRentalOnly: $request->boolean('rental_configured'),
        );
        if (app(ThemeResolver::class)->active() !== 'mskba_app') {
            return ThemeResolver::page('venues.index', [
                'venues' => $venues,
                'filters' => $filters,
                'types' => VenueTypeEnum::cases(),
                'operationalStatuses' => VenueOperationalStatusEnum::cases(),
                'myInterests' => $myInterests,
                'geographyCities' => City::query()->with(['districts' => fn ($query) => $query->orderBy('name')])->orderBy('name')->get(),
            ]);
        }

        $total = count($venues);
        $page = (int) ($filters['page'] ?? 1);
        $perPage = 4;
        $currentView = in_array($filters['view'] ?? null, ['list', 'map'], true)
            ? $filters['view'] : 'cards';

        // The map must show every matching venue, not only the four cards on
        // the current infinite-scroll page. Reuse the same access-aware query.
        $mapPoints = [];
        if ($currentView === 'map') {
            foreach ($venues as $venue) {
                if ($venue->latitude === null || $venue->longitude === null) {
                    continue;
                }
                $mapPoints[] = [
                    'id' => $venue->id,
                    'name' => $venue->name,
                    'address' => $venue->displayAddress,
                    'latitude' => $venue->latitude,
                    'longitude' => $venue->longitude,
                    'url' => $venue->matchedCourtUrl ?: route('venues.show', $venue->routeIdentifier()),
                    'matched_court' => $venue->matchedCourtName,
                    'matched_court_details' => $venue->cardCourt(),
                    'court_count' => $venue->cardCourtCount(),
                    'free_courts' => $venue->cardPaymentCount('free'),
                    'paid_courts' => $venue->cardPaymentCount('paid'),
                    'approval_courts' => ($venue->matchedCourtUrl !== null ? (int) $venue->matchedRequiresApproval : count($venue->courtAccessSummary['approval'] ?? [])),
                ];
            }
        }

        $venues = array_slice($venues, ($page - 1) * $perPage, $perPage);
        $hasMore = $page * $perPage < $total;

        if (($filters['fragment'] ?? null) === 'items') {
            return response()->json([
                'html' => view('theme::pages.venues.partials.index-items', compact('venues', 'page'))->render(),
                'has_more' => $hasMore,
                'next_page' => $hasMore ? $page + 1 : null,
                'total' => $total,
            ])->header('Cache-Control', 'private, no-store');
        }

        return ThemeResolver::page('venues.index', compact(
            'venues', 'filters', 'total', 'page', 'hasMore', 'currentView', 'mapPoints',
        ) + [
            'types' => VenueTypeEnum::cases(),
            'operationalStatuses' => VenueOperationalStatusEnum::cases(),
            'myInterests' => $myInterests,
            'geographyCities' => City::query()->with(['districts' => fn ($query) => $query->orderBy('name')])->orderBy('name')->get(),
        ]);
    }

    public function search(
        Request $request,
        SearchVenuesHandler $searchVenues,
        CurrentActorResolver $actors,
    ): JsonResponse {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'venue_id' => ['nullable', 'integer', 'min:1'],
            'city' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', Rule::enum(VenueTypeEnum::class)],
            'status' => ['nullable', Rule::enum(VenueStatusEnum::class)],
            'metro_station_id' => ['nullable', 'integer', 'exists:metro_stations,id'],
            'requires_payment' => ['nullable', 'boolean'],
            'requires_booking_approval' => ['nullable', 'boolean'],
            'confirmed_only' => ['nullable', 'boolean'],
            'operational_status' => ['nullable', Rule::enum(VenueOperationalStatusEnum::class)],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:30', 'max:480', 'required_with:starts_at'],
            'booking_scope' => ['nullable', Rule::enum(VenueBookingScopeEnum::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $startsAt = isset($validated['starts_at'])
            ? CarbonImmutable::createFromFormat(
                'Y-m-d\TH:i',
                $validated['starts_at'],
                (string) config('app.timezone', 'Europe/Moscow'),
            )
            : null;

        $venues = $searchVenues->handle(
            user: $request->user(),
            actor: $actors->resolveForRequest($request),
            query: $validated['query'] ?? null,
            venueId: isset($validated['venue_id']) ? (int) $validated['venue_id'] : null,
            city: $validated['city'] ?? null,
            street: $validated['street'] ?? null,
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
            bookingScope: VenueBookingScopeEnum::from($validated['booking_scope'] ?? VenueBookingScopeEnum::WHOLE->value),
            limit: isset($validated['limit']) ? (int) $validated['limit'] : 20,
        );

        $searchVenueModels = Venue::query()->with(['characteristics', 'location.address', 'location.metroStations'])
            ->whereKey(collect($venues)->pluck('id'))->get()->keyBy('id');
        $hoopsByVenue = $searchVenueModels
            ->mapWithKeys(fn (Venue $venue): array => [$venue->id => (int) ($venue->characteristics?->hoops_count ?? 1)]);

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
                // Structured street for human-readable event titles; no address guessing on the client.
                'street' => $searchVenueModels->get($venue->id)?->location?->address?->street,
                'primary_metro' => $searchVenueModels->get($venue->id)?->location?->metroStations
                    ?->sortBy(fn ($station) => $station->pivot?->distance_meters ?? PHP_INT_MAX)
                    ->first()?->name,
                'requires_payment' => $venue->hasPaidCourt() && ! $venue->hasFreeCourt()
                    ? true : ($venue->hasFreeCourt() && ! $venue->hasPaidCourt() ? false : null),
                'requires_booking_approval' => $venue->hasApprovalCourt(),
                'has_free_access' => $venue->hasFreeAccess(),
                'has_free_court' => $venue->hasFreeCourt(),
                'has_paid_court' => $venue->hasPaidCourt(),
                'has_approval_court' => $venue->hasApprovalCourt(),
                'court_access' => $venue->courtAccess,
                'matched_court' => $venue->matchedCourt,
                'operational_status' => $venue->operationalStatus,
                'metro_stations' => $venue->metroStations,
                'tags' => $venue->tags,
                'latitude' => $venue->latitude,
                'longitude' => $venue->longitude,
                'url' => $venue->publicUrl(),
                'venue_url' => route('venues.show', $venue->routeIdentifier()),
                'preview_url' => route('venues.preview', $venue->routeIdentifier()),
                'hoops_count' => $hoopsByVenue->get($venue->id, 1),
            ])->all(),
        ]);
    }

    public function nearbyMap(
        Request $request,
        string $alias,
        FindNearbyVenuesHandler $nearby,
    ): JsonResponse {
        $params = $request->validate([
            'radius_km' => ['required', 'integer', 'between:1,10'],
        ]);
        $venue = Venue::query()->whereRouteIdentifier($alias)
            ->where('status', VenueStatusEnum::CONFIRMED)
            ->with('location.address')->firstOrFail();
        $canPreview = app(\App\Modules\Venue\Application\Services\VenueAccessResolver::class)->canManage(
            $request->user(), $venue,
            app(CurrentActorResolver::class)->resolveForRequest($request),
        );
        abort_unless($venue->is_published || $canPreview, 404);
        $address = $venue->location?->address;
        abort_unless($address?->latitude !== null && $address?->longitude !== null, 404);

        return response()->json([
            'venues' => $nearby->handle(
                $venue->id,
                (float) $address->latitude,
                (float) $address->longitude,
                50,
                (int) $params['radius_km'],
            ),
        ])->header('Cache-Control', 'public, max-age=60');
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

        // The venue preview powers the same map card as the homepage.
        // Match its representative-photo fallback, surface and amenity rules.
        $mapVenue = Venue::query()->with(['courts.media', 'media', 'amenities'])
            ->findOrFail($venue->id);
        $mapPhoto = $mapVenue->representativePhoto();
        $surfaces = $mapVenue->courts
            ->map(fn ($court): ?array => $court->surface_type instanceof VenueSurfaceTypeEnum
                ? ['value' => $court->surface_type->value, 'label' => $court->surface_type->label()]
                : null)
            ->filter()->unique('value')->values()->all();
        $amenities = $mapVenue->amenities->filter(fn ($amenity): bool => (bool) $amenity->is_active)
            ->pluck('name')->values()->all();

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
                'image_url' => $mapPhoto === null ? null : url($mapPhoto->publicUrl()),
                'surfaces' => $surfaces,
                'amenities' => $amenities,
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
        VenueGalleryManager $gallery,
    ): RedirectResponse|JsonResponse {
        try {
            $venue = $createVenue->handle(
                $actors->resolveForRequest($request),
                $request->validated(),
                $request->locationData(),
                $request->tagNames(),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('venues.create')
                ->withInput()
                ->with('error', $e->getMessage());
        }

        $photoWarning = null;
        if ($request->hasFile('photo')) {
            try {
                $contents = $request->file('photo')->get();
                $gallery->store($venue, $contents, $actors->resolveForRequest($request));
            } catch (\Throwable $exception) {
                report($exception);
                // The venue already exists: never return a failed creation and
                // invite a duplicate retry when just the optional photo failed.
                $photoWarning = 'Площадка создана, но фото не добавлено. Загрузите его при редактировании.';
            }
        }

        if ($request->expectsJson()) {
            $claim = VenueOwnershipClaim::query()->where('venue_id', $venue->id)
                ->where('applicant_user_id', $request->user()->canonical()->id)
                ->where('status', VenueOwnershipClaimStatusEnum::DRAFT)->first();

            return response()->json([
                'message' => 'Площадка создана. Проверьте данные и отправьте её на модерацию.',
                'warning' => $photoWarning,
                'venue' => $this->venuePayload($venue),
                'management_url' => $claim ? route('account.venue-ownership.show', $claim) : null,
                'ownership_claim' => $claim ? ['id' => $claim->public_id, 'status' => $claim->status->value] : null,
            ], 201);
        }

        return redirect()
            ->route('account.venues.edit', $venue->routeIdentifier())
            ->with('status', 'Площадка создана. Проверьте и дополните данные.')
            ->with('photo_warning', $photoWarning);
    }

    public function show(
        Request $request,
        string $alias,
        ShowVenueHandler $useCase,
        FindNearbyVenuesHandler $findNearbyVenues,
        CurrentActorResolver $actors,
        PageSeoResolver $pageSeo,
    ): Response|\Illuminate\Http\RedirectResponse {
        // Preserve historic numeric venue URLs when a duplicate is linked.
        // Never redirect ambiguous slug-only routes or unpublished canonicals.
        if (preg_match('/^(\d+)(?:-|$)/', $alias, $match) === 1) {
            $old = Venue::query()->whereKey((int) $match[1])
                ->whereNotNull('canonical_venue_id')->with('canonicalVenue')->first();
            if ($old?->canonicalVenue?->isPubliclyVisible()) {
                return redirect()->route('venues.show', $old->canonicalVenue->routeIdentifier(), 301);
            }
        }

        try {
            $venue = $useCase->handle($alias, $request->user(), $actors->resolveForRequest($request));
        } catch (\Exception $e) {
            $status = in_array((int) $e->getCode(), [403, 404], true)
                ? (int) $e->getCode() : 404;

            return ThemeResolver::page('venues.show', ['error' => [
                'message' => $e->getMessage(),
                'code' => $status,
            ]])->setStatusCode($status);
        }

        $nearbyVenues = $venue->address?->latitude !== null && $venue->address?->longitude !== null
            ? $findNearbyVenues->handle(
                $venue->id,
                (float) $venue->address->latitude,
                (float) $venue->address->longitude,
            )
            : [];

        $venueAddress = $venue->address?->display ?: $venue->rawAddress;
        $generatedDescription = 'Площадка «'.$venue->name.'»'
            .($venueAddress ? ' по адресу '.$venueAddress : '')
            .'. Характеристики, расписание, условия доступа и баскетбольные активности на MSKBA.';
        $seoDescription = $venue->shortDescription ?: $generatedDescription;

        return ThemeResolver::page('venues.show', [
            'venue' => $venue,
            'nearbyVenues' => $nearbyVenues,
            ...$pageSeo->resolve(
                SeoEntityTypeEnum::VENUE,
                $venue->id,
                $venue->name,
                $seoDescription,
                route('venues.show', $venue->id.'-'.$venue->alias),
            ),
        ]);
    }

    public function edit(
        Request $request,
        string $alias,
        ShowEditableVenueHandler $showEditableVenue,
        ListMetrostationsHandler $listMetrostations,
        CurrentActorResolver $actors,
        VenueGalleryManager $gallery,
    ): Response {
        $isApp = app(ThemeResolver::class)->active() === 'mskba_app';
        $editor = [];
        $tab = 'general';
        if ($isApp) {
            $venue = Venue::query()->whereRouteIdentifier($alias)->firstOrFail();
            $editor = app(AccountVenueEditorAccess::class)->capabilities($request->user(), $venue);
            abort_unless(in_array(true, $editor, true), 403);
            $tab = (string) $request->query('tab', 'general');
            abort_unless(in_array($tab, [
                'general', 'address', 'facilities', 'courts', 'schedule',
                'photos', 'contracts', 'rental',
            ], true), 404);
            $tabPermission = $tab === 'rental' ? ($editor['rental'] || $editor['offers'] || $editor['memberships'] || $editor['special_terms'])
                : $editor[$tab];
            abort_unless($tabPermission, 403);
        } else {
            try {
                $venue = $showEditableVenue->handle($alias, $request->user(), $actors->resolveForRequest($request));
            } catch (\Exception $e) {
                return ThemeResolver::page('venues.edit', ['venue' => null, 'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode() ?: 500,
                ]]);
            }
        }

        $venue->loadMissing(
            'location.address', 'location.metroStations', 'tags', 'amenities',
            'characteristics', 'schedule.intervals', 'schedule.exceptions.intervals',
            'courts', 'draftRevision', 'media',
        );

        return ThemeResolver::page('venues.edit', [
            'venue' => $venue,
            'editor' => $editor,
            'tab' => $tab,
            'amenities' => $isApp ? Amenity::query()->where('scope', 'venue')->where('is_active', true)->orderBy('sort_order')->get() : collect(),
            'ownershipDraft' => VenueOwnershipClaim::query()->where('venue_id', $venue->id)
                ->whereIn('applicant_user_id', $request->user()->canonical()->identityIds())
                ->where('status', VenueOwnershipClaimStatusEnum::DRAFT)->withCount('documents')->first(),
            'venueRevision' => $venue->draftRevision,
            'venuePhotos' => ! $isApp || $editor['photos'] ? $gallery->editableGallery($venue) : [],
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
        // App editor is contract-owned; being a superadmin alone is not a public editing grant.
        if (app(ThemeResolver::class)->active() === 'mskba_app') {
            $record = Venue::query()->whereRouteIdentifier($alias)->firstOrFail();
            abort_unless(app(AccountVenueEditorAccess::class)->capabilities($request->user(), $record)['general'], 403);
            // Conditions are edited through their own handler, not this passport editor.
            abort_if($request->hasAny(['access_type', 'requires_booking_approval', 'requires_booking']), 403);
        }
        $editorTab = in_array($request->input('editor_tab'), ['general', 'address', 'facilities'], true)
            ? (string) $request->input('editor_tab') : null;
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
                ->route('account.venues.edit', ($editorTab !== null ? ['alias' => $alias, 'tab' => $editorTab] : $alias))
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
            ->route('account.venues.edit', ($editorTab !== null ? ['alias' => $venue->routeIdentifier(), 'tab' => $editorTab] : $venue->routeIdentifier()))
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
        $isAccountEditor = app(ThemeResolver::class)->active() === 'mskba_app';
        if ($isAccountEditor) {
            $record = Venue::query()->whereRouteIdentifier($alias)->firstOrFail();
            abort_unless(app(AccountVenueEditorAccess::class)->capabilities($request->user(), $record)['general'], 403);
        }

        // The photos tab is inaccessible during pending moderation.
        $returnTab = in_array($request->input('editor_tab'), ['general', 'address', 'facilities'], true)
            ? (string) $request->input('editor_tab') : 'general';
        $returnRoute = $isAccountEditor
            ? ['alias' => $alias, 'tab' => $returnTab]
            : $alias;

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
                ->route($isAccountEditor ? 'account.venues.edit' : 'account.venues.status', $returnRoute)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Площадка отправлена на модерацию.']);
        }

        return redirect()
            ->route($isAccountEditor ? 'account.venues.edit' : 'account.venues.status', $returnRoute)
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
