<?php

namespace Tests\Feature\Venue;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\UseCases\SubmitVenueOwnershipClaimHandler;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VenueOwnershipClaimConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_upload_opens_only_after_files_requested_and_closes_after_one_file(): void
    {
        $applicant = $this->confirmedUser();
        $admin = $this->confirmedUser(UserSystemRoleEnum::ADMIN);
        $venue = Venue::factory()->create();
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle(
            $venue,
            $applicant,
            'Я официальный представитель площадки и готов подтвердить полномочия.',
        );

        $this->actingAs($admin)->postJson(route('account.venue-ownership.conversation.store', $claim), [
            'client_id' => (string) Str::uuid(),
            'body' => 'Уточните, пожалуйста, организацию.',
        ])->assertCreated();

        $this->upload($applicant, $claim, 'before-request.pdf')->assertStatus(409);

        $this->actingAs($admin)->postJson(route('account.venue-ownership.conversation.store', $claim), [
            'client_id' => (string) Str::uuid(),
            'body' => 'Пришлите документ, подтверждающий полномочия.',
            'short_code' => 'files_requested',
        ])->assertCreated()->assertJsonPath('short_code', 'files_requested');

        $this->actingAs($applicant)
            ->getJson(route('account.venue-ownership.conversation.index', $claim))
            ->assertOk()
            ->assertJsonPath('can_attach', true)
            ->assertJsonPath('files_request_open', true);

        $this->upload($applicant, $claim, 'authority.pdf')->assertCreated();

        $this->actingAs($applicant)
            ->getJson(route('account.venue-ownership.conversation.index', $claim))
            ->assertOk()
            ->assertJsonPath('can_attach', false)
            ->assertJsonPath('files_request_open', false);

        $this->upload($applicant, $claim, 'extra.pdf')->assertStatus(409);

        $this->actingAs($admin)->postJson(route('account.venue-ownership.conversation.store', $claim), [
            'client_id' => (string) Str::uuid(),
            'body' => 'Нужен ещё один документ.',
            'short_code' => 'files_requested',
        ])->assertCreated();

        $this->upload($applicant, $claim, 'second-authority.pdf')->assertCreated();
    }

    public function test_applicant_cannot_forge_files_requested_short_code(): void
    {
        $applicant = $this->confirmedUser();
        $admin = $this->confirmedUser(UserSystemRoleEnum::ADMIN);
        $claim = app(SubmitVenueOwnershipClaimHandler::class)->handle(
            Venue::factory()->create(),
            $applicant,
            'Я представитель площадки и готов предоставить подтверждение.',
        );

        $this->actingAs($admin)->postJson(route('account.venue-ownership.conversation.store', $claim), [
            'client_id' => (string) Str::uuid(),
            'body' => 'Начинаем проверку.',
        ])->assertCreated();

        $this->actingAs($applicant)->postJson(route('account.venue-ownership.conversation.store', $claim), [
            'client_id' => (string) Str::uuid(),
            'body' => 'Попытка открыть загрузку самостоятельно.',
            'short_code' => 'files_requested',
        ])->assertForbidden();
    }

    private function upload(User $user, $claim, string $name)
    {
        return $this->actingAs($user)->post(
            route('account.venue-ownership.conversation.attach', $claim),
            [
                'client_id' => (string) Str::uuid(),
                'attachment' => UploadedFile::fake()->create($name, 50, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        );
    }

    private function confirmedUser(UserSystemRoleEnum $role = UserSystemRoleEnum::USER): User
    {
        return User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'system_role' => $role,
        ]);
    }
}
