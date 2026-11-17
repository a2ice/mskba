<?php

namespace App\Filament\Backoffice\Pages;

use App\Modules\Identity\Application\Services\BackofficePermissionManager;
use App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum as Permission;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class PageBuilderAgentAccess extends Page
{
    protected static ?string $slug = 'page-builder-agent-access';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Разработка';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Доступ агента · Page Builder';

    protected string $view = 'filament.backoffice.pages.page-builder-agent-access';

    public string $password = '';

    public ?string $plainTextToken = null;

    public ?string $expiresAt = null;

    public function getTitle(): string
    {
        return 'Доступ агента · Page Builder';
    }

    public static function canAccess(): bool
    {
        $user = auth('web')->user();
        if (config('app.env') !== 'staging'
            || rtrim((string) config('app.url'), '/') !== 'https://dev.mskba.ru'
            || ! $user instanceof User
            || $user->system_role !== UserSystemRoleEnum::SUPERADMIN
            || ! $user->isConfirmed() || $user->isBlocked()) {
            return false;
        }

        $manager = app(BackofficePermissionManager::class);
        foreach ([
            Permission::BACKOFFICE_ACCESS,
            Permission::BACKOFFICE_BUILDER_VIEW,
            Permission::BACKOFFICE_BUILDER_EDIT,
            Permission::BACKOFFICE_BUILDER_PUBLISH,
            Permission::BACKOFFICE_BUILDER_CSS_EDIT,
            Permission::BACKOFFICE_BUILDER_CSS_PUBLISH,
            Permission::BACKOFFICE_BUILDER_JS_EDIT,
            Permission::BACKOFFICE_BUILDER_JS_PUBLISH,
        ] as $permission) {
            if (! $manager->allows($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function issue(): void
    {
        abort_unless(self::canAccess(), 403);
        $user = auth('web')->user();
        $this->validate(['password' => ['required', 'string', 'max:255']]);
        $rateKey = 'page-builder-agent-token:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $this->addError('password', 'Превышено число попыток. Попробуй позже.');

            return;
        }
        RateLimiter::hit($rateKey, 60);

        if (! Hash::check($this->password, $user->getAuthPassword())) {
            $this->password = '';
            $this->addError('password', 'Неверный пароль.');

            return;
        }
        RateLimiter::clear($rateKey);

        $expiresAt = now()->addDay();
        $token = $user->createToken(
            'mskba-pagebuilder-agent:filament-'.now()->format('YmdHis'),
            ['page-builder:read', 'page-builder:write', 'page-builder:publish'],
            $expiresAt,
        );

        $this->plainTextToken = $token->plainTextToken;
        $this->expiresAt = $expiresAt->format('d.m.Y H:i');
        $this->password = '';
    }

    public function revokeTokens(): void
    {
        abort_unless(self::canAccess(), 403);
        $user = auth('web')->user();

        $user->tokens()
            ->where('name', 'like', 'mskba-pagebuilder-agent:%')
            ->delete();

        $this->plainTextToken = null;
        $this->expiresAt = null;
        $this->password = '';
    }
}
