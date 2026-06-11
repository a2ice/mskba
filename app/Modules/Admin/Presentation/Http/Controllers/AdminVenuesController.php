<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminVenuesHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminVenuesController extends Controller
{
    public function index(Request $request, ListAdminVenuesHandler $venues): Response
    {
        return ThemeResolver::page('admin.venues', [
            'venues' => $venues->handle($request->query()),
            'filters' => $request->query(),
            'statuses' => VenueStatusEnum::cases(),
            'types' => VenueTypeEnum::cases(),
        ]);
    }
}
