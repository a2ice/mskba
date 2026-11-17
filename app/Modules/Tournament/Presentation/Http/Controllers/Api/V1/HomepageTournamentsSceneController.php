<?php

namespace App\Modules\Tournament\Presentation\Http\Controllers\Api\V1;

use App\Modules\Event\Domain\Enums\GameFormatEnum;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use App\Modules\Media\Domain\Models\SiteMediaFolder;
use App\Modules\Tournament\Domain\Enums\TournamentStatusEnum;
use App\Modules\Tournament\Domain\Models\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Public, read-only data for the homepage tournaments scene.
 * The three art layers are looked up by asset name within the Filament folder.
 * Never returns unpublished tournaments or invents schedule/participant counts.
 */
final class HomepageTournamentsSceneController
{
    public function __invoke(): JsonResponse
    {
        $assets = ['background' => null, 'rim' => null, 'ball' => null];

        $folder = SiteMediaFolder::query()->where('slug', 'home-tournaments-section')->first();
        if ($folder !== null) {
            $media = SiteMediaAsset::query()
                ->where('folder_id', $folder->getKey())
                ->whereNotNull('current_media_id')
                ->with('currentMedia')
                ->orderByDesc('id')
                ->get();

            foreach ($media as $asset) {
                $title = Str::lower(trim((string) $asset->title));
                $filename = Str::lower(pathinfo((string) $asset->original_name, PATHINFO_FILENAME));
                foreach (array_keys($assets) as $key) {
                    if ($assets[$key] === null
                        && ($title === $key || $filename === $key)
                        && str_starts_with((string) $asset->currentMedia?->mime, 'image/')) {
                        $assets[$key] = $asset->publicUrl();
                    }
                }
            }
        }

        $tournaments = Tournament::query()
            ->where('status', TournamentStatusEnum::CONFIRMED->value)
            ->whereNull('tournament_closed_at')
            ->whereDate('starts_on', '>=', today())
            ->orderBy('starts_on')
            ->orderBy('id')
            ->limit(3)
            ->get(['id', 'alias', 'title', 'starts_on', 'format'])
            ->map(fn (Tournament $item): array => [
                'title' => $item->title,
                'date' => $item->starts_on?->toDateString(),
                'format' => $item->format?->label(),
                'url' => route('tournaments.show', $item->routeIdentifier(), false),
            ])
            ->values()
            ->all();

        return response()->json([
            'assets' => $assets,
            'tournaments' => $tournaments,
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
