<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Venue\Application\UseCases\ListVenues;
use App\Modules\Venue\Application\UseCases\ShowVenue;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\View\View;
use Illuminate\Http\Response;

class VenueController extends Controller
{
    public function index(ListVenues $useCase): Response
    {
        $user = request()->user();
        $venues = $useCase->handle($user);

        return ThemeResolver::page('venues.index', ['venues' => $venues]);
    }

    public function show(string $alias, ShowVenue $useCase): Response
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
}
