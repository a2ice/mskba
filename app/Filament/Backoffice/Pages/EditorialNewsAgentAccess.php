<?php

namespace App\Filament\Backoffice\Pages;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class EditorialNewsAgentAccess extends Page
{
    protected static ?string $slug = 'editorial-news-agent-access';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Контент';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Доступ агента · Публикация новостей';

    protected string $view = 'filament.backoffice.pages.editorial-news-agent-access';

    public string $password = '';

    public bool $allowPublish = false;
    public ?string $plainTextToken = null;
    public ?string $expiresAt = null;

    public function getTitle(): string
    {
        return 'Доступ агента · Публикация новостей';
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
        $rateKey = 'editorial-news-agent-token:'.$actor->getKey();

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
            ->whereNull('deleted_at')->first();

        if ($dev === null || $dev->isBlocked()) {
            $this->password = '';
            $this->addError('password', 'Системный пользователь dev должен существовать, иметь роль system и не быть заблокирован.');
            return;
        }

        $expiresAt = now()->addDay();
        $token = $dev->createToken(
            'mskba-editorial-news:filament-'.now()->format('YmdHis'),
            $this->allowPublish ? ['editorial-news:write', 'editorial-news:publish'] : ['editorial-news:write'],
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
            ->where('name', 'like', 'mskba-editorial-news:%')
            ->delete();

        $this->plainTextToken = null;
        $this->expiresAt = null;
        $this->password = '';
    }
}
