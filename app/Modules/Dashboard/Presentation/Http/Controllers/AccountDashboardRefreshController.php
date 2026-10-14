<?php
namespace App\Modules\Dashboard\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Application\Services\AccountDashboardService;
use App\Modules\Notification\Application\UseCases\CountNewUserNotificationsHandler;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountDashboardRefreshController extends Controller
{
    public function __invoke(Request $request, AccountDashboardService $dashboard,
        CountNewUserNotificationsHandler $notifications): JsonResponse
    {
        abort_unless(app(ThemeResolver::class)->active() === 'mskba_app', 404);
        $user = $request->user()->canonical();

        return response()->json([
            'html' => view('theme::pages.account.partials.dashboard-widget-list', [
                'dashboardWidgets' => $dashboard->forUser($user),
                'dashboardUser' => $user,
                'dashboardUnread' => $notifications->handle($user),
            ])->render(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
