<?php

namespace Tests\Feature\Contact;

use App\Modules\Contact\Application\DTO\ConfirmContactVerificationDTO;
use App\Modules\Contact\Application\UseCases\ConfirmContactVerificationHandler;
use App\Modules\Contact\Application\UseCases\DeleteContactHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Application\Services\UserLoginResolver;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ContactOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_successful_code_claims_email_and_invalidates_other_pending_codes(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $data = ['type' => 'email', 'value' => 'shared@example.test'];
        $a = $this->actingAs($first)->postJson(route('account.contacts.store'), $data)->assertCreated()->json('contact.id');
        $b = $this->actingAs($second)->postJson(route('account.contacts.store'), [
            'type' => 'email', 'value' => 'SHARED@example.test',
        ])->assertCreated()->json('contact.id');
        $a = Contact::query()->findOrFail($a);
        $b = Contact::query()->findOrFail($b);
        $codeA = $this->code($a, '111111');
        $codeB = $this->code($b, '222222');
        $this->assertNull(app(UserLoginResolver::class)->resolve('shared@example.test'));

        $this->postJson(route('account.contacts.verification.confirm', $b), ['code' => '333333'])
            ->assertUnprocessable();
        $this->assertSame(ContactVerificationStatusEnum::PENDING, $codeA->refresh()->status);
        $this->assertFalse($a->refresh()->trashed());

        $this->postJson(route('account.contacts.verification.confirm', $b), ['code' => '222222'])
            ->assertOk()->assertJsonPath('verified', true);
        $this->assertNotNull($b->refresh()->verified_at);
        $this->assertSoftDeleted($a);
        $this->assertSame(ContactVerificationStatusEnum::CANCELLED, $codeA->refresh()->status);
        $this->assertSame(ContactVerificationStatusEnum::CONFIRMED, $codeB->refresh()->status);
        $this->assertSame($second->id, app(UserLoginResolver::class)->resolve('SHARED@example.test')?->id);

        $this->actingAs($first)->postJson(route('account.contacts.verification.confirm', $a), ['code' => '111111'])->assertNotFound();
        $this->postJson(route('account.contacts.verification.store', $a))->assertNotFound();
        $this->postJson(route('account.contacts.store'), $data)->assertUnprocessable();
        $this->assertSame(1, Contact::query()->withTrashed()->where('user_email_key', 'shared@example.test')->count());
    }

    public function test_deleted_unverified_email_does_not_reserve_but_previously_verified_email_does(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $never = $owner->contacts()->create(['type' => ContactTypeEnum::EMAIL, 'value' => 'typo@example.test']);
        $confirmed = $owner->contacts()->create(['type' => ContactTypeEnum::EMAIL, 'value' => 'reserved@example.test', 'verified_at' => now()]);
        $delete = app(DeleteContactHandler::class);
        $delete->handle($never);
        $delete->handle($confirmed);
        $this->assertNull($confirmed->refresh()->verified_at);
        $this->assertTrue($confirmed->ownership_reserved);
        $this->actingAs($other)->postJson(route('account.contacts.store'), ['type' => 'email', 'value' => $never->value])->assertCreated();
        $this->postJson(route('account.contacts.store'), ['type' => 'email', 'value' => $confirmed->value])->assertUnprocessable();
    }

    public function test_phone_pending_records_can_coexist_but_verified_ownership_blocks_other_formats(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->postJson(route('account.contacts.store'), ['type' => 'phone', 'value' => '8 (999) 123-45-67'])->assertCreated();
        $this->actingAs($other)->postJson(route('account.contacts.store'), ['type' => 'phone', 'value' => '79991234567'])->assertCreated();
        $this->postJson(route('account.contacts.store'), ['type' => 'phone', 'value' => '+79991234567'])->assertUnprocessable();
        $known = $owner->contacts()->create(['type' => ContactTypeEnum::PHONE, 'value' => '8 (999) 345-67-89', 'verified_at' => now()]);
        $this->postJson(route('account.contacts.store'), ['type' => 'phone', 'value' => '+79993456789'])->assertUnprocessable();
        $this->assertSame($owner->id, app(UserLoginResolver::class)->resolve('+79993456789')?->id);
        $this->assertSame('+79993456789', $known->refresh()->user_phone_key);
    }

    public function test_replacing_with_other_pending_email_does_not_remove_other_users_record_before_proof(): void
    {
        config()->set('mail.default', 'smtp');
        Mail::fake();
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $target = $owner->contacts()->create(['type' => ContactTypeEnum::EMAIL, 'value' => 'target@example.test']);
        $old = $requester->contacts()->create(['type' => ContactTypeEnum::EMAIL, 'value' => 'typo@example.test']);
        $result = $this->actingAs($requester)->postJson(route('account.contacts.store'), [
            'type' => 'email', 'value' => $target->value, 'replace_contact_id' => $old->id,
        ])->assertCreated();
        $this->assertSoftDeleted($old);
        $this->assertFalse($target->refresh()->trashed());
        $this->assertNull(Contact::query()->findOrFail($result->json('contact.id'))->verified_at);
    }

    private function code(Contact $contact, string $code)
    {
        return $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make($code),
            'sent_to' => $contact->value,
            'attempts_count' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(10),
        ]);
    }
}
