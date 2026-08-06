<?php

namespace App\Console\Commands;

use App\Modules\Event\Application\Services\LegacyGamesMigrationService;
use Illuminate\Console\Command;

class MigrateLegacyGamesCommand extends Command
{
    protected $signature = 'games:migrate-legacy {--apply : Выполнить перенос после успешного аудита}';

    protected $description = 'Проверить и перенести legacy Event-игры в сущности Game';

    public function handle(LegacyGamesMigrationService $service): int
    {
        $apply = (bool) $this->option('apply');
        $result = $service->run($apply);

        $this->table(['Кандидаты', 'Уже перенесены', 'Перенесены сейчас', 'Конфликты'], [[
            $result['candidates'],
            $result['existing'],
            $result['migrated'],
            count($result['conflicts']),
        ]]);

        foreach ($result['conflicts'] as $conflict) {
            $this->error($conflict);
        }

        if ($result['conflicts'] !== []) {
            $this->warn('Перенос не завершён: устраните конфликты и повторите аудит.');

            return self::FAILURE;
        }

        $this->info($apply
            ? 'Legacy-игры перенесены. Повторный запуск безопасен.'
            : 'Конфликтов нет. Для переноса повторите команду с --apply.');

        return self::SUCCESS;
    }
}
