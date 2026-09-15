<?php

namespace App\Modules\SportsSection\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Application\UseCases\ManageSportsSectionJoinRequestHandler;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;
use App\Modules\SportsSection\Domain\Models\SportsSectionJoinRequest;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SportsSectionApplicationController extends Controller
{
    public function manage(Request $request, SportsSection $sportsSection, SportsSectionAccess $access): Response
    {
        $this->guardFeature();
        abort_unless($access->allows($request->user()->canonical(), $sportsSection, SportsSectionPermissionEnum::MANAGE_TRAINEES), 403);

        return ThemeResolver::page('account.sports-sections.applications', [
            'section' => $sportsSection,
            'joinRequests' => $sportsSection->joinRequests()
                ->with(['user.profile.activeAvatar', 'reviewedBy.profile'])
                ->orderByRaw("case status when 'pending' then 0 else 1 end")
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get(),
        ]);
    }

    public function updateSettings(Request $request, SportsSection $sportsSection, SportsSectionAccess $access): RedirectResponse
    {
        $this->guardFeature();
        $currentYear = (int) now()->year;
        $rules = [
            'accepts_trainee_requests' => ['required', 'boolean'],
            'is_recruiting' => ['required', 'boolean'],
            'trainee_capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'audience_mode' => ['sometimes', Rule::in(['none', 'exact', 'range'])],
        ];

        if ($request->has('audience_mode')) {
            $rules['target_year'] = ['nullable', 'integer', 'between:1900,'.$currentYear, 'required_if:audience_mode,exact', 'prohibited_unless:audience_mode,exact'];
            $rules['target_year_from'] = ['nullable', 'integer', 'between:1900,'.$currentYear, 'required_if:audience_mode,range', 'prohibited_unless:audience_mode,range'];
            $rules['target_year_to'] = ['nullable', 'integer', 'between:1900,'.$currentYear, 'required_if:audience_mode,range', 'prohibited_unless:audience_mode,range', 'gte:target_year_from'];
        }

        $data = $request->validate($rules);
        $accepts = (bool) $data['accepts_trainee_requests'];
        $recruiting = (bool) $data['is_recruiting'];
        $autoEnabled = $recruiting && ! $accepts;
        $targetYears = null;

        if (array_key_exists('audience_mode', $data)) {
            $mode = (string) $data['audience_mode'];
            $targetYears = [
                'target_year' => $mode === 'exact' ? (int) $data['target_year'] : null,
                'target_year_from' => $mode === 'range' ? (int) $data['target_year_from'] : null,
                'target_year_to' => $mode === 'range' ? (int) $data['target_year_to'] : null,
            ];
        }

        DB::transaction(function () use ($sportsSection, $request, $access, $accepts, $recruiting, $targetYears, $data): void {
            $section = SportsSection::query()->lockForUpdate()->findOrFail($sportsSection->id);
            abort_unless($access->allows($request->user()->canonical(), $section, SportsSectionPermissionEnum::MANAGE_TRAINEES), 403);
            $section->forceFill([
                'accepts_trainee_requests' => $recruiting || $accepts,
                'is_recruiting' => $recruiting,
                'trainee_capacity' => isset($data['trainee_capacity']) ? (int) $data['trainee_capacity'] : null,
                ...($targetYears ?? []),
            ])->save();
        });

        return back()->with('status', $autoEnabled
            ? 'Активный набор включён. Приём заявок включён автоматически.'
            : 'Настройки заявок секции обновлены.');
    }

    public function store(Request $request, SportsSection $sportsSection, ManageSportsSectionJoinRequestHandler $handler): RedirectResponse
    {
        return $this->execute(fn () => $handler->submit($sportsSection, $request->user()), 'Заявка отправлена.');
    }

    public function cancel(Request $request, SportsSection $sportsSection, SportsSectionJoinRequest $joinRequest, ManageSportsSectionJoinRequestHandler $handler): RedirectResponse
    {
        return $this->execute(fn () => $handler->cancel($sportsSection, $joinRequest, $request->user()), 'Заявка отменена.');
    }

    public function respond(Request $request, SportsSection $sportsSection, SportsSectionJoinRequest $joinRequest, ManageSportsSectionJoinRequestHandler $handler): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['accept', 'reject'])],
            'review_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->execute(
            fn () => $handler->respond($sportsSection, $joinRequest, $request->user(), $data['action'], $data['review_reason'] ?? null),
            $data['action'] === 'accept' ? 'Заявка принята. Пользователь добавлен в состав секции.' : 'Заявка отклонена.',
        );
    }

    private function execute(callable $action, string $message): RedirectResponse
    {
        $this->guardFeature();
        try {
            $action();

            return back()->with('status', $message);
        } catch (SportsSectionException $exception) {
            return back()->withErrors(['section' => $exception->getMessage()]);
        }
    }

    private function guardFeature(): void
    {
        abort_unless(config('features.sports_sections.enabled'), 404);
    }
}
