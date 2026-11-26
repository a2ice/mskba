<?php

namespace Tests\Feature\Contact;

use App\Modules\Contact\Application\Exceptions\ContactVerificationException;
use App\Modules\Contact\Application\UseCases\AdminManageUserContactsHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum as Type;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum as Channel;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum as VerificationStatus;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserStatusEnum as Status;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminManageUserContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_creates_unverified_contact_and_records_actor(): void
    {
        $admin = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $target = $this->user();

        $contact = app(AdminManageUserContactsHandler::class)->add($admin, $target, Type::EMAIL, 'New@example.test', 'Рабочий');

        $this->assertSame('new@example.test', $contact->value);
        $this->assertNull($contact->verified_at);
        $this->assertFalse((bool) $contact->is_public);
        $this->assertTrue($target->ownsIdentityContact($contact));
        $this->assertSame(Status::UNCONFIRMED, $target->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class, 'auditable_id' => $target->id, 'event' => 'admin_user_contact_added',
        ]);
    }

    public function test_admin_can_enter_real_code_and_make_verified_contact_primary(): void
    {
        $admin = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $target = $this->user();
        $handler = app(AdminManageUserContactsHandler::class);
        $first = $handler->add($admin, $target, Type::EMAIL, 'first@example.test', null);
        $second = $handler->add($admin, $target, Type::EMAIL, 'second@example.test', null);
        $this->code($first);
        $this->code($second);

        $handler->confirmCode($admin, $target, $first->id, '123456');
        $handler->confirmCode($admin, $target, $second->id, '123456');
        $handler->setPrimary($admin, $target, $second->id);

        $this->assertTrue($first->fresh()->hasBeenVerified());
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertTrue($target->fresh()->hasVerifiedPrimaryContact());
        $this->assertSame(Status::UNCONFIRMED, $target->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $target->id, 'event' => 'admin_user_contact_verified']);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $target->id, 'event' => 'admin_user_contact_primary_changed']);
    }

    public function test_wrong_and_expired_codes_do_not_verify_contact(): void
    {
        $admin = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $target = $this->user();
        $handler = app(AdminManageUserContactsHandler::class);
        $contact = $handler->add($admin, $target, Type::EMAIL, 'pending@example.test', null);
        $verification = $this->code($contact);

        try {
            $handler->confirmCode($admin, $target, $contact->id, '000000');
            $this->fail('Wrong code must fail.');
        } catch (ContactVerificationException) {
            $this->assertSame(1, (int) $verification->fresh()->attempts_count);
        }

        $verification->update(['expires_at' => now()->subMinute()]);
        try {
            $handler->confirmCode($admin, $target, $contact->id, '123456');
            $this->fail('Expired code must fail.');
        } catch (ContactVerificationException) {
            $this->assertSame(VerificationStatus::EXPIRED, $verification->fresh()->status);
        }

        $this->assertNull($contact->fresh()->verified_at);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'admin_user_contact_verified']);
    }

    public function test_contact_of_other_user_cannot_be_operated_on(): void
    {
        $admin = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $target = $this->user();
        $other = $this->user();
        $contact = $other->contacts()->create(['type' => Type::EMAIL, 'value' => 'other@example.test']);

        $this->expectException(ModelNotFoundException::class);
        app(AdminManageUserContactsHandler::class)->confirmCode($admin, $target, $contact->id, '123456');
    }

    public function test_regular_admin_cannot_change_login_contacts(): void
    {
        $actor = $this->user(Role::ADMIN, Status::CONFIRMED);
        $target = $this->user();

        $this->expectException(AuthorizationException::class);
        app(AdminManageUserContactsHandler::class)->add($actor, $target, Type::EMAIL, 'forbidden@example.test', null);
    }

    public function test_phone_verification_is_not_supported(): void
    {
        $admin = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $target = $this->user();
        $handler = app(AdminManageUserContactsHandler::class);
        $contact = $handler->add($admin, $target, Type::PHONE, '+79991234567', null);

        $this->expectException(ValidationException::class);
        $handler->sendCode($admin, $target, $contact->id);
    }

    public function test_contact_revision_preserves_old_record_and_label(): void
    {
        $admin = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $target = $this->user();
        $handler = app(AdminManageUserContactsHandler::class);
        $old = $handler->add($admin, $target, Type::EMAIL, 'typo@example.test', 'Рабочий');
        $new = $handler->replacePending($admin, $target, $old->id, 'correct@example.test', 'Исправление опечатки в адресе');

        $this->assertSoftDeleted($old);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('correct@example.test', $new->value);
        $this->assertSame('Рабочий', $new->label);
        $this->assertTrue($new->is_primary);
        $this->assertNull($new->verified_at);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $target->id, 'event' => 'admin_user_contact_replaced']);
    }

    public function test_contact_label_change_and_deletion_are_audited(): void
    {
        $admin = $this->user(Role::SUPERADMIN, Status::CONFIRMED);
        $target = $this->user();
        $handler = app(AdminManageUserContactsHandler::class);
        $contact = $handler->add($admin, $target, Type::EMAIL, 'office@example.test', null);

        $handler->updateLabel($admin, $target, $contact->id, 'Рабочий', 'Уточнение названия контакта');
        $this->assertSame('Рабочий', $contact->fresh()->label);
        $handler->delete($admin, $target, $contact->id, 'Контакт добавлен по ошибке');
        $this->assertSoftDeleted($contact);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $target->id, 'event' => 'admin_user_contact_label_changed']);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $target->id, 'event' => 'admin_user_contact_deleted']);
    }

    private function user(Role $role = Role::USER, Status $status = Status::UNCONFIRMED): User
    {
        return User::factory()->create(['system_role' => $role, 'status' => $status]);
    }

    private function code(Contact $contact)
    {
        return $contact->verifications()->create([
            'channel' => Channel::EMAIL,
            'status' => VerificationStatus::PENDING,
            'code_hash' => Hash::make('123456'),
            'sent_to' => $contact->value,
            'attempts_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
        ]);
    }
}
