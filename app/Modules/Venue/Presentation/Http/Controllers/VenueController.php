<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Location\Application\UseCases\ListMetrostationsHandler;
use App\Modules\Venue\Application\UseCases\CreateAccountVenueHandler;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Application\UseCases\ShowEditableVenueHandler;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Modules\Venue\Application\UseCases\SubmitVenueModerationRequestHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueHandler;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Presentation\Http\Requests\CreateVenueRequest;
use App\Modules\Venue\Presentation\Http\Requests\SubmitVenueModerationRequest;
use App\Modules\Venue\Presentation\Http\Requests\UpdateVenueRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VenueController extends Controller
{
    public function index(Request $request, ListVenuesHandler $useCase, CurrentActorResolver $actors): Response
    {
        $venues = $useCase->handle($request->user(), $actors->resolveForRequest($request));

        return ThemeResolver::page('venues.index', ['venues' => $venues]);
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
    ): RedirectResponse
    {
        try {
            $venue = $createVenue->handle(
                $actors->resolveForRequest($request),
                $request->validated(),
                $request->locationData(),
            );
        } catch (\Exception $e) {
            return redirect()
                ->route('venues.create')
                ->withInput()
                ->with('error', $e->getMessage());
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
    ): Response
    {
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
    ): Response
    {
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
    ): RedirectResponse {
        try {
            $venue = $updateVenue->handle(
                alias: $alias,
                user: $request->user(),
                actor: $actors->resolveForRequest($request),
                data: $request->validated(),
                locationData: $request->locationData(),
            );
        } catch (\Exception $e) {
            return redirect()
                ->route('venues.edit', $alias)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('venues.edit', $venue->alias)
            ->with('status', 'Площадка сохранена.');
    }

    public function submitModeration(
        SubmitVenueModerationRequest $request,
        string $alias,
        SubmitVenueModerationRequestHandler $submitModeration,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        try {
            $submitModeration->handle(
                alias: $alias,
                user: $request->user(),
                actor: $actors->resolveForRequest($request),
                message: $request->messageText(),
            );
        } catch (\Exception $e) {
            return redirect()
                ->route('venues.edit', $alias)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('venues.edit', $alias)
            ->with('status', 'Площадка отправлена на модерацию.');
    }

    public function remove(string $alias): Response
    {
        return ThemeResolver::page('venues.show', ['error' => [
            'message' => 'Удаление площадки будет реализовано отдельно.',
            'code' => 501,
        ]]);
    }
}
