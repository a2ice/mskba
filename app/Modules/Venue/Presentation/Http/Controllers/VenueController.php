<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\View\View;
use App\Modules\Venue\Application\UseCases\ListVenues;

class VenueController extends Controller
{
    public function index(ListVenues $useCase): View
    {
        $user = request()->user();
        $venues = $useCase->handle($user);

        return ThemeResolver::page('venues.index', ['venues' => $venues]);
    }

    public function show(string $alias): View
    {
        return ThemeResolver::page('venues.show', ['alias' => $alias]);
    }
}
