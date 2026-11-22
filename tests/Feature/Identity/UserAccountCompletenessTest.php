<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Services\UserAccountCompleteness;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserAccountCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_more_meaningful_profile_and_related_fields_increase_completeness(): void
    {
        $empty = User::factory()->create(['username' => 'tg_123456']);
        $filled = User::factory()->withVerifiedContact()->create(['username' => 'basketball-player']);
        $filled->createProfile(['first_name' => 'Иван', 'last_name' => 'Иванов']);
        $filled->playerProfile()->create(['height_cm' => 180, 'weight_kg' => 80]);
        $service = app(UserAccountCompleteness::class);
        $low = $service->summarize($empty);
        $high = $service->summarize($filled);

        $this->assertSame(0, $low['percent']);
        $this->assertGreaterThan($low['percent'], $high['percent']);
        $this->assertSame($low['total'], $high['total']);
        $this->assertSame(1, $high['contacts']);
        $this->assertLessThanOrEqual(100, $high['percent']);
    }
}
