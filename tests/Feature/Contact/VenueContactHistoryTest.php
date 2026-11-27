<?php

namespace Tests\Feature\Contact;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserStatusEnum as Status;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum as Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueContactHistory;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class VenueContactHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_contact_history_is_scoped_and_allowlisted(): void
    {
        $super = User::factory()->create(['system_role' => Role::SUPERADMIN, 'status' => Status::CONFIRMED]);
        $venue = Venue::factory()->create();
        $other = Venue::factory()->create();
        $contact = $venue->contacts()->create(['type' => 'email', 'value' => 'desk@example.test']);
        AuditLog::query()->create([
            'auditable_type' => Contact::class, 'auditable_id' => $contact->id, 'event' => 'updated',
            'old_values' => ['label' => 'Old', 'meta' => ['secret' => 'not rendered']],
            'new_values' => ['label' => 'New', 'meta' => ['secret' => 'not rendered']],
        ]);
        $contact->delete();
        $resolver = app(VenueContactHistory::class);
        $history = $resolver->resolve($super, $venue, $contact->id);
        $this->assertTrue($history['contact']->trashed());
        $change = $history['changes']->first(fn ($change) => ($change['new']['label'] ?? null) === 'New');
        $this->assertSame(['label' => 'Old'], $change['old']);
        $this->assertSame(['label' => 'New'], $change['new']);
        $this->expectException(ModelNotFoundException::class);
        $resolver->resolve($super, $other, $contact->id);
    }

    public function test_unprivileged_account_cannot_read_contact_history(): void
    {
        $user = User::factory()->create(['system_role' => Role::USER, 'status' => Status::CONFIRMED]);
        $venue = Venue::factory()->create();
        $contact = $venue->contacts()->create(['type' => 'email', 'value' => 'desk@example.test']);
        $this->expectException(HttpException::class);
        app(VenueContactHistory::class)->resolve($user, $venue, $contact->id);
    }
}
