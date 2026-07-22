<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Application\Services\VenueGalleryManager;
use App\Modules\Venue\Application\UseCases\ShowEditableVenueHandler;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Presentation\Http\Requests\StoreVenuePhotoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class VenuePhotoController extends Controller
{
    public function store(StoreVenuePhotoRequest $request, string $alias, ShowEditableVenueHandler $venues, CurrentActorResolver $actors, VenueGalleryManager $gallery): RedirectResponse|JsonResponse
    {
        try {
            $venue = $venues->handle($alias, $request->user(), $actors->resolveForRequest($request));
        } catch (\Exception $exception) {
            abort($exception->getCode() ?: 404, $exception->getMessage());
        }
        $file = $request->file('photo');
        $path = $file?->getRealPath();
        $contents = is_string($path) ? file_get_contents($path) : false;
        if (! is_string($contents)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Не удалось прочитать изображение.'], 422)
                : back()->with('photo_error', 'Не удалось прочитать изображение.');
        }
        try {
            $gallery->store($venue, $contents, $actors->resolveForRequest($request));
        } catch (\InvalidArgumentException|RuntimeException $exception) {
            return $request->expectsJson()
                ? response()->json(['message' => $exception->getMessage()], 422)
                : back()->with('photo_error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json($this->galleryPayload($venue, $gallery, 'Фотография добавлена.'));
        }

        return back()->with('photo_status', 'Фотография добавлена.');
    }

    public function activate(Request $request, string $alias, int $photo, ShowEditableVenueHandler $venues, CurrentActorResolver $actors, VenueGalleryManager $gallery): RedirectResponse|JsonResponse
    {
        $actor = $actors->resolveForRequest($request);
        try {
            $venue = $venues->handle($alias, $request->user(), $actor);
        } catch (\Exception $exception) {
            abort($exception->getCode() ?: 404, $exception->getMessage());
        }
        $gallery->activate($venue, $photo, $actor);

        if ($request->expectsJson()) {
            return response()->json($this->galleryPayload($venue, $gallery, 'Основная фотография изменена.'));
        }

        return back()->with('photo_status', 'Основная фотография изменена.');
    }

    public function destroy(Request $request, string $alias, int $photo, ShowEditableVenueHandler $venues, CurrentActorResolver $actors, VenueGalleryManager $gallery): RedirectResponse|JsonResponse
    {
        $actor = $actors->resolveForRequest($request);
        try {
            $venue = $venues->handle($alias, $request->user(), $actor);
        } catch (\Exception $exception) {
            abort($exception->getCode() ?: 404, $exception->getMessage());
        }
        $gallery->delete($venue, $photo, $actor);

        if ($request->expectsJson()) {
            return response()->json($this->galleryPayload($venue, $gallery, 'Фотография удалена.'));
        }

        return back()->with('photo_status', 'Фотография удалена.');
    }

    /** @return array<string, mixed> */
    private function galleryPayload(Venue $venue, VenueGalleryManager $gallery, string $message): array
    {
        return [
            'message' => $message,
            'photos' => collect($gallery->editableGallery($venue))->map(fn (array $photo): array => $photo + [
                'activate_url' => route('account.venues.photos.activate', [$venue->routeIdentifier(), $photo['id']]),
                'delete_url' => route('account.venues.photos.destroy', [$venue->routeIdentifier(), $photo['id']]),
            ])->all(),
        ];
    }
}
