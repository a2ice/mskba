<?php

namespace App\Filament\Backoffice\Resources\PageBuilder\Concerns;

use App\Modules\Content\Application\Services\PageBuilderSources;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

final class BuilderPlaceholderGuide
{
    public static function panel(bool $template = false): Section
    {
        $description = $template
            ? '[[page_content]] — вставить содержимое страницы в шаблон.'
            : 'Компоненты вставляются в HTML. Их идентификаторы не являются PHP/Blade-кодом.';

        return Section::make('Плейсхолдеры и источники')
            ->description($description)
            ->schema([
                Text::make('<mskba-component name="hero-slider"></mskba-component> — управляемый системный Hero Slider.'),
                Text::make('<mskba-component name="hero-banner"></mskba-component>')
                    ,
                Text::make('<mskba-component name="games-list" source="upcoming_games" limit="5"></mskba-component>')
                    ,
                Text::make('upcoming_games — '.PageBuilderSources::DESCRIPTIONS['upcoming_games']),
                Text::make('my_games — '.PageBuilderSources::DESCRIPTIONS['my_games']),
                Text::make('featured_teams — '.PageBuilderSources::DESCRIPTIONS['featured_teams']),
                Text::make('Сначала опубликуй шаблон и компоненты. Визуальный редактор будет добавлен позже.'),
            ]);
    }
}
