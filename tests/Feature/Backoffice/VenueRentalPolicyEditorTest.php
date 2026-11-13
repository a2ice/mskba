<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\Venues\Pages\EditVenue;
use App\Filament\Backoffice\Resources\Venues\Support\VenueRentalPolicyEditor;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\EffectiveCourtPolicy;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class VenueRentalPolicyEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
    }

    private function superadmin(): User
    {
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $user->createProfile([]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));

        return $user;
    }

    public function test_filament_venue_editor_creates_and_versions_rental_policy_used_by_inheriting_courts(): void
    {
        $user = $this->superadmin();
        $venue = Venue::factory()->create(['requires_payment' => true]);
        $court = $venue->primaryCourt()->firstOrFail();
        $editor = app(VenueRentalPolicyEditor::class);

        $this->assertTrue($editor->canManage($venue));
        $fields = $editor->fields($venue);
        $this->assertFalse($fields['rental_policy_enabled']);
        $this->assertTrue($fields['rental_policy_requires_payment']);

        // A regular venue save with disabled/unpublished rental must not
        // create an unusable policy with default prices.
        $editor->save($venue, $user, $fields);
        $this->assertSame(0, VenueBookingPolicy::query()->where('venue_id', $venue->id)->count());

        $fields['rental_policy_enabled'] = true;
        $fields['rental_policy_requires_payment'] = true;
        $fields['rental_policy_whole_price_rub'] = '1500.50';
        $fields['rental_policy_minimum_lead_time_minutes'] = 0;
        $editor->save($venue, $user, $fields);

        $policy = VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)->where('active_marker', true)->firstOrFail();
        $this->assertSame(1, $policy->version);
        $this->assertTrue($policy->is_enabled);
        $this->assertTrue($policy->requires_payment);
        $this->assertSame(150050, $policy->whole_price_per_step_minor);

        $effective = app(EffectiveCourtPolicy::class)->resolve($venue->refresh(), $court->refresh(), $policy);
        $this->assertTrue($effective['rental_enabled']);
        $this->assertTrue($effective['requires_payment']);
        $this->assertSame(150050, $effective['whole_price_per_step_minor']);

        // Saving an unchanged venue does not create unnecessary versions.
        $editor->save($venue, $user, $editor->fields($venue));
        $this->assertSame(1, VenueBookingPolicy::query()->where('venue_id', $venue->id)->count());

        $disabled = $editor->fields($venue);
        $disabled['rental_policy_enabled'] = false;
        $editor->save($venue, $user, $disabled);
        $this->assertSame(2, VenueBookingPolicy::query()->where('venue_id', $venue->id)->count());
        $disabledPolicy = VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)->where('active_marker', true)->firstOrFail();
        $this->assertFalse($disabledPolicy->is_enabled);
        $this->assertFalse(app(EffectiveCourtPolicy::class)
            ->resolve($venue->refresh(), $court->refresh(), $disabledPolicy)['rental_enabled']);

        $enabled = $editor->fields($venue);
        $enabled['rental_policy_enabled'] = true;
        $editor->save($venue, $user, $enabled);
        $this->assertSame(3, VenueBookingPolicy::query()->where('venue_id', $venue->id)->count());
        $this->assertTrue(VenueBookingPolicy::query()
            ->where('venue_id', $venue->id)->where('active_marker', true)->firstOrFail()->is_enabled);
    }

    public function test_inline_rental_rejects_paid_zero_price_without_creating_policy(): void
    {
        $user = $this->superadmin();
        $venue = Venue::factory()->create();
        $editor = app(VenueRentalPolicyEditor::class);
        $data = $editor->fields($venue);
        $data['rental_policy_enabled'] = true;
        $data['rental_policy_requires_payment'] = true;
        $data['rental_policy_whole_price_rub'] = '0';

        try {
            $editor->save($venue, $user, $data);
            $this->fail('Paid rental cannot be enabled with a zero base price.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.rental_policy_enabled', $exception->errors());
        }

        $this->assertSame(0, VenueBookingPolicy::query()->where('venue_id', $venue->id)->count());
    }

    public function test_fillet_rental_toggle_is_not_exposed_to_unprivileged_venue_manager(): void
    {
        $venue = Venue::factory()->create();
        $user = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::MANAGER,
        ]);
        $user->createProfile([]);
        $this->actingAs($user);
        $editor = app(VenueRentalPolicyEditor::class);
        $this->assertFalse($editor->canManage($venue));
        $this->assertSame([], $editor->fields($venue));

        $this->expectException(HttpException::class);
        $editor->save($venue, $user, ['rental_policy_enabled' => true]);
    }

    public function test_existing_venue_edit_screen_has_inline_rental_settings(): void
    {
        $this->superadmin();
        $venue = Venue::factory()->create();

        $this->get(EditVenue::getUrl(['record' => $venue]))
            ->assertOk()
            ->assertSee('Принимать заявки на аренду')
            ->assertDontSee('Аренда: не настроена');
    }
}
