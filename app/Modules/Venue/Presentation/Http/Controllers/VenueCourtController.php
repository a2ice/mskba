<?php

namespace App\Modules\Venue\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Event\Domain\Enums\VenueBookingScopeEnum;
use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Venue\Application\Services\VenueAccessResolver;
use App\Modules\Venue\Application\Services\VenueCourtGalleryManager;
use App\Modules\Venue\Domain\Enums\VenueSurfaceTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueCourt;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VenueCourtController extends Controller
{
    public function index(
        Request $request,
        string $venue,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
        VenueCourtGalleryManager $gallery,
    ): Response {
        $venueModel = $this->managedVenue($request, $venue, $actors, $access);
        $venueModel->load(['courts', 'characteristics']);
        $policy = $this->activePolicy($venueModel);
        $parentHoops = (int) ($venueModel->characteristics?->hoops_count ?? 1);
        $parentHoops = in_array($parentHoops, [1, 2], true) ? $parentHoops : 1;
        $courtPhotos = $venueModel->courts
            ->mapWithKeys(fn (VenueCourt $court): array => [$court->id => $gallery->gallery($court)])
            ->all();

        return ThemeResolver::page('venues.courts', [
            'venue' => $venueModel,
            'courts' => $venueModel->courts,
            'surfaceTypes' => VenueSurfaceTypeEnum::cases(),
            'courtPhotos' => $courtPhotos,
            'courtDefaults' => [
                'hoops_count' => $parentHoops,
                'allows_whole' => $policy?->allows_whole ?? true,
                'allows_halves' => $parentHoops >= 2 && ($policy?->allows_halves ?? false),
            ],
        ]);
    }

    public function store(
        Request $request,
        string $venue,
        CurrentActorResolver $actors,
        VenueAccessResolver $access,
    ): RedirectResponse {
        $venueModel = $this->managedVenue($request, $venue, $actors, $access);
        $validated = $request->validate($this->courtRules());
        $policy = $this->activePolicy($venueModel);
        $parentHoops = (int) ($venueModel->characteristics()->value('hoops_count') ?? 1);
        $hoopsCount = isset($validated['hoops_count'])
            ? (int) $validated['hoops_count']
            : (in_array($parentHoops, [1, 2], true) ? $parentHoops : 1);
        $supportsHalves = $hoopsCount >= 2;
        $allowsWhole = array_key_exists('allows_whole', $validated)
            ? $request->boolean('allows_whole')
            : ($policy?->allows_whole ?? true);
        $allowsHalves = $supportsHalves && (array_key_exists('allows_halves', $validated)
            ? $request->boolean('allows_halves')
            : ($policy?->allows_halves ?? false));

        DB::transaction(function () use ($venueModel, $validated, $hoopsCount, $supportsHalves, $allowsWhole, $allowsHalves): void {
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
                'hoops_count' => $hoopsCount,
                'surface_type' => $validated['surface_type'] ?? null,
                'supports_halves' => $supportsHalves,
                'allows_whole' => $allowsWhole,
                'allows_halves' => $allowsHalves,
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
        $rules = $this->courtRules($venueModel, $courtModel);
        $rules['sort_order'] = ['required', 'integer', 'min:0', 'max:65535'];
        $validated = $request->validate($rules);
        DB::transaction(function () use ($venueModel, $courtModel, $validated, $request): void {
            Venue::query()->whereKey($venueModel->id)->lockForUpdate()->firstOrFail();
            $lockedCourt = VenueCourt::query()
                ->where('venue_id', $venueModel->id)
                ->whereKey($courtModel->id)
                ->lockForUpdate()
                ->firstOrFail();
            $hoopsCount = (int) ($validated['hoops_count'] ?? $lockedCourt->hoops_count ?? 1);
            $supportsHalves = $hoopsCount >= 2;

            if ($lockedCourt->supports_halves && ! $supportsHalves) {
                $this->assertNoFutureHalfBookings($venueModel, $lockedCourt);
            }

            $lockedCourt->update([
                'name' => trim($validated['name']),
                'alias' => $this->uniqueAlias(
                    $venueModel,
                    $validated['alias'] ?? null,
                    $validated['name'],
                    $lockedCourt->id,
                ),
                'sort_order' => (int) $validated['sort_order'],
                'hoops_count' => $hoopsCount,
                'surface_type' => $validated['surface_type'] ?? null,
                'supports_halves' => $supportsHalves,
                'allows_whole' => $request->boolean('allows_whole'),
                'allows_halves' => $supportsHalves && $request->boolean('allows_halves'),
            ]);
        });

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

                if ($this->courtHasReferences($courtModel)) {
                    throw new \DomainException('Нельзя удалить зал, пока с ним связаны бронирования или мероприятия.');
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

    /** @return array<string, mixed> */
    private function courtRules(?Venue $venue = null, ?VenueCourt $court = null): array
    {
        $aliasRules = ['nullable', 'string', 'max:120'];
        if ($venue !== null && $court !== null) {
            $aliasRules[] = Rule::unique('venue_courts', 'alias')
                ->where(fn ($query) => $query->where('venue_id', $venue->id))
                ->ignore($court->id);
        }

        return [
            'name' => ['required', 'string', 'max:120'],
            'alias' => $aliasRules,
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'hoops_count' => ['nullable', 'integer', Rule::in([1, 2])],
            'surface_type' => ['nullable', Rule::enum(VenueSurfaceTypeEnum::class)],
            'allows_whole' => ['nullable', 'boolean'],
            'allows_halves' => ['nullable', 'boolean'],
        ];
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

    private function activePolicy(Venue $venue): ?VenueBookingPolicy
    {
        return VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)
            ->where('active_marker', true)
            ->first();
    }

    private function assertNoFutureHalfBookings(Venue $venue, VenueCourt $court): void
    {
        $hasFutureHalfBooking = DB::table('venue_bookings')
            ->where('venue_id', $venue->id)
            ->where('venue_court_id', $court->id)
            ->whereIn('scope', [VenueBookingScopeEnum::HALF_A->value, VenueBookingScopeEnum::HALF_B->value])
            ->whereIn('status', VenueBookingStatusEnum::occupyingValues())
            ->where('ends_at', '>', now())
            ->exists();

        if ($hasFutureHalfBooking) {
            throw ValidationException::withMessages([
                'hoops_count' => 'Нельзя уменьшить количество колец: у этого зала есть будущие бронирования отдельных половин.',
            ]);
        }
    }

    private function courtHasReferences(VenueCourt $court): bool
    {
        return DB::table('venue_booking_quotes')->where('venue_court_id', $court->id)->exists()
            || DB::table('venue_bookings')->where('venue_court_id', $court->id)->exists()
            || DB::table('events')->where('venue_court_id', $court->id)->exists();
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
