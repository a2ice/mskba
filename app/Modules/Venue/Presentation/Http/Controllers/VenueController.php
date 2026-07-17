<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Location\Application\UseCases\ListMetrostationsHandler;
use App\Modules\Venue\Application\UseCases\CreateAccountVenueHandler;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Application\UseCases\SearchVenuesHandler;
use App\Modules\Venue\Application\UseCases\ShowEditableVenueHandler;
use App\Modules\Venue\Application\UseCases\ShowManageableVenueHandler;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Modules\Venue\Application\UseCases\SubmitModerationRequestHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueHandler;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Presentation\Http\Requests\CreateVenueRequest;
use App\Modules\Venue\Presentation\Http\Requests\SubmitModerationRequest;
use App\Modules\Venue\Presentation\Http\Requests\UpdateVenueRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class VenueController extends Controller
{
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
            'metro_station_id' => ['nullable', 'integer', 'exists:metro_stations,id'],
            'requires_payment' => ['nullable', 'boolean'],
            'requires_booking_approval' => ['nullable', 'boolean'],
        ]);

        $venues = $searchVenues->handle(
            user: $request->user(),
            actor: $actors->resolveForRequest($request),
            query: $validated['query'] ?? null,
            type: isset($validated['type']) ? VenueTypeEnum::from($validated['type']) : null,
            metroStationId: isset($validated['metro_station_id']) ? (int) $validated['metro_station_id'] : null,
            requiresPayment: isset($validated['requires_payment']) ? (bool) $validated['requires_payment'] : null,
            requiresBookingApproval: isset($validated['requires_booking_approval']) ? (bool) $validated['requires_booking_approval'] : null,
        );

        return response()->json([
            'venues' => collect($venues)->map(fn ($venue): array => [
                'id' => $venue->id,
                'name' => $venue->name,
                'type' => $venue->type,
                'description' => $venue->shortDescription,
                'address' => $venue->rawAddress,
                'requires_payment' => $venue->requiresPayment,
                'requires_booking_approval' => $venue->requiresBookingApproval,
                'has_free_access' => $venue->hasFreeAccess(),
                'url' => route('venues.show', $venue->alias),
            ])->all(),
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
            ->route('venues.show', $venue->alias)
            ->with('status', 'Площадка добавлена и ожидает подтверждения.');
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
                ->route('venues.edit', $alias)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Площадка сохранена.',
                'venue' => $this->venuePayload($venue),
            ]);
        }

        return redirect()
            ->route('venues.edit', $venue->alias)
            ->with('status', 'Площадка сохранена.');
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
                ->route('venues.status', $alias)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Площадка отправлена на модерацию.']);
        }

        return redirect()
            ->route('venues.status', $alias)
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
    private function venuePayload(Venue $venue): array
    {
        $venue->loadMissing('location.address', 'location.metroStations.line', 'tags');

        return [
            'alias' => $venue->alias,
            'name' => $venue->name,
            'type' => $venue->type->value,
            'requires_payment' => $venue->requires_payment,
            'requires_booking_approval' => $venue->requires_booking_approval,
            'has_free_access' => $venue->hasFreeAccess(),
            'short_description' => $venue->short_description,
            'full_description' => $venue->full_description,
            'tags' => $venue->tags->pluck('name')->values()->all(),
            'address' => $venue->location?->address?->full_address ?? $venue->raw_address,
            'metro' => collect($venue->location?->metroStations ?? [])
                ->map(fn ($station): array => [
                    'id' => $station->id,
                    'label' => $station->name.($station->line?->name ? ' ('.$station->line->name.')' : ''),
                ])
                ->values()
                ->all(),
            'update_url' => route('venues.update', $venue->alias),
            'moderation_url' => route('venues.moderation.submit', $venue->alias),
        ];
    }
}
