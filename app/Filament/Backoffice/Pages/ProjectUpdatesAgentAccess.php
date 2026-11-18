<?php

namespace App\Filament\Backoffice\Pages;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class ProjectUpdatesAgentAccess extends Page
{
    protected static ?string $slug = 'project-updates-agent-access';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Контент';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Доступ агента · Новости проекта';

    protected string $view = 'filament.backoffice.pages.project-updates-agent-access';

    public string $password = '';
    public ?string $plainTextToken = null;
    public ?string $expiresAt = null;

    public function getTitle(): string
    {
        return 'Доступ агента · Новости проекта';
    }

    public static function canAccess(): bool
    {
        $user = auth('web')->user();

        return $user instanceof User
            && $user->system_role === UserSystemRoleEnum::SUPERADMIN
            && $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed();
    }

    public function issue(): void
    {
        abort_unless(self::canAccess(), 403);
        $actor = auth('web')->user();
        $this->validate(['password' => ['required', 'string', 'max:255']]);
        $rateKey = 'project-update-agent-token:'.$actor->getKey();

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $this->addError('password', 'Слишком много попыток. Повтори позже.');
            return;
        }
        RateLimiter::hit($rateKey, 60);

        if (! Hash::check($this->password, $actor->getAuthPassword())) {
            $this->password = '';
            $this->addError('password', 'Неверный пароль.');
            return;
        }
        RateLimiter::clear($rateKey);

        $dev = User::query()->where('username', 'dev')
            ->where('system_role', UserSystemRoleEnum::SYSTEM)
            ->where('status', UserStatusEnum::CONFIRMED)
            ->whereNull('deleted_at')->first();

        if ($dev === null) {
            $this->password = '';
            $this->addError('password', 'Системный пользователь dev должен существовать и быть подтверждён.');
            return;
        }

        $expiresAt = now()->addDay();
        $token = $dev->createToken(
            'mskba-project-updates:filament-'.now()->format('YmdHis'),
            ['project-updates:write'],
            $expiresAt,
        );
        $this->plainTextToken = $token->plainTextToken;
        $this->expiresAt = $expiresAt->format('d.m.Y H:i');
        $this->password = '';
    }

    public function revokeTokens(): void
    {
        abort_unless(self::canAccess(), 403);
        User::query()->where('username', 'dev')
            ->where('system_role', UserSystemRoleEnum::SYSTEM)
            ->first()?->tokens()
            ->where('name', 'like', 'mskba-project-updates:%')
            ->delete();

        $this->plainTextToken = null;
        $this->expiresAt = null;
        $this->password = '';
    }
}
