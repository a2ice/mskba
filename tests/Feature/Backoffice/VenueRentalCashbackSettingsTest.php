<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\VenueRewardOverrides\VenueRewardOverrideResource;
use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Rewards\Application\Services\VenueRentalCashbackSettings;
use App\Modules\Rewards\Domain\Models\Reward;
use App\Modules\Rewards\Domain\Models\VenueRewardOverride;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class VenueRentalCashbackSettingsTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }

    private function login(UserSystemRoleEnum $role): User
    {
        $user = User::factory()->create([
            'system_role' => $role,
            'status' => UserStatusEnum::CONFIRMED,
        ]);
        $user->createProfile([]);
        $this->actingAs($user);

        return $user;
    }

    public function test_superadmin_manages_local_cashback_inside_venue_with_inheritance_and_zero(): void
    {
        $this->login(UserSystemRoleEnum::SUPERADMIN);
        $venue = Venue::factory()->create();
        $reward = Reward::query()->where('code', 'venue_rental_completed')->firstOrFail();
        $settings = app(VenueRentalCashbackSettings::class);

        $this->assertSame('inherit', $settings->formFields($venue)['cashback_mode']);
        $this->assertSame('inherit', app(VenueBackofficeEditor::class)->fields($venue)['cashback_mode']);
        $this->assertFalse(VenueRewardOverrideResource::shouldRegisterNavigation());

        $settings->save($venue, [
            'cashback_mode' => 'custom',
            'cashback_amount_rub' => '200,50',
        ]);
        $override = VenueRewardOverride::query()
            ->where('venue_id', $venue->id)->where('reward_id', $reward->id)->firstOrFail();
        $this->assertSame('custom', $override->mode);
        $this->assertSame(20050, $override->amount_minor);
        $this->assertSame('200.50', $settings->formFields($venue)['cashback_amount_rub']);

        $settings->save($venue, [
            'cashback_mode' => 'custom',
            'cashback_amount_rub' => '0',
        ]);
        $this->assertSame(0, $override->fresh()->amount_minor);

        $settings->save($venue, ['cashback_mode' => 'disabled']);
        $override->refresh();
        $this->assertSame('disabled', $override->mode);
        $this->assertNull($override->amount_minor);

        $settings->save($venue, ['cashback_mode' => 'inherit']);
        $this->assertFalse(VenueRewardOverride::query()
            ->where('venue_id', $venue->id)->where('reward_id', $reward->id)->exists());
        $this->assertSame('inherit', $settings->formFields($venue)['cashback_mode']);
    }

    public function test_unrelated_venue_edit_does_not_touch_existing_override(): void
    {
        $this->login(UserSystemRoleEnum::SUPERADMIN);
        $venue = Venue::factory()->create();
        $settings = app(VenueRentalCashbackSettings::class);
        $settings->save($venue, ['cashback_mode' => 'custom', 'cashback_amount_rub' => '150']);
        $settings->save($venue, ['name' => 'Изменение названия']);
        $this->assertSame(15000, VenueRewardOverride::query()->where('venue_id', $venue->id)->firstOrFail()->amount_minor);
    }

    public function test_non_superadmin_cannot_read_or_change_financial_override(): void
    {
        $this->login(UserSystemRoleEnum::MANAGER);
        $venue = Venue::factory()->create();
        $settings = app(VenueRentalCashbackSettings::class);
        $this->assertSame([], $settings->formFields($venue));

        $this->expectException(HttpException::class);
        $settings->save($venue, ['cashback_mode' => 'custom', 'cashback_amount_rub' => '200']);
    }

    public function test_invalid_local_cashback_amount_is_rejected_without_modifying_database(): void
    {
        $this->login(UserSystemRoleEnum::SUPERADMIN);
        $venue = Venue::factory()->create();
        try {
            app(VenueRentalCashbackSettings::class)->save($venue, [
                'cashback_mode' => 'custom',
                'cashback_amount_rub' => '100.999',
            ]);
            $this->fail('Value with more than two fractional digits must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.cashback_amount_rub', $e->errors());
        }

        $this->assertFalse(VenueRewardOverride::query()->where('venue_id', $venue->id)->exists());
    }
}
