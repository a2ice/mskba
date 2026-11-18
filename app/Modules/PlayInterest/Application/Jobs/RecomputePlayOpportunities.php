<?php

namespace App\Modules\PlayInterest\Application\Jobs;

use App\Modules\PlayInterest\Application\Services\SynchronizePlayOpportunities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class RecomputePlayOpportunities implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function handle(SynchronizePlayOpportunities $matcher): void
    {
        // Concurrent saves collapse to a single globally serialized refresh.
        $lock=Cache::lock('play-opportunities:refresh',240);
        if(!$lock->get())return;
        try{$matcher->sync(400);}
        finally{$lock->release();}
    }
}
