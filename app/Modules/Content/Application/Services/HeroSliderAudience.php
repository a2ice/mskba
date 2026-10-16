<?php

namespace App\Modules\Content\Application\Services;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Support\Facades\Auth;

/** Audience checks are server-side; excluded slides never reach the browser. */
final class HeroSliderAudience
{
    public function visible(array $slide, bool $preview = false, ?User $viewer = null): bool
    {
        if (! (bool) ($slide['enabled'] ?? true)) {
            return false;
        }
        // Backoffice previews are for editors and intentionally display all
        // audiences (except disabled slides), without exposing them publicly.
        if ($preview) {
            return true;
        }

        $viewer ??= Auth::guard('web')->user()?->canonical();
        $audience = (array) ($slide['audience'] ?? []);
        $auth = $audience['auth'] ?? 'all';
        if ($auth === 'guest' && $viewer !== null) {
            return false;
        }
        if ($auth === 'authenticated' && $viewer === null) {
            return false;
        }
        $confirmation = $audience['confirmation'] ?? 'any';
        if ($confirmation !== 'any') {
            if ($viewer === null) {
                return false;
            }
            if (($confirmation === 'confirmed' && $viewer->status !== UserStatusEnum::CONFIRMED)
                || ($confirmation === 'unconfirmed' && $viewer->status !== UserStatusEnum::UNCONFIRMED)) {
                return false;
            }
        }
        if (! empty($audience['system_roles'])) {
            if ($viewer === null || ! in_array($viewer->system_role->value, $audience['system_roles'], true)) {
                return false;
            }
        }
        if (! empty($audience['participation_roles'])) {
            if ($viewer === null || ! $viewer->participationRoles()
                ->whereIn('role', $audience['participation_roles'])->exists()) {
                return false;
            }
        }

        // Fail closed even for malformed imported records: user tags are not
        // implemented yet, and must never be silently treated as a match.
        if (! empty($audience['tags_any']) || ! empty($audience['tags_none'])) {
            return false;
        }

        return true;
    }
}
