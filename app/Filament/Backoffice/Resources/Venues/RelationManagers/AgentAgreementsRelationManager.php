<?php

namespace App\Filament\Backoffice\Resources\Venues\RelationManagers;

use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueAgentAgreementService;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\VenueAgentAgreement;
use App\Modules\VenueBooking\Application\Services\MinorAmountParser;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class AgentAgreementsRelationManager extends VenueContractsRelationManager
{
    protected static string $relationship = 'agentAgreements';
    protected static ?string $title = 'Агентские договоры';

    private const STATUSES = [
        'proposed' => 'Ожидает акцепта', 'accepted' => 'Ожидает проверки',
        'verified' => 'Ожидает активации', 'active' => 'Действует',
        'declined' => 'Отклонён', 'withdrawn' => 'Отозван',
        'replaced' => 'Заменён', 'deactivated' => 'Прекращён',
    ];

    private function service(): VenueAgentAgreementService
    {
        return app(VenueAgentAgreementService::class);
    }

    private function reason(): Textarea
    {
        return Textarea::make('reason')->label('Причина')->required()->minLength(10)->maxLength(500);
    }

    private function rate(VenueAgentAgreement $record): string
    {
        $money = app(MinorAmountParser::class);

        return match ($record->mode) {
            'percent' => $money->format($record->value, 'RUB').'%',
            'fixed' => $money->format($record->value, $record->currency).' '.$money->currencyLabel($record->currency),
            default => 'Без комиссии',
        };
    }

    private function permissionOptions(): array
    {
        return collect(VenueMembershipAccessLevelEnum::AGENT->allowedPermissions())
            ->mapWithKeys(fn (VenuePermissionEnum $permission) => [$permission->value => $permission->label()])->all();
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['user', 'contract']))
            ->columns([
                TextColumn::make('id')->label('№')->sortable(),
                TextColumn::make('user.username')->label('Агент')->searchable(),
                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state),
                TextColumn::make('rate')->label('Ставка')->state(fn (VenueAgentAgreement $r): string => $this->rate($r)),
                TextColumn::make('proposed_at')->label('Предложен')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('accepted_at')->label('Акцепт')->dateTime('d.m.Y H:i')->placeholder('—'),
                TextColumn::make('activated_at')->label('Активирован')->dateTime('d.m.Y H:i')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->label('Статус')->options(self::STATUSES)])
            ->emptyStateHeading('Агентских договоров пока нет')
            ->headerActions([
                Action::make('proposeAgreement')->label('Предложить договор')->icon('heroicon-o-user-plus')
                    ->visible(fn (): bool => $this->mutableVenue())
                    ->schema([
                        $this->candidateField(),
                        Select::make('mode')->label('Способ расчёта')->options(['percent'=>'Процент','fixed'=>'Фиксированная сумма'])->required()->default('percent'),
                        TextInput::make('value_text')->label('Ставка, % или сумма')->required()->maxLength(32)->default('0'),
                        CheckboxList::make('permissions')->label('Полномочия после активации')->options($this->permissionOptions())->columns(2),
                        Textarea::make('terms')->label('Условия договора')->required()->minLength(10)->maxLength(4000),
                    ])
                    ->action(fn (array $data) => $this->perform(function () use ($data): void {
                        $this->service()->propose($this->getOwnerRecord(), User::query()->findOrFail($data['user_id']),
                            $this->actor(), $data['mode'], $data['value_text'],
                            array_map(fn (string $code) => VenuePermissionEnum::from($code), $data['permissions'] ?? []), $data['terms']);
                    }, 'Предложение создано')),
            ])
            ->recordActions([
                Action::make('details')->label('Условия')->icon('heroicon-o-document-text')
                    ->modalHeading('Условия и история агентского договора')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Закрыть')
                    ->modalContent(fn (VenueAgentAgreement $record) => view('filament.backoffice.venue-agent-agreement-details', [
                        'agreement' => $record, 'rate' => $this->rate($record),
                        'status' => self::STATUSES[$record->status] ?? $record->status,
                        'permissions' => array_map(fn (string $code) => VenuePermissionEnum::tryFrom($code)?->label() ?? $code, $record->proposed_permissions),
                    ])),
                Action::make('verifyAgreement')->label('Проверить')->icon('heroicon-o-shield-check')
                    ->visible(fn (VenueAgentAgreement $r): bool => $this->mutableVenue() && $r->status === 'accepted')
                    ->schema([
                        TextInput::make('evidence_reference')->label('Документ / основание')->required()->minLength(5)->maxLength(500),
                        Textarea::make('reason')->label('Результат проверки')->required()->minLength(10)->maxLength(2000),
                    ])
                    ->action(fn (VenueAgentAgreement $record, array $data) => $this->perform(
                        fn () => $this->service()->verify($this->getOwnerRecord(), $record, $this->actor(), $data['evidence_reference'], $data['reason']), 'Основание проверено')),
                Action::make('activateAgreement')->label('Активировать')->icon('heroicon-o-check-circle')->requiresConfirmation()
                    ->modalDescription('Активация выдаёт согласованные права и включает агента в новые финансовые расчёты.')
                    ->visible(fn (VenueAgentAgreement $r): bool => $this->mutableVenue() && $r->status === 'verified'
                        && $this->getOwnerRecord()->commissionPolicy()->value('selected_agent_id') === null)
                    ->action(fn (VenueAgentAgreement $record) => $this->perform(
                        fn () => $this->service()->activate($this->getOwnerRecord(), $record, $this->actor()), 'Договор активирован')),
                Action::make('replaceAgreement')->label('Заменить агента')->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (VenueAgentAgreement $r): bool => $this->mutableVenue() && $r->status === 'active')
                    ->modalDescription('Прежний договор останется в истории. Открытые обязательства блокируют замену.')
                    ->schema([
                        Select::make('next_id')->label('Проверенный договор')->required()->searchable()
                            ->options(fn (): array => $this->getOwnerRecord()->agentAgreements()->where('status','verified')
                                ->with('user')->get()->mapWithKeys(fn (VenueAgentAgreement $r) => [$r->id => '№'.$r->id.' — '.($r->user?->username ?? '#'.$r->user_id)])->all()),
                        $this->reason(),
                    ])
                    ->action(fn (VenueAgentAgreement $record, array $data) => $this->perform(
                        fn () => $this->service()->replace($this->getOwnerRecord(), $record,
                            $this->getOwnerRecord()->agentAgreements()->findOrFail($data['next_id']), $this->actor(), $data['reason']), 'Агент заменён')),
                Action::make('deactivateAgreement')->label('Прекратить')->color('danger')->icon('heroicon-o-x-circle')
                    ->visible(fn (VenueAgentAgreement $r): bool => $r->status === 'active')
                    ->modalDescription('История и финансовые снимки сохраняются. Открытые обязательства блокируют прекращение.')
                    ->schema([$this->reason()])
                    ->action(fn (VenueAgentAgreement $record, array $data) => $this->perform(
                        fn () => $this->service()->deactivate($this->getOwnerRecord(), $record, $this->actor(), $data['reason']), 'Договор прекращён')),
                Action::make('withdrawAgreement')->label('Отозвать предложение')->color('danger')
                    ->visible(fn (VenueAgentAgreement $r): bool => in_array($r->status, ['proposed','accepted','verified'], true))
                    ->schema([$this->reason()])
                    ->action(fn (VenueAgentAgreement $record, array $data) => $this->perform(
                        fn () => $this->service()->close($this->getOwnerRecord(), $record, $this->actor(), $data['reason']), 'Предложение отозвано')),
            ])->defaultSort('id', 'desc');
    }
}
