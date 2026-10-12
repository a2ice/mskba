<?php

namespace Tests\Feature\Notification;

use App\Modules\Contact\Application\DTO\ConfirmContactVerificationDTO;
use App\Modules\Contact\Application\UseCases\ConfirmContactVerificationHandler;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationChannelEnum;
use App\Modules\Contact\Domain\Enums\ContactVerificationStatusEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Notification\Domain\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ContactConfirmationCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_contact_before_account_confirmation_points_to_confirmation(): void
    {
        $notification = $this->confirmContact(UserStatusEnum::UNCONFIRMED);
        $this->assertStringContainsString('подтверждение аккаунта', $notification->body);
        $this->assertSame(route('account.confirmation', absolute: false), $notification->action_url);
        $this->assertSame('Подтвердить аккаунт', $notification->action_text);
    }

    public function test_second_contact_after_account_confirmation_does_not_prompt_to_confirm_again(): void
    {
        $notification = $this->confirmContact(UserStatusEnum::CONFIRMED);
        $this->assertStringContainsString('дополнительный контакт', $notification->body);
        $this->assertStringNotContainsString('подтверждение профиля', $notification->body);
        $this->assertSame(route('account.contacts', absolute: false), $notification->action_url);
        $this->assertSame('Управлять контактами', $notification->action_text);
    }

    private function confirmContact(UserStatusEnum $status): UserNotification
    {
        $user = User::factory()->create(['status' => $status]);
        if ($status === UserStatusEnum::CONFIRMED) {
            $user->contacts()->create([
                'type' => ContactTypeEnum::EMAIL,
                'value' => 'primary@example.test',
                'verified_at' => now(),
                'is_primary' => true,
            ]);
        }
        $contact = $user->contacts()->create([
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'another@example.test',
            'is_primary' => false,
        ]);
        $contact->verifications()->create([
            'channel' => ContactVerificationChannelEnum::EMAIL,
            'status' => ContactVerificationStatusEnum::PENDING,
            'code_hash' => Hash::make('123456'),
            'sent_to' => 'another@example.test',
            'expires_at' => now()->addMinutes(15),
        ]);

        app(ConfirmContactVerificationHandler::class)->handle(
            $contact, new ConfirmContactVerificationDTO('123456'),
        );
        $this->assertTrue($contact->fresh()->hasBeenVerified());
        $this->assertSame($status === UserStatusEnum::UNCONFIRMED, $contact->fresh()->is_primary);

        return UserNotification::query()->where('user_id', $user->id)->sole();
    }
}
