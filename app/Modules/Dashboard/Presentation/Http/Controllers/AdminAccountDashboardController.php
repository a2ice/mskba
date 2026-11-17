<?php
namespace App\Modules\Dashboard\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Application\Services\AccountDashboardService;
use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as BackofficePermission;
use App\Modules\Dashboard\Application\Services\DashboardWidgetActions;
use App\Modules\Dashboard\Application\Rules\DashboardWidgetLinkRule;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Dashboard\Infrastructure\Broadcasting\DashboardChangedBroadcast;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminAccountDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeWidgetAction($request, BackofficePermission::BACKOFFICE_WIDGET_VIEW);
        abort_unless(app(ThemeResolver::class)->active() === 'mskba_app', 404);
        return ThemeResolver::page('admin.account-dashboard', [
            'widgets' => AccountDashboardWidget::query()->orderBy('sort_order')->orderBy('id')->get(),
            'kinds' => AccountDashboardService::KINDS,
            'statuses' => UserStatusEnum::cases(),
            'systemRoles' => UserSystemRoleEnum::cases(),
            'participationRoles' => UserParticipationRoleEnum::cases(),
            'canEditCode' => $this->isSuperadmin($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeWidgetAction($request, BackofficePermission::BACKOFFICE_WIDGET_CREATE);
        $data = $this->validated($request);
        DB::transaction(fn () => AccountDashboardWidget::query()->create($data));
        $this->changed();
        return back()->with('status', 'Виджет создан.');
    }

    public function update(Request $request, AccountDashboardWidget $widget): RedirectResponse
    {
        $this->authorizeWidgetAction($request, BackofficePermission::BACKOFFICE_WIDGET_EDIT);
        abort_unless($request->user()?->can('update', $widget), 403);
        $data = $this->validated($request, $widget);
        DB::transaction(fn () => $widget->update($data));
        $this->changed();
        return back()->with('status', 'Виджет обновлён.');
    }

    public function destroy(Request $request, AccountDashboardWidget $widget): RedirectResponse
    {
        $this->authorizeWidgetAction($request, BackofficePermission::BACKOFFICE_WIDGET_DELETE);
        abort_unless($request->user()?->can('delete', $widget), 403);
        $widget->delete();
        $this->changed();
        return back()->with('status', 'Виджет удалён.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?AccountDashboardWidget $existing = null): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(AccountDashboardService::KINDS))],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:10000'],
            'status_confirmed_html' => ['nullable', 'string', 'max:10000'],
            'status_unconfirmed_html' => ['nullable', 'string', 'max:10000'],
            'status_blocked_html' => ['nullable', 'string', 'max:10000'],
            'link_url' => ['nullable', 'string', 'max:500', new DashboardWidgetLinkRule()],
            'image_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'link_label' => ['nullable', 'string', 'max:100'],
            'html_code' => ['nullable', 'string', 'max:40000'],
            'css_code' => ['nullable', 'string', 'max:20000'],
            'js_code' => ['nullable', 'string', 'max:20000'],
            'iframe_height' => ['required', 'integer', 'between:120,900'],
            'sort_order' => ['required', 'integer', 'between:0,9999'],
            'gradient_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'size' => ['required', Rule::in(['half', 'full'])],
            'enabled' => ['required', 'boolean'],
            'can_hide' => ['required', 'boolean'],
            'contact_filter' => ['required', Rule::in(['any', 'verified', 'unverified'])],
            'account_statuses' => ['sometimes', 'array'],
            'account_statuses.*' => [Rule::in(array_column(UserStatusEnum::cases(), 'value'))],
            'system_roles' => ['sometimes', 'array'],
            'system_roles.*' => [Rule::in(array_column(UserSystemRoleEnum::cases(), 'value'))],
            'participation_roles' => ['sometimes', 'array'],
            'participation_roles.*' => [Rule::in(array_column(UserParticipationRoleEnum::cases(), 'value'))],
            'include_user_ids' => ['nullable', 'string', 'max:4000'],
            'exclude_user_ids' => ['nullable', 'string', 'max:4000'],
        ]);

        if (($data['kind'] === 'custom_html' || $existing?->kind === 'custom_html')
            && ! $this->isSuperadmin($request)) abort(403);
        if ($data['kind'] === 'advertisement' && blank($data['link_url'] ?? null)) {
            throw ValidationException::withMessages(['link_url' => 'Для рекламы нужна ссылка.']);
        }
        foreach (['include_user_ids', 'exclude_user_ids'] as $key) {
            $raw = trim((string) ($data[$key] ?? ''));
            if ($raw !== '' && ! preg_match('/\A\d+(?:[\s,]+\d+)*\z/D', $raw)) {
                throw ValidationException::withMessages([$key => 'Укажи ID через запятую.']);
            }
            $data[$key] = $raw === '' ? [] : array_values(array_unique(array_map('intval',
                preg_split('/[\s,]+/', $raw))));
            if (count($data[$key]) > 200) {
                throw ValidationException::withMessages([$key => 'Не более 200 ID.']);
            }
        }
        foreach (['account_statuses', 'system_roles', 'participation_roles'] as $key) {
            $data[$key] = array_values(array_unique($data[$key] ?? []));
        }
        // Clear previously stored executable code if widget type changes.
        if ($data['kind'] !== 'custom_html') {
            $data['html_code'] = null;
            $data['css_code'] = null;
            $data['js_code'] = null;
        }
        // The fallback editor still supports a single link; update only its
        // primary button and preserve any additional actions defined in Filament.
        $service = app(DashboardWidgetActions::class);
        $actions = $existing?->actions ?? $service->defaults(
            $data['kind'], $data['link_url'] ?? null, $data['link_label'] ?? null,
        );
        if ($existing && in_array($data['kind'], ['advertisement', 'information'], true)) {
            if (filled($data['link_url'] ?? null)) {
                $primary = [
                    'label' => $data['link_label'] ?: 'Подробнее',
                    'url' => $data['link_url'],
                    'appearance' => $data['kind'] === 'advertisement' ? 'primary' : 'secondary',
                    'visibility' => $actions[0]['visibility'] ?? 'always',
                    'classes' => $actions[0]['classes'] ?? [],
                ];
                if ($actions === []) $actions = [$primary];
                else $actions[0] = array_replace($actions[0], $primary);
            } elseif ($data['kind'] === 'information') {
                array_shift($actions);
            }
        }
        $data['actions'] = $data['kind'] === 'custom_html'
            ? [] : $service->validate($actions, $data['kind']);

        return $data;
    }

    private function authorizeWidgetAction(Request $request, BackofficePermission $permission): void
    {
        $user = $request->user();
        $checker = app(BackofficePermissionManager::class);

        abort_unless($user && $user->canonical()->isAdmin()
            && $checker->allows($user, BackofficePermission::BACKOFFICE_ACCESS)
            && $checker->allows($user, BackofficePermission::BACKOFFICE_WIDGET_VIEW)
            && $checker->allows($user, $permission), 403);
    }

    private function isSuperadmin(Request $request): bool
    {
        return $request->user()?->canonical()->system_role === UserSystemRoleEnum::SUPERADMIN;
    }

    private function changed(): void
    {
        try { DashboardChangedBroadcast::dispatch(); }
        catch (\Throwable $exception) { report($exception); }
    }
}
