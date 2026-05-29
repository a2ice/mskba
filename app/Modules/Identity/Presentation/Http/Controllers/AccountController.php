<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Modules\Identity\Domain\Models\User;

class AccountController extends Controller
{
    public function index(): View
    {
        /** @var User|null $user */
        $user = Auth::user();

        if(!$user) {
            abort(403, 'Unauthorized');
        }

        $user->load('profile', 'participationRoles');

        if($user->participationRoles) {
            $participationRoleLabels = $user->participationRoles
                ->map(fn ($participationRole) => $participationRole->role->label())
                ->join(', ');
            $user->participation_role_labels = $participationRoleLabels;
        }

        $data = ['user' => $user];

        return ThemeResolver::page('account.index', $data);
    }

    public function contracts(): View
    {
        return ThemeResolver::page('account.contracts');
    }
}
