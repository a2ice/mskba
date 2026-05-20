<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\View\View;
use App\Modules\Identity\Application\Queries\ListAdminUsersQuery;
use App\Modules\Identity\Application\Queries\GetAdminUserQuery;

class AdminController extends Controller
{
    public function __construct(private ThemeResolver $themeResolver) {}

    public function index(): View
    {
        return view($this->themeResolver->view('pages.admin.index'));
    }

    public function users(ListAdminUsersQuery $listAdminUsersQuery): View
    {
        $filters = [
            'includeProfile' => true,
            'includeContacts' => true,
            'includeParticipationRoles' => true,
            'sortBy' => 'created_at',
            'sortDirection' => 'desc',
            'perPage' => 20,
            'page' => 1,
        ];

        $users = $listAdminUsersQuery->execute($filters);

        return view($this->themeResolver->view('pages.admin.users'), ['users' => $users]);
    }

    public function user(int $id, GetAdminUserQuery $getAdminUserQuery): View
    {
        $user = $getAdminUserQuery->execute($id);

        return view($this->themeResolver->view('pages.admin.user'), ['user' => $user]);
    }
}
