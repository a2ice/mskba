<?php

namespace App\Modules\Dashboard\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Application\Services\AccountDashboardService;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AccountDashboardPreferencesController extends Controller
{
    public function index(Request $request, AccountDashboardService $dashboard): Response|RedirectResponse
    {
        if (app(ThemeResolver::class)->active() !== 'mskba_app') {
            return redirect()->route('account.settings');
        }

        $user = $request->user()->canonical();

        return ThemeResolver::page('account.dashboard-settings', [
            'user' => $user,
            'widgets' => $dashboard->availableForUser($user),
            'widgetPreferences' => $dashboard->preferencesForUser($user),
        ]);
    }

    public function update(Request $request, AccountDashboardService $dashboard): RedirectResponse|JsonResponse
    {
        abort_unless(app(ThemeResolver::class)->active() === 'mskba_app', 404);

        $validated = $request->validate([
            'visible_widget_ids' => ['sometimes', 'array', 'max:250'],
            'visible_widget_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $dashboard->saveVisibility(
            $request->user(),
            array_map('intval', $validated['visible_widget_ids'] ?? []),
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('account.settings.dashboard')
            ->with('status', 'Виджеты дашборда сохранены.');
    }

    public function reorder(Request $request, AccountDashboardService $dashboard): JsonResponse
    {
        abort_unless(app(ThemeResolver::class)->active() === 'mskba_app', 404);

        $validated = $request->validate([
            'widget_ids' => ['required', 'array', 'max:250'],
            'widget_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $dashboard->saveOrder(
            $request->user(),
            array_map('intval', $validated['widget_ids']),
        );

        return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
    }
}
