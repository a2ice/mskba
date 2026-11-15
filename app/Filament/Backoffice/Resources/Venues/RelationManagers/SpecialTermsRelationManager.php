<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Filament\Backoffice\Resources\Venues\VenueResource;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\PlayInterest\Domain\Models\VenueGroupOffer;
use App\Modules\VenueBooking\Application\Services\VenueSpecialTermsManager;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class SpecialTermsRelationManager extends RelationManager
{
    protected static string $relationship = 'specialTerms';

    protected static ?string $title = 'Специальные условия';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return VenueResource::authorized() && app(VenueSpecialTermsManager::class)->canManage(auth('web')->user(), $ownerRecord, administration: true);
    }

    public function table(Table $table): Table
    {
        $manager = fn () => app(VenueSpecialTermsManager::class);

        return $table->columns([
            TextColumn::make('title')->label('Название'),
            TextColumn::make('audience')->label('Тип'),
            TextColumn::make('status')->label('Статус'),
            TextColumn::make('used_count')->label('Использовано'),
            TextColumn::make('description')->label('Причина')->wrap(),
        ])->headerActions([
            Action::make('createSpecial')->label('Создать условия')->schema([
                TextInput::make('title')->label('Название')->required()->maxLength(120),
                Textarea::make('description')->label('Причина (видна получателю)')->required()->maxLength(1000),
                Select::make('audience')->label('Тип')->options(['PUBLIC' => 'Публичный купон', 'USER_BOUND' => 'Персональные условия'])->required()->default('USER_BOUND'),
                Select::make('recipient_ids')->label('Получатели')->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->where('status', UserStatusEnum::CONFIRMED->value)->where('username', 'like', '%'.$search.'%')
                        ->orderBy('username')->limit(25)->pluck('username', 'id')->all())
                    ->getOptionLabelsUsing(fn (array $values): array => User::query()->whereIn('id', $values)->pluck('username', 'id')->all()),
                Toggle::make('auto_apply')->label('Применять автоматически к получателям')->default(false),
                TextInput::make('code')->label('Код (обязателен для публичного купона)')->maxLength(32),
                Select::make('discount_type')->label('Способ расчёта')->options(['percent' => 'Проценты', 'fixed' => 'Скидка, копейки', 'fixed_total' => 'Итоговая цена, копейки'])->required(),
                TextInput::make('discount_value')->label('Значение')->integer()->minValue(1)->required(),
                Select::make('venue_court_id')->label('Зал')->options(fn () => $this->getOwnerRecord()->courts()->pluck('name', 'id')->all())->searchable(),
                Select::make('scope')->label('Зона')->options(['whole' => 'Весь зал', 'half_a' => 'Половина А', 'half_b' => 'Половина Б']),
                DateTimePicker::make('valid_from')->label('Начало действия')->seconds(false),
                DateTimePicker::make('valid_until')->label('Окончание (пусто = бессрочно)')->seconds(false),
                DatePicker::make('booking_date_from')->label('Дата аренды с'),
                DatePicker::make('booking_date_to')->label('Дата аренды по'),
                TimePicker::make('time_from')->label('Время с')->seconds(false),
                TimePicker::make('time_to')->label('Время до')->seconds(false),
                CheckboxList::make('weekdays')->label('Дни недели')->options([1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс']),
                TextInput::make('minimum_amount_minor')->label('Минимальная сумма, копейки')->integer()->minValue(0),
                TextInput::make('max_total_uses')->label('Общий лимит (пусто = без лимита)')->integer()->minValue(1),
                TextInput::make('max_uses_per_account')->label('Лимит на аккаунт')->integer()->minValue(1),
            ])->action(fn (array $data) => $manager()->create($this->getOwnerRecord(), auth('web')->user(), $data, administration: true)),
            Action::make('accountSpecials')->label('Предпросмотр и история')->url(fn () => route('account.venues.special-terms.index', $this->getOwnerRecord()->id)),
        ])->recordActions(array_map(fn (string $action, string $label) => Action::make($action)->label($label)
            ->visible(fn (VenueGroupOffer $record) => match ($action) {
                'publish' => in_array($record->status, ['draft', 'paused'], true),
                'pause' => $record->status === 'active',
                default => $record->status !== 'revoked',
            })->action(fn (VenueGroupOffer $record) => $manager()->transition($this->getOwnerRecord(), auth('web')->user(), $record, $action, administration: true)),
            ['publish', 'pause', 'revoke'], ['Опубликовать', 'Приостановить', 'Отозвать']));
    }
}
