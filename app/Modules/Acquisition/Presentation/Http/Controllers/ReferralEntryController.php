<?php

namespace App\Modules\Acquisition\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Acquisition\Application\Services\ReferralAttributionService;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ReferralEntryController extends Controller
{
    public function __invoke(
        Request $request,
        string $username,
        ReferralAttributionService $referrals,
    ): RedirectResponse {
        $referrer = User::query()
            ->whereNull('canonical_user_id')
            ->whereNull('deleted_at')
            ->where('status', UserStatusEnum::CONFIRMED->value)
            ->where('username', $username)
            ->firstOrFail();

        $referrals->capture($request, $referrer);

        return redirect()->route('acquisition.join');
    }
}
