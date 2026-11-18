<?php

namespace App\Filament\Backoffice\Widgets;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Widgets\Widget;

final class NavigationOverview extends Widget
{
    protected string $view = 'filament.backoffice.widgets.navigation-overview';

    protected int | string | array $columnSpan = 'full';

    /** @var array<string, string> */
    private const GROUP_ICONS = [
        'Контент' => 'heroicon-o-document-text',
        'Сайт и дизайн' => 'heroicon-o-paint-brush',
        'Пользователи и доступ' => 'heroicon-o-users',
        'Площадки' => 'heroicon-o-map-pin',
        'География' => 'heroicon-o-globe-alt',
        'Аренда и документы' => 'heroicon-o-clipboard-document-list',
        'Соревнования' => 'heroicon-o-trophy',
        'Магазин' => 'heroicon-o-shopping-bag',
        'Лояльность' => 'heroicon-o-gift',
        'Поддержка' => 'heroicon-o-lifebuoy',
        'Разработка' => 'heroicon-o-code-bracket-square',
    ];

    protected function getViewData(): array
    {
        // The sidebar remains the only authority for routes and visibility.
        // Flatten child links for accurate per-card counts.
        $groups = collect(Filament::getNavigation())
            ->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()))
            ->map(function (NavigationGroup $group): array {
                $label = (string) $group->getLabel();
                $items = collect($group->getItems())
                    ->flatMap(fn (NavigationItem $item): array => [
                        $item,
                        ...$item->getChildItems(),
                    ])
                    ->filter(fn (NavigationItem $item): bool => $item->isVisible() && filled($item->getUrl()))
                    ->values()
                    ->all();

                return [
                    'label' => $label,
                    'icon' => self::GROUP_ICONS[$label] ?? 'heroicon-o-folder',
                    'items' => $items,
                    'count' => count($items),
                ];
            })
            ->filter(fn (array $group): bool => $group['count'] > 0)
            ->values()
            ->all();

        return ['groups' => $groups];
    }
}
