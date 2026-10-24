<?php

namespace App\Filament\Backoffice\Resources\PageBuilder;

use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPermissions;
use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPlaceholderGuide;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\CreateBuilderComponent;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderComponent;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\ListBuilderComponents;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use App\Modules\Media\Domain\Models\SiteMediaAsset;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class BuilderComponentResource extends Resource
{
    use BuilderPermissions;

    protected static ?string $model = PageBuilderComponent::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Сайт';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string { return 'Компоненты'; }
    public static function getModelLabel(): string { return 'компонент'; }
    public static function getPluralModelLabel(): string { return 'компоненты'; }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Компонент')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(150),
                TextInput::make('slug')->label('Плейсхолдер')->required()
                    ->regex('/\A[a-z][a-z0-9_-]{1,79}\z/D')->unique(ignoreRecord: true)
                    ->disabled(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider')
                    ->helperText('Пример: hero-banner. Для системных источников используй games-list.'),
                Textarea::make('description')->label('Краткое описание')->maxLength(500)->columnSpanFull(),
            ]),
            Section::make('Hero Slider · поведение')
                ->description('Настройки относятся только к системному компоненту hero-slider. Существующая главная не изменится до отдельной публикации страницы.')
                ->visible(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider')
                ->columns(4)
                ->schema([
                    Toggle::make('draft_options.autoplay')->label('Автоматическое переключение')->default(false),
                    TextInput::make('draft_options.interval_ms')->label('Интервал, мс')
                        ->numeric()->minValue(4000)->maxValue(30000)->default(7000),
                    Toggle::make('draft_options.show_arrows')->label('Стрелки')->default(true),
                    Toggle::make('draft_options.show_dots')->label('Индикаторы')->default(true),
                ]),
            Section::make('Слайды · порядок и содержимое')
                ->description('Слайды состоят из независимых элементов: заголовки, тексты, изображения, кнопки и группы ссылок. Положение отдельно на desktop/tablet/mobile.')
                ->visible(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider')
                ->schema([
                    Repeater::make('draft_slides')->label('Слайды')
                        ->itemLabel(fn (array $state): ?string => ($state['name'] ?? null) ?: ($state['title'] ?? 'Новый слайд'))
                        ->reorderable()->collapsible()->cloneable()
                        ->minItems(1)->maxItems(6)->defaultItems(0)
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('name')->label('Название слайда (для админки)')->maxLength(90),
                                Toggle::make('enabled')->label('Слайд включён')->default(true),
                                Hidden::make('layout')->default('layers'),
                                Select::make('mobile_layout')->label('Компоновка Mobile')
                                    ->native(false)->live()
                                    ->options([
                                        'absolute' => 'Свободная — координаты X/Y',
                                        'flow' => 'Последовательная — без пустот между элементами',
                                    ])->default('absolute')->required()
                                    ->helperText('Для текстовых слайдов выбирай последовательную: высота подстраивается под содержимое. Desktop и Tablet не меняются.'),
                            ]),
                            Section::make('Кому показывать слайд')
                                ->description('Условия проверяются сервером до выдачи HTML. Неавторизованные и неподходящие пользователи не получают скрытые слайды.')
                                ->collapsible()->collapsed()
                                ->columns(2)->schema([
                                    Select::make('audience.auth')->label('Авторизация')->native(false)
                                        ->options([
                                            'all' => 'Всем', 'guest' => 'Только гостям',
                                            'authenticated' => 'Только авторизованным',
                                        ])->default('all'),
                                    Select::make('audience.confirmation')->label('Статус аккаунта')->native(false)
                                        ->options([
                                            'any' => 'Любой', 'confirmed' => 'Подтверждён',
                                            'unconfirmed' => 'Не подтверждён',
                                        ])->default('any'),
                                    CheckboxList::make('audience.system_roles')->label('Системные роли (хотя бы одна)')
                                        ->options([
                                            'user' => 'Пользователь', 'editor' => 'Редактор',
                                            'moderator' => 'Модератор', 'admin' => 'Админ',
                                            'superadmin' => 'Суперадмин',
                                        ])->columns(2)->columnSpanFull(),
                                    CheckboxList::make('audience.participation_roles')->label('Роли участия (хотя бы одна)')
                                        ->options([
                                            'player' => 'Игрок', 'coach' => 'Тренер', 'referee' => 'Судья',
                                            'statistician' => 'Статист', 'media' => 'Медиа',
                                            'venue_related' => 'Представитель площадки', 'organizer' => 'Организатор',
                                        ])->columns(2)->columnSpanFull(),
                                    Text::make('Теги пользователей пока не реализованы: интерфейс появится после внедрения общей системы тегов для персонализации страниц и рекламы.'),
                                ]),
                            Section::make('Универсальный слайд')
                                ->visible(fn (Get $get): bool => $get('layout') === 'layers')
                                ->description('Фон и произвольные элементы — текст, изображения, кнопки. Перетаскивай элементы для изменения порядка; координаты в % холста.')
                                ->schema([
                                    Grid::make(2)->schema([
                                        FileUpload::make('background_image')->label('Фон · desktop')
                                            ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                                            ->disk('public')->directory('page-builder/hero')->visibility('public')->maxSize(4096),
                                        Select::make('background_image_site_asset')->label('Или из медиабиблиотеки · desktop')
                                            ->options(fn (): array => self::libraryOptions())->searchable()->native(false),
                                        FileUpload::make('background_image_mobile')->label('Фон · mobile')
                                            ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                                            ->disk('public')->directory('page-builder/hero')->visibility('public')->maxSize(3072),
                                        Select::make('background_image_mobile_site_asset')->label('Или из медиабиблиотеки · mobile')
                                            ->options(fn (): array => self::libraryOptions())->searchable()->native(false),
                                        Select::make('background_preset')->label('Встроенный фон (при отсутствии изображения)')
                                            ->native(false)->options(['indoor' => 'Баскетбольная арена'])
                                            ->helperText('Для старых слайдов, перенесённых из прежнего редактора. Загрузка изображения всегда имеет приоритет.'),
                                        Select::make('scrim')->label('Затемнение/осветление фона')
                                            ->native(false)->options(['none' => 'Нет', 'dark' => 'Тёмное', 'light' => 'Светлое'])
                                            ->default('none'),
                                        Select::make('background_effect')->label('Появление фона')->native(false)
                                            ->options(self::animationOptions())->default('fade'),
                                    ]),
                                    Section::make('Компоновка Mobile · отступы и высота')
                                        ->description('Применяется только в последовательном режиме: элементы идут по порядку, с заданными отступами в пикселях.')
                                        ->visible(fn (Get $get): bool => $get('mobile_layout') === 'flow')
                                        ->collapsible()->collapsed()->columns(3)
                                        ->schema([
                                            TextInput::make('mobile_padding_top')->label('Отступ сверху, px')
                                                ->numeric()->minValue(12)->maxValue(120)->default(38),
                                            TextInput::make('mobile_padding_bottom')->label('Отступ снизу, px')
                                                ->numeric()->minValue(48)->maxValue(160)->default(76),
                                            TextInput::make('mobile_min_height')->label('Минимальная высота, px')
                                                ->numeric()->minValue(360)->maxValue(780)->default(470)
                                                ->helperText('Если текста больше, слайдер вырастет автоматически.'),
                                        ]),
                                    Repeater::make('elements')->label('Элементы слайда')
                                        ->itemLabel(fn (array $state): ?string => ($state['name'] ?? null) ?: ($state['type'] ?? 'Новый элемент'))
                                        ->reorderable()->cloneable()->collapsible()
                                        ->minItems(1)->maxItems(16)->defaultItems(0)
                                        ->helperText('Для публикации нужен минимум один элемент: заголовок, текст, изображение, кнопка или группа ссылок.')
                                        ->schema([
                                            Grid::make(3)->schema([
                                                Select::make('type')->label('Тип элемента')->native(false)->live()
                                                    ->options([
                                                        'heading' => 'Заголовок', 'text' => 'Текст',
                                                        'image' => 'Изображение', 'button' => 'Кнопка',
                                                        'links' => 'Группа ссылок · хайлайты',
                                                    ])->default('text')->required(),
                                                TextInput::make('name')->label('Название элемента')->required()->maxLength(90),
                                                TextInput::make('class_name')->label('CSS-классы (до 3)')
                                                    ->maxLength(160)->regex('/\A[a-z][a-z0-9_-]{0,49}(?: [a-z][a-z0-9_-]{0,49}){0,2}\z/D')
                                                    ->helperText('Например: my-slide-title bg-lightblue. Без точек и CSS-кода.'),
                                            ]),
                                            Textarea::make('content')->label('Текст')->rows(2)->maxLength(400)
                                                ->visible(fn (Get $get): bool => in_array($get('type'), ['heading','text','button'], true))
                                                ->required(fn (Get $get): bool => in_array($get('type'), ['heading','text','button'], true)),
                                            Toggle::make('show_arrow')->label('Стрелка справа от текста кнопки')
                                                ->visible(fn (Get $get): bool => $get('type') === 'button')
                                                ->default(true),
                                            Repeater::make('links')->label('Ссылки с иконками')
                                                ->visible(fn (Get $get): bool => $get('type') === 'links')
                                                ->helperText('На desktop — горизонтальная группа, на tablet — прокручиваемая лента, на mobile скрыта (как в старом макете).')
                                                ->reorderable()->cloneable()->collapsible()
                                                ->minItems(1)->maxItems(8)->defaultItems(0)
                                                ->itemLabel(fn (array $state): ?string => $state['label'] ?? 'Новая ссылка')
                                                ->schema([
                                                    TextInput::make('label')->label('Подпись')->required()->maxLength(35),
                                                    TextInput::make('url')->label('Внутренняя ссылка')->required()->maxLength(220),
                                                    Select::make('icon')->label('Иконка')->native(false)->live()
                                                        ->options([
                                                            'court' => 'Площадка',
                                                            'trophy' => 'Кубок',
                                                            'users' => 'Участники',
                                                            'video' => 'Видео',
                                                            'chart' => 'Рейтинг',
                                                            'custom' => 'Загрузить свою',
                                                        ])->default('court')->required(),
                                                    FileUpload::make('image')->label('Своя иконка (PNG/WebP)')
                                                        ->visible(fn (Get $get): bool => $get('icon') === 'custom')
                                                        ->required(fn (Get $get): bool => $get('icon') === 'custom' && blank($get('image_site_asset')))
                                                        ->image()->acceptedFileTypes(['image/png', 'image/webp', 'image/jpeg', 'image/avif'])
                                                        ->disk('public')->directory('page-builder/hero')
                                                        ->visibility('public')->maxSize(1024),
                                                    Select::make('image_site_asset')->label('Или иконка из медиабиблиотеки')
                                                        ->visible(fn (Get $get): bool => $get('icon') === 'custom')
                                                        ->options(fn (): array => self::libraryOptions())->searchable()->native(false),
                                                ])->columns(2),
                                            TextInput::make('url')->label('Внутренняя ссылка кнопки')
                                                ->visible(fn (Get $get): bool => $get('type') === 'button')
                                                ->required(fn (Get $get): bool => $get('type') === 'button'),
                                            FileUpload::make('image')->label('Изображение')
                                                ->visible(fn (Get $get): bool => $get('type') === 'image')
                                                ->required(fn (Get $get): bool => $get('type') === 'image' && blank($get('image_site_asset')))
                                                ->image()->acceptedFileTypes(['image/jpeg','image/png','image/webp','image/avif'])
                                                ->disk('public')->directory('page-builder/hero')->visibility('public')->maxSize(4096),
                                            Select::make('image_site_asset')->label('Или изображение из медиабиблиотеки')
                                                ->visible(fn (Get $get): bool => $get('type') === 'image')
                                                ->options(fn (): array => self::libraryOptions())->searchable()->native(false),
                                            TextInput::make('alt')->label('Альтернативный текст изображения')
                                                ->maxLength(140)->visible(fn (Get $get): bool => $get('type') === 'image')
                                                ->helperText('Для декоративной картинки оставь пустым.'),
                                            Section::make('Положение слоя')->columns(3)->schema([
                                                TextInput::make('x')->label('Desktop X, %')->numeric()->minValue(0)->maxValue(100)->default(10),
                                                TextInput::make('y')->label('Desktop Y, %')->numeric()->minValue(0)->maxValue(100)->default(20),
                                                TextInput::make('width')->label('Desktop ширина, %')->numeric()->minValue(5)->maxValue(100)->default(50),
                                                TextInput::make('tablet_x')->label('Tablet X, %')->numeric()->minValue(0)->maxValue(100)
                                                    ->helperText('641–1050 px по ширине слайдера. Пусто = значение Desktop.'),
                                                TextInput::make('tablet_y')->label('Tablet Y, %')->numeric()->minValue(0)->maxValue(100)
                                                    ->helperText('Пусто = Desktop Y.'),
                                                TextInput::make('tablet_width')->label('Tablet ширина, %')->numeric()->minValue(5)->maxValue(100)
                                                    ->helperText('Пусто = ширина Desktop.'),
                                                TextInput::make('mobile_x')->label('Mobile X, %')->numeric()->minValue(0)->maxValue(100)->default(6),
                                                TextInput::make('mobile_y')->label('Mobile Y, %')->numeric()->minValue(0)->maxValue(100)->default(20),
                                                TextInput::make('mobile_width')->label('Mobile ширина, %')->numeric()->minValue(5)->maxValue(100)->default(88),
                                                Select::make('mobile_placement')->label('Mobile · расположение')
                                                    ->native(false)->options([
                                                        'flow' => 'В потоке — после предыдущего элемента',
                                                        'overlay' => 'Наложение — по координатам X/Y',
                                                        'hidden' => 'Скрыть на mobile',
                                                    ])->helperText('В последовательной компоновке X/Y работают только для наложения.'),
                                                TextInput::make('mobile_gap')->label('Mobile · отступ перед, px')
                                                    ->numeric()->minValue(0)->maxValue(120)
                                                    ->helperText('Работает в последовательном режиме только для элементов в потоке.'),

                                                TextInput::make('z_index')->label('Слой Z (2–7)')->numeric()->minValue(2)->maxValue(7)->default(4),
                                                Select::make('align')->label('Выравнивание')->native(false)->options([
                                                    'left' => 'Слева', 'center' => 'По центру', 'right' => 'Справа',
                                                ])->default('left'),
                                            ])->collapsible()->collapsed(),
                                            Section::make('Таймлайн и эффект')->columns(3)->schema([
                                                Select::make('animation')->label('Эффект')->native(false)
                                                    ->options(self::animationOptions())->default('fade'),
                                                TextInput::make('delay_ms')->label('Задержка, мс')
                                                    ->numeric()->minValue(0)->maxValue(5000)->default(0),
                                                TextInput::make('duration_ms')->label('Продолжительность, мс')
                                                    ->numeric()->minValue(100)->maxValue(3000)->default(700),
                                            ])->collapsible()->collapsed(),
                                        ]),
                                ]),
                        ]),
                ]),
            Section::make('HTML')->columns(1)
                ->hidden(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider')
                ->schema([
                    CodeEditor::make('draft_html')->label('HTML')->language(Language::Html)
                        ->required(fn (?PageBuilderComponent $record): bool => $record?->slug !== 'hero-slider')
                        ->columnSpanFull(),
                ])->columnSpanFull(),
            BuilderPlaceholderGuide::panel()->collapsible()
                ->hidden(fn (?PageBuilderComponent $record): bool => $record?->slug === 'hero-slider')
                ->columnSpanFull(),
        ]);
    }

    private static function libraryOptions(): array
    {
        return SiteMediaAsset::query()->orderBy('title')->get(['uuid', 'title'])
            ->pluck('title', 'uuid')->all();
    }

    private static function animationOptions(): array
    {
        return [
            'none' => 'Без анимации',
            'fade' => 'Fade-in',
            'slide-up' => 'Slide-up',
            'slide-down' => 'Slide-down',
            'slide-left' => 'Slide-left',
            'slide-right' => 'Slide-right',
            'zoom' => 'Zoom-in',
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Компонент')->searchable(),
            TextColumn::make('slug')->label('Плейсхолдер')->searchable(),
            TextColumn::make('published_at')->label('Опубликован')->dateTime('d.m.Y H:i')->placeholder('Черновик'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuilderComponents::route('/'),
            'create' => CreateBuilderComponent::route('/create'),
            'edit' => EditBuilderComponent::route('/{record}/edit'),
        ];
    }
}
