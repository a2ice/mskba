<?php

namespace App\Filament\Backoffice\Widgets;

use App\Filament\Backoffice\Pages\GraphQLExplorer;
use App\Filament\Backoffice\Pages\PageBuilderAgentAccess;
use App\Filament\Backoffice\Pages\RestApiExplorer;
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

    /** Native Filament navigation items displayed in a separate Site subsection. */
    private const SITE_TOOL_KEYS = [
        'backoffice.venue-integrations',
        'backoffice.openapi',
        'backoffice.dev.swagger',
        'backoffice.dev.openapi',
        'backoffice.dev.graphql',
        RestApiExplorer::class,
        GraphQLExplorer::class,
        PageBuilderAgentAccess::class,
        \App\Filament\Backoffice\Pages\SocialIntegrationSettingsPage::class,
    ];

    protected function getViewData(): array
    {
        // All destinations and visibility still come from the authorized sidebar.
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
                    ->values();

                $tools = $label === 'Сайт и дизайн'
                    ? $items->filter(
                        fn (NavigationItem $item): bool => in_array($item->getKey(), self::SITE_TOOL_KEYS, true),
                    )->values()->all()
                    : [];

                $regularItems = $items->reject(
                    fn (NavigationItem $item): bool => in_array($item->getKey(), self::SITE_TOOL_KEYS, true),
                )->values()->all();

                return [
                    'label' => $label,
                    'icon' => self::GROUP_ICONS[$label] ?? 'heroicon-o-folder',
                    'items' => $regularItems,
                    'tools' => $tools,
                    'count' => count($regularItems) + count($tools),
                ];
            })
            ->filter(fn (array $group): bool => $group['count'] > 0)
            ->values()
            ->all();

        return ['groups' => $groups];
    }
}
