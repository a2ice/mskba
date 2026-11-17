<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Domain\Models\Profile;
use App\Modules\Media\Domain\Models\Media;
use Illuminate\Http\JsonResponse;

final class AccountAvatarJsonResponse
{
    public static function success(Profile $profile, string $message): JsonResponse
    {
        // Reload after the transaction: deleting the active image can promote
        // another avatar and a fourth upload can evict the oldest one.
        $avatars = $profile->avatars()->get();
        $active = $avatars->first(fn (Media $avatar): bool => $avatar->is_featured);

        return response()->json([
            'message' => $message,
            'avatar_url' => $active?->publicUrl(),
            'avatars' => $avatars->map(fn (Media $avatar): array => [
                'id' => $avatar->id,
                'url' => $avatar->publicUrl(),
                'is_featured' => $avatar->is_featured,
                'activate_url' => route('account.avatar.activate', $avatar->id),
                'delete_url' => route('account.avatar.destroy', $avatar->id),
            ])->values()->all(),
        ]);
    }
}
