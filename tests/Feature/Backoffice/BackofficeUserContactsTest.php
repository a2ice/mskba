<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Backoffice\Resources\BackofficeUsers\BackofficeUserResource;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Telegram\Domain\Models\TelegramAccount;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BackofficeUserContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_history_is_scoped_to_identity_and_does_not_expose_secrets(): void
    {
        $super = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => UserSystemRoleEnum::SUPERADMIN,
        ]);
        $target = User::factory()->create(['system_role' => UserSystemRoleEnum::USER]);
        $target->createProfile([]);
        $alias = User::factory()->create(['canonical_user_id' => $target->id]);
        $other = User::factory()->create();
        $confirmedAt = now()->subDay()->startOfSecond();
        $contact = $alias->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'alias-contact@example.test',
            'is_primary' => true,
            'verified_at' => $confirmedAt,
        ]);
        $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::CONFIRMED,
            'sent_to' => $contact->value,
            'verified_at' => $confirmedAt,
            'code_hash' => 'verification-secret-hash',
            'meta' => ['internal' => 'private-verification-metadata'],
        ]);
        $deleted = $target->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'deleted-contact@example.test',
        ]);
        $deleted->delete();
        $other->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'unrelated-contact@example.test',
        ]);
        TelegramAccount::query()->create([
            'user_id' => $alias->id,
            'telegram_user_id' => 123456789,
            'username' => 'legacy_contact',
        ]);

        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        $this->get(BackofficeUserResource::getUrl('edit', ['record' => $target]))
            ->assertOk()->assertSee('Контакты');
        $this->view('filament.backoffice.user-contacts', ['editedUser' => $target])
            ->assertSee('alias-contact@example.test')
            ->assertSee($confirmedAt->format('d.m.Y H:i'))
            ->assertSee('История подтверждений (1)')
            ->assertSee('deleted-contact@example.test')
            ->assertSee('@legacy_contact')
            ->assertDontSee('unrelated-contact@example.test')
            ->assertDontSee('verification-secret-hash')
            ->assertDontSee('private-verification-metadata');

        $this->actingAs($other);
        $this->view('filament.backoffice.user-contacts', ['editedUser' => $target])
            ->assertDontSee('alias-contact@example.test')
            ->assertDontSee('deleted-contact@example.test')
            ->assertDontSee('@legacy_contact');
    }
}
