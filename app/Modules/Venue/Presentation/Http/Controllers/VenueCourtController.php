<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class VenueCourtController extends Controller
{
    public function index(
        Request $request,
        string $venue,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
    ): Response {
        $venueModel = $this->managedVenue($request, $venue, $actors, $access);
        $venueModel->load('courts');

        return ThemeResolver::page('venues.courts', [
            'venue' => $venueModel,
            'courts' => $venueModel->courts,
        ]);
    }

    public function store(
        Request $request,
        string $venue,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
    ): RedirectResponse {
        $venueModel = $this->managedVenue($request, $venue, $actors, $access);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'alias' => ['nullable', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'supports_halves' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $venueModel, $validated): void {
            Venue::query()->whereKey($venueModel->id)->lockForUpdate()->firstOrFail();
            $hasCourt = VenueCourt::query()->where('venue_id', $venueModel->id)->lockForUpdate()->exists();
            $nextOrder = (int) VenueCourt::query()->where('venue_id', $venueModel->id)->max('sort_order') + 10;

            VenueCourt::query()->create([
                'venue_id' => $venueModel->id,
                'name' => trim($validated['name']),
                'alias' => $this->uniqueAlias(
                    $venueModel,
                    $validated['alias'] ?? null,
                    $validated['name'],
                ),
                'sort_order' => isset($validated['sort_order']) ? (int) $validated['sort_order'] : $nextOrder,
                'is_primary' => ! $hasCourt,
                'supports_halves' => $request->boolean('supports_halves'),
            ]);
        });

        return redirect()
            ->route('account.venues.courts.index', $venueModel->routeIdentifier())
            ->with('status', 'Зал добавлен.');
    }

    public function update(
        Request $request,
        string $venue,
        string $court,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
    ): RedirectResponse {
        $venueModel = $this->managedVenue($request, $venue, $actors, $access);
        $courtModel = $this->courtForVenue($venueModel, $court);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'alias' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('venue_courts', 'alias')
                    ->where(fn ($query) => $query->where('venue_id', $venueModel->id))
                    ->ignore($courtModel->id),
            ],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'supports_halves' => ['nullable', 'boolean'],
        ]);

        $courtModel->update([
            'name' => trim($validated['name']),
            'alias' => $this->uniqueAlias(
                $venueModel,
                $validated['alias'] ?? null,
                $validated['name'],
                $courtModel->id,
            ),
            'sort_order' => (int) $validated['sort_order'],
            'supports_halves' => $request->boolean('supports_halves'),
        ]);

        return redirect()
            ->route('account.venues.courts.index', $venueModel->routeIdentifier())
            ->with('status', 'Зал обновлён.');
    }

    public function makePrimary(
        Request $request,
        string $venue,
        string $court,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
    ): RedirectResponse {
        $venueModel = $this->managedVenue($request, $venue, $actors, $access);
        $courtModel = $this->courtForVenue($venueModel, $court);

        DB::transaction(function () use ($venueModel, $courtModel): void {
            Venue::query()->whereKey($venueModel->id)->lockForUpdate()->firstOrFail();
            VenueCourt::query()
                ->where('venue_id', $venueModel->id)
                ->where('is_primary', true)
                ->whereKeyNot($courtModel->id)
                ->update(['is_primary' => false]);
            $courtModel->forceFill(['is_primary' => true])->save();
        });

        return redirect()
            ->route('account.venues.courts.index', $venueModel->routeIdentifier())
            ->with('status', 'Основной зал изменён.');
    }

    public function destroy(
        Request $request,
        string $venue,
        string $court,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
    ): RedirectResponse {
        $venueModel = $this->managedVenue($request, $venue, $actors, $access);
        $courtModel = $this->courtForVenue($venueModel, $court);

        try {
            DB::transaction(function () use ($venueModel, $courtModel): void {
                Venue::query()->whereKey($venueModel->id)->lockForUpdate()->firstOrFail();
                $courts = VenueCourt::query()
                    ->where('venue_id', $venueModel->id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($courts->count() <= 1) {
                    throw new \DomainException('У площадки должен оставаться хотя бы один зал.');
                }

                $wasPrimary = (bool) $courtModel->is_primary;
                $courtModel->delete();

                if ($wasPrimary) {
                    $replacement = $courts->first(fn (VenueCourt $item): bool => $item->id !== $courtModel->id);
                    $replacement?->forceFill(['is_primary' => true])->save();
                }
            });
        } catch (\DomainException $exception) {
            return redirect()
                ->route('account.venues.courts.index', $venueModel->routeIdentifier())
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('account.venues.courts.index', $venueModel->routeIdentifier())
            ->with('status', 'Зал удалён.');
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

    private function uniqueAlias(
        Venue $venue,
        ?string $requestedAlias,
        string $name,
        ?int $ignoreCourtId = null,
    ): string {
        $base = Str::slug(trim((string) ($requestedAlias ?: $name)));
        if ($base === '') {
            $base = 'court';
        }

        $candidate = $base;
        $suffix = 2;
        while (VenueCourt::withTrashed()
            ->where('venue_id', $venue->id)
            ->where('alias', $candidate)
            ->when($ignoreCourtId !== null, fn ($query) => $query->whereKeyNot($ignoreCourtId))
            ->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
