<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Response;

class VenueController extends Controller
{
    public function index(ListVenuesHandler $useCase): Response
    {
        $user = request()->user();
        $venues = $useCase->handle($user);

        return ThemeResolver::page('venues.index', ['venues' => $venues]);
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
}
