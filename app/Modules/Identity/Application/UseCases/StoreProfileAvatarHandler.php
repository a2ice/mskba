<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Media\Application\Services\WebpImageNormalizer;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class StoreProfileAvatarHandler
{
    private const MAX_AVATARS = 3;

    public function __construct(
        private readonly WebpImageNormalizer $normalizer,
    ) {}

    public function handle(
        Profile $profile,
        string $contents,
        string $source = 'upload',
        ?string $sourceReference = null,
    ): Media {
        $image = $this->normalizer->normalize($contents);
        $disk = 'public';
        $path = sprintf('avatars/%d/%s.webp', $profile->id, Str::uuid());

        if (! Storage::disk($disk)->put($path, $image['contents'])) {
            throw new RuntimeException('Не удалось сохранить аватар.');
        }

        $obsolete = collect();

        try {
            $avatar = DB::transaction(function () use ($profile, $disk, $path, $image, $source, $sourceReference, &$obsolete): Media {
                $lockedProfile = Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

                $avatars = $lockedProfile->media()
                    ->where('collection', 'avatar')
                    ->lockForUpdate()
                    ->latest('id')
                    ->get();

                foreach ($avatars as $existingAvatar) {
                    if ($existingAvatar->is_featured) {
                        $existingAvatar->forceFill(['is_featured' => false])->save();
                    }
                }

                $avatar = $lockedProfile->media()->create([
                    'collection' => 'avatar',
                    'source' => $source,
                    'source_reference' => $sourceReference,
                    'disk' => $disk,
                    'path' => $path,
                    'mime' => $image['mime'],
                    'size' => strlen($image['contents']),
                    'is_featured' => true,
                ]);

                $obsolete = $lockedProfile->media()
                    ->where('collection', 'avatar')
                    ->latest('id')
                    ->get()
                    ->slice(self::MAX_AVATARS)
                    ->values();

                foreach ($obsolete as $oldAvatar) {
                    $oldAvatar->forceDelete();
                }

                return $avatar;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        foreach ($obsolete as $oldAvatar) {
            Storage::disk($oldAvatar->disk)->delete($oldAvatar->path);
        }

        $profile->unsetRelation('activeAvatar');

        return $avatar;
    }
}
