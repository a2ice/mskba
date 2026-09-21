<?php

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Application\Services\SuperadminWalletBootstrapBonus;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SuperadminWalletBootstrapBonusController extends Controller
{
    public function __invoke(Request $request, SuperadminWalletBootstrapBonus $bootstrap): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_if($user === null, 401);

        if (! $bootstrap->available($user)) {
            try {
                $bootstrap->grant($user);
            } catch (WalletException) {
                abort(403);
            }

            return redirect()
                ->route('account.wallet')
                ->with('status', 'Тестовые 10 000,00 ₽ уже были начислены ранее.');
        }

        $bootstrap->grant($user);

        return redirect()
            ->route('account.wallet')
            ->with('status', 'На бонусный баланс начислено 10 000,00 ₽ для проверки переводов.');
    }
}
