<?php

namespace App\Filament\Backoffice\Pages;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Filament\Pages\Page;

final class EnvironmentStatePage extends Page
{
    protected static ?string $slug = 'environment-state';
    protected static ?string $navigationLabel = 'Состояние окружений';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';
    protected static string|\UnitEnum|null $navigationGroup = 'Система';
    protected string $view = 'filament.backoffice.pages.environment-state';

    public static function canAccess(): bool
    {
        $user = auth('web')->user();

        return $user instanceof User
            && $user->system_role === UserSystemRoleEnum::SUPERADMIN
            && $user->isConfirmed() && ! $user->isBlocked() && ! $user->trashed();
    }

    public function getTitle(): string
    {
        return 'Состояние окружений';
    }

    protected function getViewData(): array
    {
        abort_unless(self::canAccess(), 403);
        $directory = storage_path('app/private/environment-checkpoints');
        $read = static function (string $path): ?array {
            if (! is_readable($path)) {
                return null;
            }
            $raw = file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;

            return is_array($data) && ($data['schema'] ?? null) === 1
                && is_string($data['git_sha'] ?? null)
                && preg_match('/^[a-f0-9]{40}$/D', $data['git_sha'])
                && is_string($data['functional_sha256'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/D', $data['functional_sha256'])
                ? $data : null;
        };
        $history = [];
        foreach (glob($directory.'/*.json') ?: [] as $path) {
            if (basename($path) === 'latest.json') {
                continue;
            }
            if ($data = $read($path)) {
                $history[] = $data;
            }
        }
        usort($history, static fn (array $a, array $b): int =>
            strcmp((string) ($b['verified_at'] ?? ''), (string) ($a['verified_at'] ?? '')));

        return [
            'checkpoint' => $read($directory.'/latest.json'),
            'history' => array_slice($history, 0, 20),
            'environment' => config('app.env'),
            'comparisonUrl' => 'https://github.com/a2ice/mskba/actions/workflows/environment-checkpoints.yml',
        ];
    }
}
