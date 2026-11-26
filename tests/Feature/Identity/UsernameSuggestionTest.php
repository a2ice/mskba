<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Services\UsernameSuggestionService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\ValueObjects\UsernameVO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UsernameSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalized_email_base_is_preferred(): void
    {
        $service = app(UsernameSuggestionService::class);

        $this->assertSame('ivanov', $service->suggest('Ivanov123@example.test'));
        $this->assertSame('alex.petrov', $service->suggest('Alex.Petrov99@example.test'));
        $this->assertSame('alex_sales', $service->suggest('Alex+Sales42@example.test'));
        $this->assertSame('ivanov', $service->suggest('Ivanov123@example.test'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_suffix_starts_at_777_and_increments_original_base(): void
    {
        $service = app(UsernameSuggestionService::class);
        User::factory()->create(['username' => 'IVANOV']);

        $this->assertSame('ivanov777', $service->suggest('ivanov777@example.test'));

        User::factory()->create(['username' => 'ivanov777']);
        User::factory()->create(['username' => 'IVANOV778'])->delete();

        $this->assertSame('ivanov779', $service->suggest('ivanov123@example.test'));
        $this->assertFalse($service->isAvailable('Ivanov778'));
        $this->assertTrue($service->isAvailable('ivanov779'));
    }

    public function test_public_nicknames_are_reserved_even_when_username_differs(): void
    {
        $service = app(UsernameSuggestionService::class);
        $user = User::factory()->create(['username' => 'another_login']);
        $user->forceFill(['nickname' => 'ivanov'])->save();
        $other = User::factory()->create(['username' => 'another_account']);
        $other->forceFill(['nickname' => 'ivanov777'])->save();

        $this->assertFalse($service->isAvailable('IVANOV'));
        $this->assertSame('ivanov778', $service->suggest('ivanov@example.test'));
    }

    public function test_invalid_or_short_base_uses_neutral_numbered_fallback(): void
    {
        $service = app(UsernameSuggestionService::class);

        $this->assertSame('user777', $service->suggest('123456@example.test'));
        $this->assertSame('user777', $service->suggest('ab12@example.test'));

        User::factory()->create(['username' => 'user777']);
        $this->assertSame('user778', $service->suggest('123456@example.test'));
        $this->assertFalse($service->isAvailable('a!'));
    }

    public function test_long_base_leaves_room_for_numeric_suffix(): void
    {
        $service = app(UsernameSuggestionService::class);
        $base = str_repeat('a', UsernameVO::MAX_LENGTH);
        $email = $base.'long123@example.test';

        $this->assertSame($base, $service->suggest($email));

        User::factory()->create(['username' => $base]);
        $suggestion = $service->suggest($email);

        $this->assertSame(str_repeat('a', 29).'777', $suggestion);
        $this->assertSame($suggestion, UsernameVO::fromString($suggestion)->value);
    }
}
