<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ActivateProfileAvatarHandler
{
    public function handle(Profile $profile, int $avatarId): Media
    {
        return DB::transaction(function () use ($profile, $avatarId): Media {
            $lockedProfile = Profile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            $avatars = $lockedProfile->avatars()
                ->lockForUpdate()
                ->get();
            $avatar = $avatars->firstWhere('id', $avatarId);

            if (! $avatar instanceof Media) {
                throw (new ModelNotFoundException)->setModel(Media::class, [$avatarId]);
            }

            foreach ($avatars as $candidate) {
                $shouldBeActive = $candidate->is($avatar);

                if ($candidate->is_featured !== $shouldBeActive) {
                    $candidate->forceFill(['is_featured' => $shouldBeActive])->save();
                }
            }

            $profile->unsetRelation('activeAvatar');
            $profile->unsetRelation('avatars');

            return $avatar->refresh();
        });
    }
}
