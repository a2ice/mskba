<?php

namespace App\Filament\Backoffice\Resources\PageBuilder;

use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPermissions;
use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPlaceholderGuide;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\CreateBuilderPage;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderPage;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\ListBuilderPages;
use App\Modules\Content\Domain\Models\PageBuilderPage;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class BuilderPageResource extends Resource
{
    use BuilderPermissions;

    protected static ?string $model = PageBuilderPage::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Контент';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';
    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string { return 'Страницы'; }
    public static function getModelLabel(): string { return 'страница'; }
    public static function getPluralModelLabel(): string { return 'страницы'; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Страница')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(150),
                TextInput::make('path')->label('URL')->required()->unique(ignoreRecord: true)
                    ->regex('~\A/(?:[a-z0-9]+(?:-[a-z0-9]+)*)?\z~D')
                    ->helperText('Главная: / · Новая страница: /about. Системные маршруты защищены.'),
                Select::make('template_id')->label('Шаблон')
                    ->relationship('template', 'name')->searchable()->preload()->native(false),
            ]),
            Tabs::make('Редактор')->tabs([
                Tab::make('HTML и CSS')->schema([
                    Grid::make(3)->schema([
                        Section::make('Код')->columnSpan(2)->schema([
                            CodeEditor::make('draft_html')->label('HTML')->language(Language::Html)
                                ->required()->columnSpanFull(),
                            CodeEditor::make('draft_css')->label('CSS (локальный)')->language(Language::Css)
                                ->columnSpanFull()
                                ->helperText('Без JS, @import, URL и глобальных CSS-селекторов. Селекторы ограничены рамкой страницы.'),
                        ]),
                        BuilderPlaceholderGuide::panel()->columnSpan(1),
                    ]),
                ]),
                Tab::make('SEO')->schema([
                    TextInput::make('meta_title')->label('Meta title')->maxLength(180),
                    TextInput::make('meta_description')->label('Meta description')->maxLength(500),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Страница')->searchable(),
            TextColumn::make('path')->label('URL')->searchable()->copyable(),
            TextColumn::make('published_at')->label('Опубликовано')->dateTime('d.m.Y H:i')->placeholder('Черновик'),
            TextColumn::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuilderPages::route('/'),
            'create' => CreateBuilderPage::route('/create'),
            'edit' => EditBuilderPage::route('/{record}/edit'),
        ];
    }
}
