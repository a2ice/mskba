<?php

namespace Tests\Feature\Contract;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\AccountAgentAgreements;
use App\Modules\Venue\Application\Services\VenueAgentAgreementService;
use App\Modules\Venue\Domain\Enums\VenuePermissionEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccountAgentAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_list_and_explicit_recipient_acceptance_without_granting_rights(): void
    {
        $admin = User::factory()->create(['status' => UserStatusEnum::CONFIRMED, 'system_role' => Role::SUPERADMIN]);
        $recipient = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $venue = Venue::factory()->create();
        $record = app(VenueAgentAgreementService::class)->propose($venue, $recipient, $admin, 'percent', '5,25',
            [VenuePermissionEnum::VIEW], 'Индивидуальные условия тестового договора.');
        $list = app(AccountAgentAgreements::class);
        $this->assertSame(0, $list->forRecipient($other)->total());
        $item = $list->forRecipient($recipient)->items()[0];
        $this->assertSame('5.25%', $item['rate_label']);
        $this->assertTrue($item['can_accept']);
        $this->assertArrayNotHasKey('evidence_reference', $item);
        $url = route('account.agent-agreements.accept', $record->id);
        $this->post($url, ['accepted' => 1, 'terms_hash' => $record->terms_hash])->assertRedirect(route('login'));
        $this->actingAs($other)->post($url, ['accepted' => 1, 'terms_hash' => $record->terms_hash])->assertNotFound();
        $this->actingAs($recipient)->post($url, ['terms_hash' => $record->terms_hash])->assertSessionHasErrors('accepted');
        $this->post($url, ['accepted' => 1, 'terms_hash' => str_repeat('0',64)])->assertSessionHasErrors('terms');
        $this->assertSame('proposed', $record->fresh()->status);
        $this->post($url, ['accepted' => 1, 'terms_hash' => $record->terms_hash])->assertRedirect(route('account.contracts'));
        $this->assertSame('accepted', $record->fresh()->status);
        $this->assertFalse($list->forRecipient($recipient)->items()[0]['can_accept']);
        $this->assertNull($record->fresh()->contract_id);
        $this->assertNull($record->fresh()->commission_agent_id);
        $this->post(route('account.agent-agreements.decline', $record->id), ['reason' => 'Отказ от предложения договора.'])
            ->assertRedirect(route('account.contracts'));
        $this->assertSame('declined', $record->fresh()->status);
    }
}
