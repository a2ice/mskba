<?php

namespace App\Modules\Dashboard\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Application\Services\AccountDashboardService;
use App\Modules\Dashboard\Application\Services\DashboardDataRegistry;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountDashboardWidgetDataController extends Controller
{
    public function __invoke(
        Request $request,
        AccountDashboardWidget $widget,
        AccountDashboardService $dashboard,
        DashboardDataRegistry $registry,
    ): JsonResponse {
        abort_unless(app(ThemeResolver::class)->active() === 'mskba_app', 404);
        $user = $request->user()->canonical();
        abort_unless($widget->kind === 'dynamic' && ! $user->isBlocked()
            && $dashboard->forUser($user)->contains('id', $widget->getKey()), 404);

        return response()->json([
            'widget_id' => $widget->getKey(),
            'data' => $registry->getData($widget, $user)->toArray(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
