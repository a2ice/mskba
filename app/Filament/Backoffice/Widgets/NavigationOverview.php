<?php

namespace App\Filament\Backoffice\Widgets;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Widgets\Widget;

final class NavigationOverview extends Widget
{
    protected string $view = 'filament.backoffice.widgets.navigation-overview';

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        // Reuse the actual, authorized Filament navigation. Hidden resources,
        // inaccessible developer tools, and unavailable links stay hidden.
        return [
            'groups' => collect(Filament::getNavigation())
                ->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()))
                ->values()
                ->all(),
        ];
    }
}
