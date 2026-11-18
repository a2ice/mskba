<?php

namespace App\Modules\PlayInterest\Presentation\Console;

use App\Modules\PlayInterest\Application\Services\SynchronizePlayOpportunities;
use Illuminate\Console\Command;

final class PlayInterestMatchingCommand extends Command
{
    protected $signature = 'play-opportunities:sync {--max=400}';
    protected $description = 'Обновить группы взаимно совместимых игровых интересов';

    public function handle(SynchronizePlayOpportunities $matcher): int
    {
        $result=$matcher->sync((int)$this->option('max'));
        $this->components->info('Проверено: '.$result['intents']
            .'; совместимых кандидатов: '.$result['candidates']
            .'; затронуто групп: '.$result['groups_touched'].'.');
        return self::SUCCESS;
    }
}
