<?php

namespace Tests\Feature\Venue;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\UseCases\ReviewVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\SubmitVenueOwnershipClaimHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueOwnershipMaintenanceHandler;
use App\Modules\Venue\Domain\Models\Venue;
use App\Modules\Venue\Domain\Models\VenueOwnership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class VenueOwnershipMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_and_database_defaults_are_available_for_existing_workflow(): void
    {
        $ownership = $this->activeOwnership();

        $this->assertTrue(Schema::hasColumns('venue_ownerships', [
            'maintenance_commitment_accepted',
            'maintenance_score',
            'maintenance_comment',
        ]));
        $this->assertFalse($ownership->maintenance_commitment_accepted);
        $this->assertSame(0, $ownership->maintenance_score);
        $this->assertNull($ownership->maintenance_comment);
    }

    public function test_http_validation_rejects_out_of_range_and_non_step_scores(): void
    {
        $admin = $this->confirmedUser(UserSystemRoleEnum::ADMIN);
        $ownership = $this->activeOwnership();

        foreach ([-10, 37, 93, 110] as $invalidScore) {
            $this->actingAs($admin)
                ->from(route('admin.venue-ownership.index'))
                ->patch(route('admin.venue-ownership.maintenance', $ownership), [
                    'maintenance_commitment_accepted' => '1',
                    'maintenance_score' => $invalidScore,
                    'maintenance_comment' => 'Не должно сохраниться.',
                ])
                ->assertRedirect(route('admin.venue-ownership.index'))
                ->assertSessionHasErrors('maintenance_score');
        }

        $this->assertSame(0, $ownership->refresh()->maintenance_score);
    }

    public function test_use_case_rejects_invalid_score_outside_http_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(UpdateVenueOwnershipMaintenanceHandler::class)->handle(
            $this->activeOwnership(),
            true,
            37,
            null,
            $this->confirmedUser(UserSystemRoleEnum::ADMIN),
        );
    }

    public function test_admin_can_store_boundary_scores_and_maintenance_values(): void
    {
        $admin = $this->confirmedUser(UserSystemRoleEnum::ADMIN);
        $ownership = $this->activeOwnership();

        $this->actingAs($admin)
            ->get(route('admin.venue-ownership.index'))
            ->assertOk()
            ->assertSee('maintenance_commitment_accepted', false)
            ->assertSee('maintenance_score', false)
            ->assertSee('maintenance_comment', false);

        foreach ([0, 100] as $score) {
            $this->actingAs($admin)
                ->patch(route('admin.venue-ownership.maintenance', $ownership), [
                    'maintenance_commitment_accepted' => '1',
                    'maintenance_score' => $score,
                    'maintenance_comment' => "Оценка {$score}",
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $ownership->refresh();
            $this->assertTrue($ownership->maintenance_commitment_accepted);
            $this->assertSame($score, $ownership->maintenance_score);
            $this->assertSame("Оценка {$score}", $ownership->maintenance_comment);
        }
    }

    public function test_ordinary_user_cannot_update_maintenance_values(): void
    {
        $ownership = $this->activeOwnership();

        $this->actingAs($this->confirmedUser())
            ->patch(route('admin.venue-ownership.maintenance', $ownership), [
                'maintenance_commitment_accepted' => '1',
                'maintenance_score' => 100,
                'maintenance_comment' => 'Попытка обойти полномочия.',
            ])
            ->assertForbidden();

        $this->assertFalse($ownership->refresh()->maintenance_commitment_accepted);
        $this->assertSame(0, $ownership->maintenance_score);
    }

    public function test_admin_update_is_written_to_existing_audit_with_actor_and_old_new_values(): void
    {
        config()->set('audit.ignore_console', false);
        $admin = $this->confirmedUser(UserSystemRoleEnum::ADMIN);
        $ownership = $this->activeOwnership();
        $ownership->forceFill([
            'maintenance_commitment_accepted' => false,
            'maintenance_score' => 50,
            'maintenance_comment' => 'Предыдущая оценка.',
        ])->save();
        AuditLog::query()->delete();

        $this->actingAs($admin)
            ->patch(route('admin.venue-ownership.maintenance', $ownership), [
                'maintenance_commitment_accepted' => '1',
                'maintenance_score' => 70,
                'maintenance_comment' => 'Данные обновляются регулярно.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $audit = AuditLog::query()
            ->where('auditable_type', VenueOwnership::class)
            ->where('auditable_id', $ownership->id)
            ->where('event', 'updated')
            ->sole();

        $this->assertSame($admin->id, $audit->actor?->user_id);
        $this->assertEquals(50, $audit->old_values['maintenance_score']);
        $this->assertEquals(70, $audit->new_values['maintenance_score']);
        $this->assertEquals(false, (bool) $audit->old_values['maintenance_commitment_accepted']);
        $this->assertEquals(true, (bool) $audit->new_values['maintenance_commitment_accepted']);
        $this->assertSame('Предыдущая оценка.', $audit->old_values['maintenance_comment']);
        $this->assertSame('Данные обновляются регулярно.', $audit->new_values['maintenance_comment']);
        $this->assertSame('admin.venue-ownership.maintenance', $audit->metadata['route']);
    }

    private function activeOwnership(): VenueOwnership
    {
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle(
            Venue::factory()->create(),
            $this->confirmedUser(),
            'Документы и рабочие контакты представителя площадки.',
        );
        app(ReviewVenueOwnershipClaimHandler::class)->approve(
            $claim,
            $this->confirmedUser(UserSystemRoleEnum::ADMIN),
            'Полномочия подтверждены.',
        );

        return VenueOwnership::query()->where('source_claim_id', $claim->id)->sole();
    }

    private function confirmedUser(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }
}
