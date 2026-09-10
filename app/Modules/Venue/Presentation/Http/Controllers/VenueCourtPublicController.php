<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Content\Application\Services\PageSeoResolver;
use App\Modules\Content\Domain\Enums\SeoEntityTypeEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Application\UseCases\ShowVenueHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class VenueCourtPublicController extends Controller
{
    public function __invoke(
        Request $request,
        string $venue,
        string $court,
        ShowVenueHandler $showVenue,
        CurrentActorResolver $actors,
        PageSeoResolver $pageSeo,
    ): Response|RedirectResponse {
        try {
            $details = $showVenue->handle(
                $venue,
                $request->user(),
                $actors->resolveForRequest($request),
                $court,
            );
        } catch (\Exception $exception) {
            return ThemeResolver::page('venues.show', ['error' => [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ]]);
        }

        if ($details->selectedCourt['isPrimary']) {
            return redirect()->route('venues.show', $details->routeIdentifier(), 301);
        }

        $canonicalUrl = route('venues.courts.show', [
            $details->routeIdentifier(),
            $details->selectedCourt['routeIdentifier'],
        ]);

        return ThemeResolver::page('venues.show', [
            'venue' => $details,
            ...$pageSeo->resolve(
                SeoEntityTypeEnum::VENUE,
                $details->id,
                $details->name.' · '.$details->selectedCourt['name'],
                $details->shortDescription,
                $canonicalUrl,
            ),
        ]);
    }
}
