<?php

namespace App\Modules\Media\Application\Services;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Media\Domain\Models\Media;
use App\Modules\Venue\Application\Services\VenueRevisionManager;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Keeps an owner's changes to a confirmed venue's banner in the same
 * VenueRevision that protects regular venue details and gallery images.
 */
final class VenueBannerDraftManager
{
    public function __construct(
        private readonly VenueRevisionManager $revisions,
        private readonly EntityBannerManager $banners,
        private readonly CurrentActorResolver $actors,
    ) {}

    public function preview(Venue $venue): ?Media
    {
        $revision = $this->revisions->draftFor($venue);
        if ($revision !== null && array_key_exists('banner', $revision->payload)) {
            $reference = $revision->payload['banner'];

            return is_array($reference)
                ? $revision->banner()->whereKey((int) ($reference['id'] ?? 0))->first()
                : null;
        }

        return $venue->banner;
    }

    /**
     * @param array{x: float, y: float, width: float, height: float} $crop
     */
    public function update(Venue $venue, Request $request, ?string $original, array $crop): Media
    {
        return DB::transaction(function () use ($venue, $request, $original, $crop): Media {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $revision = $this->draft($venue, $request);
            $draftBanner = $revision->banner;

            if ($original !== null) {
                $media = $this->banners->replace($revision, $original, $crop);
            } elseif ($draftBanner !== null) {
                $media = $this->banners->recrop($revision, $crop);
            } else {
                $published = $venue->banner;
                if ($published === null || ! $published->original_path) {
                    throw ValidationException::withMessages(['image' => 'Сначала выберите изображение.']);
                }
                $original = Storage::disk($published->original_disk ?: 'local')->get($published->original_path);
                $media = $this->banners->replace($revision, $original, $crop);
            }

            $payload = $revision->payload;
            $payload['banner'] = ['kind' => 'draft', 'id' => $media->id];
            $revision->forceFill(['payload' => $payload])->save();

            return $media;
        });
    }

    public function remove(Venue $venue, Request $request): void
    {
        DB::transaction(function () use ($venue, $request): void {
            $venue = Venue::query()->lockForUpdate()->findOrFail($venue->id);
            $revision = $this->draft($venue, $request);
            $this->banners->remove($revision);
            $payload = $revision->payload;
            $payload['banner'] = null;
            $revision->forceFill(['payload' => $payload])->save();
        });
    }

    private function draft(Venue $venue, Request $request): VenueRevision
    {
        $actor = $this->actors->resolveForRequest($request);
        $revision = $this->revisions->getOrCreateDraft($venue, $actor);
        $this->revisions->assertCurrent($revision);

        return $revision;
    }
}
