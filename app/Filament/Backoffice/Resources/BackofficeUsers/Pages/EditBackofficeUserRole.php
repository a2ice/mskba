<?php

namespace App\Filament\Backoffice\Resources\BackofficeUsers\Pages;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Contract\Domain\Enums\ContractMembershipScopeTypeEnum;
use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Event\Domain\Enums\GameStatusEnum;
use App\Modules\Event\Domain\Models\EventParticipant;
use App\Modules\Event\Domain\Models\Game;
use App\Modules\Identity\Application\UseCases\AdminAddPlayerToTeamHandler;
use App\Modules\Identity\Application\UseCases\AdminEditParticipationRoleHandler;
use App\Modules\Identity\Application\UseCases\AdminEditPlayerGameAttributesHandler;
use App\Modules\Identity\Application\UseCases\AdminEditPlayerProfileHandler;
use App\Modules\Identity\Domain\Enums\Participation\PlayerBodyTypeEnum;
use App\Modules\Identity\Domain\Enums\Participation\PlayerPositionEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\Participation\PlayerSelfAssessment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\Models\UserParticipationRole;
use App\Modules\Team\Domain\Models\Team;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class EditBackofficeUserRole extends Page
{
    protected static string $resource = BackofficeUserResource::class;

    protected string $view = 'filament.backoffice.pages.edit-backoffice-user-role';

    public int $userId;
    public int $assignmentId;
    public string $tab = 'physical';
    public ?string $roleComment = null;
    public ?string $height = null;
    public ?string $weight = null;
    public ?string $bodyType = null;
    public ?string $experienceYear = null;
    public ?string $playerComment = null;
    /** @var list<string> */
    public array $positions = [];
    /** @var array<string, int|string|null> */
    public array $skills = [];
    public string $teamSearch = '';

    private const TABS = ['physical', 'game', 'teams', 'events', 'games'];

    public function mount(string|int $record, string|int $participationRole): void
    {
        $user = BackofficeUserResource::getEloquentQuery()->findOrFail($record);
        abort_unless(BackofficeUserResource::canEdit($user), 403);
        $assignment = $user->participationRoles(false)->findOrFail($participationRole);
        $this->userId = $user->id;
        $this->assignmentId = $assignment->id;
        $this->tab = in_array(request()->query('tab'), self::TABS, true)
            ? (string) request()->query('tab') : 'physical';
        $this->loadFields();
    }

    public function editedUser(): User
    {
        $user = BackofficeUserResource::getEloquentQuery()->findOrFail($this->userId);
        abort_unless(BackofficeUserResource::canEdit($user), 403);

        return $user;
    }

    public function assignment(): UserParticipationRole
    {
        return $this->editedUser()->participationRoles(false)->findOrFail($this->assignmentId);
    }

    public function getTitle(): string
    {
        return 'Роль: '.$this->assignment()->role->label().' — '.$this->editedUser()->username;
    }

    public function getBreadcrumbs(): array
    {
        $user = $this->editedUser();

        return [
            BackofficeUserResource::getUrl('index') => 'Пользователи',
            BackofficeUserResource::getUrl('edit', ['record' => $user]) => $user->username,
            $this->assignment()->role->label(),
        ];
    }

    public function playerEnabled(): bool
    {
        $assignment = $this->assignment();

        return $assignment->role === UserParticipationRoleEnum::PLAYER
            && $assignment->status === UserParticipationRoleStatusEnum::ACTIVE;
    }

    private function loadFields(): void
    {
        $user = $this->editedUser();
        $assignment = $this->assignment();
        $this->roleComment = $assignment->comment;
        if ($assignment->role !== UserParticipationRoleEnum::PLAYER) {
            return;
        }
        $profile = $user->playerProfile()->with('positions', 'selfAssessment')->first();
        $this->height = $profile?->height_cm === null ? null : (string) $profile->height_cm;
        $this->weight = $profile?->weight_kg === null ? null : (string) $profile->weight_kg;
        $this->bodyType = $profile?->body_type?->value;
        $this->experienceYear = $profile?->experience_started_year === null ? null : (string) $profile->experience_started_year;
        $this->playerComment = $profile?->comment;
        $this->positions = $profile?->positions?->map(fn ($item) => $item->position->value)->all() ?? [];
        $this->skills = [];
        foreach (PlayerSelfAssessment::SKILLS as $key => $label) {
            $this->skills[$key] = $profile?->selfAssessment?->$key;
        }
    }

    public function teamMemberships(): Collection
    {
        $user = $this->editedUser();
        return ContractMembership::query()
            ->with('contract')
            ->where('scope_type', ContractMembershipScopeTypeEnum::TEAM->value)
            ->whereIn('user_id', $user->identityIds())
            ->latest('id')->limit(100)->get();
    }

    public function teamNames(): array
    {
        $ids = $this->teamMemberships()->pluck('scope_id')->unique()->all();
        return Team::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    public function searchedTeams(): Collection
    {
        $search = trim($this->teamSearch);
        if (mb_strlen($search) < 2) {
            return new Collection;
        }
        $existing = $this->teamMemberships()->pluck('scope_id')->all();
        return Team::query()->competitionEligible()
            ->whereNotIn('id', $existing)
            ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
            ->orderBy('name')->limit(10)->get();
    }

    public function eventParticipations(): Collection
    {
        return EventParticipant::query()->with('event.venue')
            ->whereIn('user_id', $this->editedUser()->identityIds())
            ->latest('id')->limit(50)->get();
    }

    public function completedGames(): Collection
    {
        $identities = $this->editedUser()->identityIds();
        return Game::query()
            ->where('status', GameStatusEnum::COMPLETED->value)
            ->where(function ($query) use ($identities): void {
                $query->whereHas('rosterEntries', fn ($roster) => $roster->whereIn('user_id', $identities))
                    ->orWhereHas('playerStatistics', fn ($stats) => $stats->whereIn('user_id', $identities));
            })
            ->with(['event', 'playerStatistics' => fn ($query) => $query->whereIn('user_id', $identities)])
            ->orderByDesc('completed_at')->orderByDesc('id')->limit(50)->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveRoleComment')
                ->label('Подтвердить изменение комментария')
                ->extraAttributes(['style' => 'display:none'])
                ->modalSubmitActionLabel('Подтвердить')
                ->schema([Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $data): void {
                    $assignment = $this->assignment();
                    app(AdminEditParticipationRoleHandler::class)->handle(
                        auth('web')->user(), $this->editedUser(), $assignment->id,
                        $assignment->status->value, trim((string) $this->roleComment) ?: null,
                        $data['reason'],
                    );
                    $this->loadFields();
                }),
            Action::make('savePhysical')
                ->label('Подтвердить физические данные')
                ->extraAttributes(['style' => 'display:none'])
                ->modalSubmitActionLabel('Подтвердить')
                ->schema([Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $data): void {
                    abort_unless($this->playerEnabled(), 403);
                    $values = [
                        'height_cm' => $this->height === '' || $this->height === null ? null : $this->height,
                        'weight_kg' => $this->weight === '' || $this->weight === null ? null : $this->weight,
                        'body_type' => $this->bodyType === '' ? null : $this->bodyType,
                        'experience_started_year' => $this->experienceYear === '' || $this->experienceYear === null ? null : $this->experienceYear,
                        'comment' => $this->playerComment === '' ? null : $this->playerComment,
                    ];
                    // Existing profile stores weight as decimal(1); avoid a spurious
                    // change when the editable value is the same integer weight.
                    $profile = $this->editedUser()->playerProfile;
                    if ($profile?->weight_kg !== null && $values['weight_kg'] !== null
                        && (float) $profile->weight_kg === (float) $values['weight_kg']) {
                        unset($values['weight_kg']);
                    }
                    app(AdminEditPlayerProfileHandler::class)->handle(
                        auth('web')->user(), $this->editedUser(), $values, $data['reason'],
                    );
                    $this->loadFields();
                }),
            Action::make('saveGame')
                ->label('Подтвердить игровые характеристики')
                ->extraAttributes(['style' => 'display:none'])
                ->modalSubmitActionLabel('Подтвердить')
                ->schema([Textarea::make('reason')->label('Основание изменения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $data): void {
                    abort_unless($this->playerEnabled(), 403);
                    app(AdminEditPlayerGameAttributesHandler::class)->handle(
                        auth('web')->user(), $this->editedUser(),
                        ['positions' => $this->positions, 'self_assessment' => $this->skills],
                        $data['reason'],
                    );
                    $this->loadFields();
                }),
            Action::make('addToTeam')
                ->label('Включить игрока в команду')
                ->extraAttributes(['style' => 'display:none'])
                ->modalHeading('Административное включение в команду')
                ->modalDescription('Операция создаёт активное членство и обновляет резервный состав. Доступна только суперадминистратору и фиксируется в аудите.')
                ->modalSubmitActionLabel('Подтвердить')
                ->schema([Textarea::make('reason')->label('Основание включения')->required()->minLength(10)->maxLength(500)])
                ->action(function (array $arguments, array $data): void {
                    abort_unless($this->playerEnabled(), 403);
                    $team = Team::query()->findOrFail((int) ($arguments['team_id'] ?? 0));
                    app(AdminAddPlayerToTeamHandler::class)->handle(
                        auth('web')->user(), $this->editedUser(), $team, $data['reason'],
                    );
                    $this->teamSearch = '';
                }),
        ];
    }
}
