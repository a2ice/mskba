<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Application\UseCases\ListAdminVenueDuplicatesHandler;
use App\Modules\Venue\Application\UseCases\MergeVenueDuplicateHandler;
use App\Modules\Venue\Domain\Enums\VenueDuplicateStatusEnum;
use App\Modules\Venue\Domain\Models\VenueDuplicate;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AdminVenueDuplicatesController extends Controller
{
    public function index(Request $request, ListAdminVenueDuplicatesHandler $duplicates): Response
    {
        return ThemeResolver::page('admin.venue-duplicates', [
            'duplicates' => $duplicates->handle($request->query()),
            'filters' => $request->query(),
            'statuses' => VenueDuplicateStatusEnum::cases(),
        ]);
    }

    public function merge(
        Request $request,
        VenueDuplicate $venueDuplicate,
        MergeVenueDuplicateHandler $mergeVenueDuplicate,
    ): RedirectResponse {
        $validated = $request->validate([
            'canonical_venue_id' => ['required', 'integer'],
            'duplicate_venue_id' => ['required', 'integer', 'different:canonical_venue_id'],
        ]);

        try {
            $mergeVenueDuplicate->handle(
                candidate: $venueDuplicate,
                canonicalVenueId: (int) $validated['canonical_venue_id'],
                duplicateVenueId: (int) $validated['duplicate_venue_id'],
                resolvedBy: $request->user(),
            );
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.venues.duplicates')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.venues.duplicates')
            ->with('success', 'Площадки объединены.');
    }

    public function mergeBatch(Request $request, MergeVenueDuplicateHandler $mergeVenueDuplicate): RedirectResponse
    {
        $validated = $request->validate([
            'canonical_venue_id' => ['required', 'integer'],
            'venue_ids' => ['required', 'array', 'min:2'],
            'venue_ids.*' => ['integer', 'distinct'],
        ]);

        try {
            $mergeVenueDuplicate->handleBatch(
                venueIds: $validated['venue_ids'],
                canonicalVenueId: (int) $validated['canonical_venue_id'],
                resolvedBy: $request->user(),
            );
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.venues.duplicates')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.venues.duplicates')
            ->with('success', 'Площадки объединены.');
    }
}
