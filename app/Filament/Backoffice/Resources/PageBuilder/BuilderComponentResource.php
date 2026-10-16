<?php

namespace App\Filament\Backoffice\Resources\PageBuilder;

use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPermissions;
use App\Filament\Backoffice\Resources\PageBuilder\Concerns\BuilderPlaceholderGuide;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\CreateBuilderComponent;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\EditBuilderComponent;
use App\Filament\Backoffice\Resources\PageBuilder\Pages\ListBuilderComponents;
use App\Modules\Content\Domain\Models\PageBuilderComponent;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class BuilderComponentResource extends Resource
{
    use BuilderPermissions;

    protected static ?string $model = PageBuilderComponent::class;
    protected static string|\UnitEnum|null $navigationGroup = 'Дизайн';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string { return 'Компоненты'; }
    public static function getModelLabel(): string { return 'компонент'; }
    public static function getPluralModelLabel(): string { return 'компоненты'; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Компонент')->columns(2)->schema([
                TextInput::make('name')->label('Название')->required()->maxLength(150),
                TextInput::make('slug')->label('Плейсхолдер')->required()
                    ->regex('/\A[a-z][a-z0-9_-]{1,79}\z/D')->unique(ignoreRecord: true)
                    ->helperText('Пример: hero-banner. Для системных источников используй games-list.'),
                Textarea::make('description')->label('Краткое описание')->maxLength(500)->columnSpanFull(),
            ]),
            Grid::make(3)->schema([
                Section::make('HTML и CSS')->columnSpan(2)->schema([
                    CodeEditor::make('draft_html')->label('HTML')->language(Language::Html)->required(),
                    CodeEditor::make('draft_css')->label('CSS')->language(Language::Css),
                ]),
                BuilderPlaceholderGuide::panel()->columnSpan(1),
            ]),
        ]);
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
