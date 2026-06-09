<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Venue\Application\UseCases\CreateAccountVenueHandler;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Presentation\Http\Requests\CreateVenueRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class VenueController extends Controller
{
    public function index(ListVenuesHandler $useCase): Response
    {
        $user = request()->user();
        $venues = $useCase->handle($user);

        return ThemeResolver::page('venues.index', ['venues' => $venues]);
    }

    public function create(): Response
    {
        return ThemeResolver::page('venues.create', [
            'types' => VenueTypeEnum::cases(),
        ]);
    }

    public function store(CreateVenueRequest $request, CreateAccountVenueHandler $createVenue): RedirectResponse
    {
        try {
            $venue = $createVenue->handle($request->user(), $request->validated());
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

    public function show(string $alias, ShowVenueHandler $useCase): Response
    {
        $user = request()->user();

        try {
            $venue = $useCase->handle($alias, $user);
        } catch (\Exception $e) {
            return ThemeResolver::page('venues.show', ['error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
            ]]);
        }

        return ThemeResolver::page('venues.show', ['venue' => $venue]);
    }

    public function edit(string $alias): Response
    {
        return ThemeResolver::page('venues.show', ['error' => [
            'message' => 'Редактирование площадки будет реализовано отдельно.',
            'code' => 501,
        ]]);
    }

    public function remove(string $alias): Response
    {
        return ThemeResolver::page('venues.show', ['error' => [
            'message' => 'Удаление площадки будет реализовано отдельно.',
            'code' => 501,
        ]]);
    }
}
