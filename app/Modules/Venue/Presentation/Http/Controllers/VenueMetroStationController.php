<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Application\Services\AccountVenueEditorAccess;
use App\Modules\Venue\Application\Services\VenueMetroStationEditor;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class VenueMetroStationController extends Controller
{
    public function update(
        Request $request,
        string $alias,
        CurrentActorResolver $actors,
        AccountVenueEditorAccess $access,
        VenueMetroStationEditor $editor,
    ): RedirectResponse {
        $venue = Venue::query()->whereRouteIdentifier($alias)->firstOrFail();
        abort_unless($access->capabilities($request->user(), $venue)['address'], 403);

        $validated = $request->validate([
            'metro_station_ids' => ['sometimes', 'array', 'max:40'],
            'metro_station_ids.*' => ['required', 'integer', 'distinct', 'exists:metro_stations,id'],
        ]);
        $ids = collect($validated['metro_station_ids'] ?? [])
            ->map(static fn ($id): int => (int) $id)->values()->all();

        $editor->update($venue, $actors->resolveForRequest($request), $ids);

        return redirect()->route('account.venues.edit', [
            'alias' => $venue->routeIdentifier(), 'tab' => 'address',
        ])->with('status', $venue->status === VenueStatusEnum::CONFIRMED
            ? 'Станции метро сохранены в черновик. Отправьте изменения на модерацию.'
            : 'Станции метро сохранены.');
    }
}
