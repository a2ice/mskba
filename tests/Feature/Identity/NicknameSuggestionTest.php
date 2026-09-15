<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Services\NicknameSuggestionService;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NicknameSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_name_is_used_when_available(): void
    {
        $user = User::factory()->create(['username' => 'tg_1']);
        $user->profile()->create(['first_name' => 'Dmitry', 'last_name' => 'Olsen']);

        $this->assertSame('dmitry_olsen', app(NicknameSuggestionService::class)->suggest($user));
    }

    public function test_cyrillic_name_is_transliterated_for_personal_suggestion(): void
    {
        $user = User::factory()->create(['username' => 'tg_2']);
        $user->profile()->create(['first_name' => 'Иван', 'last_name' => 'Петров']);

        $suggestion = app(NicknameSuggestionService::class)->suggest($user);

        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{2,29}$/', $suggestion);
        $this->assertNotSame('court_king', $suggestion);
    }

    public function test_busy_personal_name_falls_back_to_first_free_neutral_variant(): void
    {
        $user = User::factory()->create(['username' => 'tg_3']);
        $user->profile()->create(['first_name' => 'Dmitry', 'last_name' => 'Olsen']);

        User::factory()->create(['username' => 'dmitry_olsen']);
        $this->userWithNickname('busy_1', 'court_king');
        User::factory()->create(['username' => 'court_king1']);

        $this->assertSame('court_king2', app(NicknameSuggestionService::class)->suggest($user));
    }

    private function userWithNickname(string $username, string $nickname): User
    {
        $user = User::factory()->create(['username' => $username]);
        $user->forceFill(['nickname' => $nickname])->save();

        return $user;
    }
}
