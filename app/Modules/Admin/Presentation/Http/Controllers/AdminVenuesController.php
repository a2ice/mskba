<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminVenuesHandler;
use App\Modules\Admin\Presentation\Http\Requests\AdminUpdateVenueRequest;
use App\Modules\Admin\Presentation\Http\Requests\ReviewModerationRequest;
use App\Modules\Admin\Presentation\Http\Requests\UpdateVenueStatusRequest;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Location\Application\UseCases\ListMetrostationsHandler;
use App\Modules\Moderation\Domain\Models\ModerationRequest;
use App\Modules\Venue\Application\Services\VenueGalleryManager;
use App\Modules\Venue\Application\UseCases\AdminUpdateVenueHandler;
use App\Modules\Venue\Application\UseCases\BulkChangeVenueDeletionStateHandler;
use App\Modules\Venue\Application\UseCases\BulkUpdateVenueStatusHandler;
use App\Modules\Venue\Application\UseCases\DeleteVenueHandler;
use App\Modules\Venue\Application\UseCases\RestoreVenueHandler;
use App\Modules\Venue\Application\UseCases\ReviewModerationRequestHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueStatusHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Presentation\Http\Requests\StoreVenuePhotoRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
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

    public function edit(Venue $venue, ListMetrostationsHandler $listMetrostations, VenueGalleryManager $gallery): Response
    {
        $venue->loadMissing('location.address', 'location.metroStations', 'tags');

        return ThemeResolver::page('admin.venue-edit', [
            'venue' => $venue,
            'types' => VenueTypeEnum::cases(),
            'metros' => $listMetrostations->handle(),
            'venuePhotos' => $gallery->editableGallery($venue, forcePublished: true),
        ]);
    }

    public function storePhoto(StoreVenuePhotoRequest $request, Venue $venue, VenueGalleryManager $gallery): RedirectResponse
    {
        $file = $request->file('photo');
        $path = $file?->getRealPath();
        $contents = is_string($path) ? file_get_contents($path) : false;
        if (! is_string($contents)) {
            return back()->with('photo_error', 'Не удалось прочитать изображение.');
        }
        try {
            $gallery->store($venue, $contents, forcePublished: true);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return back()->with('photo_error', $exception->getMessage());
        }

        return back()->with('photo_status', 'Фотография добавлена.');
    }

    public function activatePhoto(Venue $venue, int $photo, VenueGalleryManager $gallery): RedirectResponse
    {
        $gallery->activate($venue, $photo, forcePublished: true);

        return back()->with('photo_status', 'Основная фотография изменена.');
    }

    public function destroyPhoto(Venue $venue, int $photo, VenueGalleryManager $gallery): RedirectResponse
    {
        $gallery->delete($venue, $photo, forcePublished: true);

        return back()->with('photo_status', 'Фотография удалена.');
    }

    public function update(
        AdminUpdateVenueRequest $request,
        Venue $venue,
        AdminUpdateVenueHandler $updateVenue,
    ): RedirectResponse {
        try {
            $updateVenue->handle(
                user: $request->user(),
                venueId: $venue->id,
                data: $request->validated(),
                locationData: $request->locationData(),
                tagNames: $request->tagNames(),
            );
        } catch (\Exception $e) {
            return redirect()
                ->route('admin.venues.edit', $venue)
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.venues.edit', $venue)
            ->with('success', 'Площадка сохранена.');
    }

    public function approve(
        ReviewModerationRequest $request,
        ModerationRequest $moderationRequest,
        ReviewModerationRequestHandler $review,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        try {
            $review->approve(
                $moderationRequest,
                $request->user(),
                $request->messageText(),
                $actors->resolveForRequest($request),
            );
        } catch (\Exception $e) {
            return redirect()->route('admin.venues')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.venues')->with('success', 'Площадка подтверждена.');
    }

    public function reject(
        ReviewModerationRequest $request,
        ModerationRequest $moderationRequest,
        ReviewModerationRequestHandler $review,
        CurrentActorResolver $actors,
    ): RedirectResponse {
        try {
            $review->reject($moderationRequest, $request->user(), $request->messageText(), $actors->resolveForRequest($request));
        } catch (\Exception $e) {
            return redirect()->route('admin.venues')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.venues')->with('success', 'Заявка модерации отклонена.');
    }

    public function updateStatus(
        UpdateVenueStatusRequest $request,
        Venue $venue,
        UpdateVenueStatusHandler $statuses,
    ): RedirectResponse {
        try {
            $statuses->handle($venue, $request->statusEnum(), $request->messageText());
        } catch (\Exception $e) {
            return redirect()->route('admin.venues')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.venues')->with('success', 'Статус площадки обновлен.');
    }

    public function destroy(Venue $venue, DeleteVenueHandler $deleteVenue): RedirectResponse
    {
        try {
            $deleteVenue->handle($venue);
        } catch (\Exception $e) {
            return redirect()->route('admin.venues')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.venues')->with('success', 'Площадка удалена.');
    }

    public function restore(int $venueId, RestoreVenueHandler $restoreVenue): RedirectResponse
    {
        try {
            $restoreVenue->handle($venueId);
        } catch (\Exception $e) {
            return redirect()->route('admin.venues', ['deleted' => 1])->with('error', $e->getMessage());
        }

        return redirect()->route('admin.venues', ['deleted' => 1])->with('success', 'Площадка восстановлена.');
    }

    public function bulkDelete(Request $request, BulkChangeVenueDeletionStateHandler $deletionState): RedirectResponse
    {
        $venueIds = $this->validatedVenueIds($request);
        $count = $deletionState->delete($venueIds);

        return redirect()->route('admin.venues')->with('success', "Удалено площадок: {$count}.");
    }

    public function bulkRestore(Request $request, BulkChangeVenueDeletionStateHandler $deletionState): RedirectResponse
    {
        $venueIds = $this->validatedVenueIds($request);
        $count = $deletionState->restore($venueIds);

        return redirect()->route('admin.venues', ['deleted' => 1])->with('success', "Восстановлено площадок: {$count}.");
    }

    public function bulkBlock(Request $request, BulkUpdateVenueStatusHandler $statuses): RedirectResponse
    {
        $venueIds = $this->validatedVenueIds($request);
        $validated = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $count = $statuses->handle($venueIds, VenueStatusEnum::BLOCKED, $validated['message']);

        return redirect()->route('admin.venues')->with('success', "Заблокировано площадок: {$count}.");
    }

    public function bulkUnblock(Request $request, BulkUpdateVenueStatusHandler $statuses): RedirectResponse
    {
        $venueIds = $this->validatedVenueIds($request);
        $count = $statuses->handle($venueIds, VenueStatusEnum::UNCONFIRMED);

        return redirect()->route('admin.venues')->with('success', "Разблокировано площадок: {$count}.");
    }

    /**
     * @return array<int>
     */
    private function validatedVenueIds(Request $request): array
    {
        $validated = $request->validate([
            'venue_ids' => ['required', 'array', 'min:1', 'max:100'],
            'venue_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        return array_map('intval', $validated['venue_ids']);
    }
}
