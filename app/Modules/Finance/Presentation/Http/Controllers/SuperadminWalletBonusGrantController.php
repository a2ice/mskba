<?php

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Application\Services\RubleAmountParser;
use App\Modules\Finance\Application\Services\SuperadminWalletBonusGrant;
use App\Modules\Finance\Domain\Exceptions\WalletException;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SuperadminWalletBonusGrantController extends Controller
{
    public function __invoke(
        Request $request,
        RubleAmountParser $amounts,
        SuperadminWalletBonusGrant $bonusGrants,
    ): RedirectResponse {
        /** @var User|null $user */
        $user = $request->user();
        abort_if($user === null, 401);
        $user = $user->canonical();

        abort_unless($bonusGrants->allowed($user), 403);

        $validated = $request->validate([
            'grant_amount' => ['required', 'string', 'max:32'],
            'grant_password' => ['required', 'string', 'max:255'],
            'grant_idempotency_key' => ['required', 'uuid'],
        ], [
            'grant_amount.required' => 'Укажите сумму начисления.',
            'grant_password.required' => 'Введите текущий пароль superadmin.',
            'grant_idempotency_key.required' => 'Не удалось определить финансовую операцию. Повторите попытку.',
            'grant_idempotency_key.uuid' => 'Не удалось определить финансовую операцию. Повторите попытку.',
        ]);

        try {
            $amountMinor = $amounts->parse((string) $validated['grant_amount']);
        } catch (WalletException $e) {
            return back()
                ->withInput($request->except('grant_password'))
                ->withErrors(['grant_amount' => $e->getMessage()]);
        }

        try {
            $bonusGrants->grant(
                user: $user,
                currentPassword: (string) $validated['grant_password'],
                amountMinor: $amountMinor,
                idempotencyKey: (string) $validated['grant_idempotency_key'],
            );
        } catch (WalletException $e) {
            return back()
                ->withInput($request->except('grant_password'))
                ->withErrors(['grant_password' => $e->getMessage()]);
        }

        return redirect()
            ->route('account.wallet')
            ->with('status', 'На бонусный баланс начислено '.$this->formatMinor($amountMinor).'.');
    }

    private function formatMinor(int $amountMinor): string
    {
        $rubles = intdiv($amountMinor, 100);
        $kopeks = $amountMinor % 100;

        return number_format($rubles, 0, ',', ' ').','.str_pad((string) $kopeks, 2, '0', STR_PAD_LEFT).' ₽';
    }
}
