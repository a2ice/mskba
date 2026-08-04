<?php

namespace App\Console\Commands;

use App\Modules\Telegram\Domain\Models\TelegramAccount;
use App\Modules\Telegram\Infrastructure\Jobs\SyncTelegramProfileAvatarJob;
use Illuminate\Console\Command;

final class SyncTelegramProfileAvatarsCommand extends Command
{
    protected $signature = 'telegram:sync-profile-avatars {--missing : Только аккаунты без активного локального аватара}';

    protected $description = 'Поставить в очередь синхронизацию аватаров Telegram-профилей';

    public function handle(): int
    {
        $query = TelegramAccount::query()->orderBy('id');

        if ($this->option('missing')) {
            $query->whereDoesntHave('user.profile.activeAvatar');
        }

        $queued = 0;
        $query->select('id')->chunkById(200, function ($accounts) use (&$queued): void {
            foreach ($accounts as $account) {
                SyncTelegramProfileAvatarJob::dispatch($account->id);
                $queued++;
            }
        });

        $this->info("Поставлено задач: {$queued}.");

        return self::SUCCESS;
    }
}
