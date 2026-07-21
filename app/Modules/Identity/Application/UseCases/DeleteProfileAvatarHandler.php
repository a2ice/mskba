<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DeleteProfileAvatarHandler
{
    public function handle(Profile $profile, int $avatarId): void
    {
        $deletedAvatar = DB::transaction(function () use ($profile, $avatarId): Media {
            $lockedProfile = Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            $avatars = $lockedProfile->avatars()
                ->lockForUpdate()
                ->get();
            $avatar = $avatars->firstWhere('id', $avatarId);

            if (! $avatar instanceof Media) {
                throw (new ModelNotFoundException)->setModel(Media::class, [$avatarId]);
            }

            $wasActive = $avatar->is_featured;
            $avatar->delete();

            if ($wasActive) {
                $replacement = $avatars->first(fn (Media $candidate): bool => ! $candidate->is($avatar));
                $replacement?->forceFill(['is_featured' => true])->save();
            }

            $profile->unsetRelation('activeAvatar');
            $profile->unsetRelation('avatars');

            return $avatar;
        });

        Storage::disk($deletedAvatar->disk)->delete($deletedAvatar->path);
    }
}
