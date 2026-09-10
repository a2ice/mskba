<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueCourtGalleryManager;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\Venue\Presentation\Http\Requests\StoreVenuePhotoRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class VenueCourtPhotoController extends Controller
{
    public function store(
        StoreVenuePhotoRequest $request,
        string $venue,
        string $court,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
        VenueCourtGalleryManager $gallery,
    ): RedirectResponse {
        $venueModel = $this->managedVenue($request, $venue, $actors, $access);
        $courtModel = $this->courtForVenue($venueModel, $court);
        $file = $request->file('photo');
        $path = $file?->getRealPath();
        $contents = is_string($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            return back()->with('court_photo_error', [
                'court_id' => $courtModel->id,
                'message' => 'Не удалось прочитать изображение.',
            ]);
        }

        try {
            $gallery->store($courtModel, $contents);
        } catch (\InvalidArgumentException|RuntimeException $exception) {
            return back()->with('court_photo_error', [
                'court_id' => $courtModel->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return back()->with('court_photo_status', [
            'court_id' => $courtModel->id,
            'message' => 'Фотография зала добавлена.',
        ]);
    }

    public function activate(
        Request $request,
        string $venue,
        string $court,
        int $photo,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
        VenueCourtGalleryManager $gallery,
    ): RedirectResponse {
        $venueModel = $this->managedVenue($request, $venue, $actors, $access);
        $courtModel = $this->courtForVenue($venueModel, $court);
        $gallery->activate($courtModel, $photo);

        return back()->with('court_photo_status', [
            'court_id' => $courtModel->id,
            'message' => 'Основная фотография зала изменена.',
        ]);
    }

    public function destroy(
        Request $request,
        string $venue,
        string $court,
        int $photo,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
        VenueCourtGalleryManager $gallery,
    ): RedirectResponse {
        $venueModel = $this->managedVenue($request, $venue, $actors, $access);
        $courtModel = $this->courtForVenue($venueModel, $court);
        $gallery->delete($courtModel, $photo);

        return back()->with('court_photo_status', [
            'court_id' => $courtModel->id,
            'message' => 'Фотография зала удалена.',
        ]);
    }

    private function managedVenue(
        Request $request,
        string $identifier,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
    ): Venue {
        $venue = Venue::query()
            ->with('creatorActor')
            ->whereRouteIdentifier($identifier)
            ->firstOrFail();
        $actor = $actors->resolveForRequest($request);

        abort_unless($access->canManage($request->user(), $venue, $actor), 403);

        return $venue;
    }

    private function courtForVenue(Venue $venue, string $identifier): VenueCourt
    {
        return VenueCourt::query()
            ->where('venue_id', $venue->id)
            ->whereRouteIdentifier($identifier)
            ->firstOrFail();
    }
}
