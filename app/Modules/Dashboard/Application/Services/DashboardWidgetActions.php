<?php

namespace App\Modules\Dashboard\Application\Services;

use App\Modules\Dashboard\Application\Rules\DashboardWidgetLinkRule;
use App\Modules\Dashboard\Domain\Models\AccountDashboardWidget;
use App\Modules\Dashboard\Domain\Models\DashboardButtonStyle;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Validation\ValidationException;

final class DashboardWidgetActions
{
    public function validate(array $actions, string $kind): array
    {
        if (count($actions) > 12) {
            throw ValidationException::withMessages(['data.actions' => 'Не более 12 кнопок на виджет.']);
        }

        $registered = DashboardButtonStyle::query()->pluck('class_name')->all();
        $result = [];
        foreach ($actions as $action) {
            if (! is_array($action)) {
                throw ValidationException::withMessages(['data.actions' => 'Неправильный формат кнопки.']);
            }
            $url = (string) ($action['url'] ?? '');
            $label = trim((string) ($action['label'] ?? ''));
            $appearance = $action['appearance'] ?? 'secondary';
            $visibility = $action['visibility'] ?? 'always';
            $classes = $action['classes'] ?? [];

            if (mb_strlen($label) < 1 || mb_strlen($label) > 100
                || mb_strlen($url) > 500 || ! DashboardWidgetLinkRule::isValid($url)
                || ! in_array($appearance, ['primary', 'secondary'], true)
                || ! in_array($visibility, ['always', 'confirmed', 'unconfirmed', 'blocked'], true)
                || ! is_array($classes) || count($classes) > 10
                || array_diff($classes, $registered) !== []) {
                throw ValidationException::withMessages([
                    'data.actions' => 'Проверь название, ссылку, оформление и CSS-классы кнопок.',
                ]);
            }

            $result[] = [
                'label' => $label,
                'url' => $url,
                'appearance' => $appearance,
                'visibility' => $visibility,
                'classes' => array_values(array_unique($classes)),
            ];
        }

        if ($kind === 'advertisement' && $result === []) {
            throw ValidationException::withMessages([
                'data.actions' => 'Рекламной карточке нужна как минимум одна кнопка со ссылкой.',
            ]);
        }

        return $result;
    }

    public function forUser(AccountDashboardWidget $widget, User $user): array
    {
        $user = $user->canonical();
        $state = $user->isBlocked() ? 'blocked' : ($user->isConfirmed() ? 'confirmed' : 'unconfirmed');
        $registered = DashboardButtonStyle::query()->where('enabled', true)->pluck('class_name')->all();

        $result = [];
        foreach ($widget->actions ?? [] as $action) {
            if (! is_array($action) || ! in_array($action['visibility'] ?? 'always', ['always', $state], true)) {
                continue;
            }
            $url = $action['url'] ?? '';
            if (! is_string($url) || ! DashboardWidgetLinkRule::isValid($url)) {
                continue;
            }
            $appearance = in_array($action['appearance'] ?? '', ['primary', 'secondary'], true)
                ? $action['appearance'] : 'secondary';
            $classes = array_values(array_intersect(
                array_filter($action['classes'] ?? [], 'is_string'),
                $registered,
            ));
            $result[] = [
                'label' => (string) ($action['label'] ?? ''),
                'url' => $url,
                'appearance' => $appearance,
                'classes' => $classes,
                'external' => ! str_starts_with($url, '/'),
                'confirmation' => $url === route('account.confirmation') && $state === 'unconfirmed',
            ];
        }

        return $result;
    }
}
