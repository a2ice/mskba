<?php

namespace App\Modules\Finance\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Application\Queries\GetAccountWalletOverview;
use App\Modules\Identity\Domain\Models\User;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\Response;

final class AccountWalletController extends Controller
{
    public function __invoke(GetAccountWalletOverview $overview): Response
    {
        /** @var User|null $user */
        $user = request()->user();
        abort_if($user === null, 401);

        return ThemeResolver::page('account.wallet', $overview->handle($user));
    }
}
