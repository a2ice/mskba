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
                Text::make('Верстка: <div class="container">...</div> — тот же ограниченный контейнер, что у шапки сайта (до 1280 px).'),
                Text::make('Секция во всю ширину: <section class="my-wide-section">...</section> — без обёртки .container.'),
                Text::make('Можно чередовать boxed- и full-width-секции. .mskba-page-builder и базовая секция не задают отступов и промежутков: указывай их в CSS или обёртках отдельных секций.'),
                Text::make('Отступы в разметке: mt-3 — сверху 16px, mb-4 — снизу 24px, px-3 — горизонтальный внутренний отступ 16px, py-5 — вертикальный 48px.'),
                Text::make('Поддерживаются m-0…m-5, mt/mb/ml/mr/ms/me/mx/my-0…5, p-0…p-5, pt/pb/pl/pr/ps/pe/px/py-0…5, gap-0…5, mx-auto.'),
                Text::make('Пример секции: <section class="py-5"><div class="container"><h2 class="mb-3">Заголовок</h2>…</div></section>. Классы действуют только в Page Builder.'),
                Text::make('Фоны: bg-lightblue, bg-blue, bg-darkblue; аналогично grey, orange, green, red, yellow — 3 сплошных фона для каждого цвета.'),
                Text::make('Градиенты: bg-grad-lightblue-darkblue или bg-grad-darkblue-lightblue; также доступны light→base, base→light, base→dark, dark→base (всего 6 на цвет).'),
                Text::make('Семантические фоны: bg-page, bg-surface, bg-surface-raised, bg-field, bg-accent, bg-transparent. Все классы зависят от выбранной темы и действуют только внутри Page Builder.'),
                Text::make('Сначала опубликуй шаблон и компоненты. Визуальный редактор будет добавлен позже.'),
            ]);
    }
}
