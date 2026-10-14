<?php
namespace App\Modules\Dashboard\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Application\Services\AccountDashboardService;
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
        $this->authorizeAdmin($request);
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
        $this->authorizeAdmin($request);
        $data = $this->validated($request);
        DB::transaction(fn () => AccountDashboardWidget::query()->create($data));
        $this->changed();
        return back()->with('status', 'Виджет создан.');
    }

    public function update(Request $request, AccountDashboardWidget $widget): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validated($request, $widget);
        DB::transaction(fn () => $widget->update($data));
        $this->changed();
        return back()->with('status', 'Виджет обновлён.');
    }

    public function destroy(Request $request, AccountDashboardWidget $widget): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($this->isSuperadmin($request), 403);
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
            'link_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'image_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'link_label' => ['nullable', 'string', 'max:100'],
            'html_code' => ['nullable', 'string', 'max:40000'],
            'css_code' => ['nullable', 'string', 'max:20000'],
            'js_code' => ['nullable', 'string', 'max:20000'],
            'iframe_height' => ['required', 'integer', 'between:120,900'],
            'sort_order' => ['required', 'integer', 'between:0,9999'],
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
        return $data;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->canonical()->isAdmin(), 403);
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
