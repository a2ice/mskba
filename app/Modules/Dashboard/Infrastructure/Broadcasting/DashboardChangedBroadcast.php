<?php
namespace App\Modules\Dashboard\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Public invalidation only: never exposes content, targeting or user IDs. */
final class DashboardChangedBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;
    public function broadcastOn(): Channel { return new Channel('account-dashboard'); }
    public function broadcastAs(): string { return 'dashboard.changed'; }
    public function broadcastWith(): array { return ['refresh' => true]; }
}
