<?php

namespace Tests\Feature\Backoffice;

use App\Modules\Contract\Domain\Enums\VenueMembershipAccessLevelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\UseCases\AdminChangeVenueMembershipHandler;
use App\Modules\Venue\Application\UseCases\AdminRevertVenueMembershipHandler;
use App\Modules\Venue\Application\UseCases\ManageVenueMembershipHandler;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UserVenueContractConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.venue_rental.rental_flow', true);
    }

    private function user(UserSystemRoleEnum $role): User
    {
        return User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => $role]);
    }

    public function test_superadmin_can_change_active_membership_with_audit_and_safe_revert(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $venue = Venue::factory()->create();
        $this->actingAs($super);
        $membership = app(ManageVenueMembershipHandler::class)->grant(
            $venue, $target, VenueMembershipAccessLevelEnum::BOOKING_OPERATOR, $super,
        );

        $revision = app(AdminChangeVenueMembershipHandler::class)->handle(
            $super, $target, $membership->id, VenueMembershipAccessLevelEnum::FINANCE_VIEWER->value,
            [VenuePermissionEnum::VIEW->value, VenuePermissionEnum::VIEW_PAYMENTS->value],
            'Назначение финансовой специализации по договору',
        );
        $this->assertSame('admin_venue_membership_changed', $revision->event);
        $this->assertSame('Назначение финансовой специализации по договору', $revision->metadata['reason']);
        $this->assertSame(VenueMembershipAccessLevelEnum::FINANCE_VIEWER->value, $membership->fresh()->access_level);

        $revert = app(AdminRevertVenueMembershipHandler::class)->handle(
            $super, $target, $revision->id, 'Восстановление прежнего уровня договорных прав',
        );
        $this->assertSame('admin_venue_membership_reverted', $revert->event);
        $this->assertSame(VenueMembershipAccessLevelEnum::BOOKING_OPERATOR->value, $membership->fresh()->access_level);
        $this->assertNotNull($revision->fresh());
    }

    public function test_admin_without_user_edit_permission_cannot_change_contract(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $admin = $this->user(UserSystemRoleEnum::ADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $venue = Venue::factory()->create();
        $this->actingAs($admin);
        $membership = app(ManageVenueMembershipHandler::class)->grant(
            $venue, $target, VenueMembershipAccessLevelEnum::BOOKING_OPERATOR, $super,
        );

        $this->expectException(AuthorizationException::class);
        app(AdminChangeVenueMembershipHandler::class)->handle(
            $admin, $target, $membership->id, VenueMembershipAccessLevelEnum::FINANCE_VIEWER->value,
            [VenuePermissionEnum::VIEW->value], 'Проверка отказа администратору без разрешения',
        );
    }

    public function test_other_users_membership_is_not_editable_through_target_record(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $other = $this->user(UserSystemRoleEnum::USER);
        $venue = Venue::factory()->create();
        $this->actingAs($super);
        $membership = app(ManageVenueMembershipHandler::class)->grant(
            $venue, $other, VenueMembershipAccessLevelEnum::BOOKING_OPERATOR, $super,
        );

        $this->expectException(ValidationException::class);
        app(AdminChangeVenueMembershipHandler::class)->handle(
            $super, $target, $membership->id, VenueMembershipAccessLevelEnum::FINANCE_VIEWER->value,
            [VenuePermissionEnum::VIEW->value], 'Проверка запрета чужого договора',
        );
    }

    public function test_conflicting_newer_contract_change_prevents_revert(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $venue = Venue::factory()->create();
        $this->actingAs($super);
        $membership = app(ManageVenueMembershipHandler::class)->grant(
            $venue, $target, VenueMembershipAccessLevelEnum::BOOKING_OPERATOR, $super,
        );
        $revision = app(AdminChangeVenueMembershipHandler::class)->handle(
            $super, $target, $membership->id, VenueMembershipAccessLevelEnum::FINANCE_VIEWER->value,
            [VenuePermissionEnum::VIEW->value, VenuePermissionEnum::VIEW_PAYMENTS->value],
            'Первое редактирование договорной специализации',
        );
        app(ManageVenueMembershipHandler::class)->change(
            $venue, $membership, VenueMembershipAccessLevelEnum::MANAGER, $super,
        );

        $this->expectException(ValidationException::class);
        app(AdminRevertVenueMembershipHandler::class)->handle(
            $super, $target, $revision->id, 'Проверка невозможности откатить более позднюю правку',
        );
    }

    public function test_owner_cannot_be_replaced_with_ordinary_role(): void
    {
        $super = $this->user(UserSystemRoleEnum::SUPERADMIN);
        $target = $this->user(UserSystemRoleEnum::USER);
        $venue = Venue::factory()->create();
        $this->actingAs($super);
        $membership = app(ManageVenueMembershipHandler::class)->grant(
            $venue, $target, VenueMembershipAccessLevelEnum::BOOKING_OPERATOR, $super,
        );

        $this->expectException(ValidationException::class);
        app(AdminChangeVenueMembershipHandler::class)->handle(
            $super, $target, $membership->id, VenueMembershipAccessLevelEnum::OWNER->value,
            [VenuePermissionEnum::VIEW->value], 'Проверка запрета переназначения владельца',
        );
    }
}
