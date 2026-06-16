<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Enums\ActorTypeEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('audit.ignore_console', false);
    }

    public function test_guest_venue_creation_writes_actor_aware_audit_log(): void
    {
        $response = $this
            ->post(route('venues.store'), [
                'name' => 'Аудируемая площадка',
                'type' => VenueTypeEnum::STREET_COURT->value,
                'description' => 'Площадка для проверки аудита',
                'raw_address' => 'Москва, Тестовая, 10',
            ])
            ->assertRedirect();

        $venue = Venue::query()->where('name', 'Аудируемая площадка')->firstOrFail();
        $log = AuditLog::query()
            ->where('auditable_type', Venue::class)
            ->where('auditable_id', $venue->id)
            ->where('event', 'created')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('Аудируемая площадка', $log->new_values['name']);
        $this->assertArrayNotHasKey('created_at', $log->new_values);
        $this->assertSame(ActorTypeEnum::GUEST, $log->actor->type);
        $this->assertNull($log->actor->user_id);
        $this->assertNotNull($log->actor->user_fingerprint_id);
        $this->assertSame('POST', $log->metadata['method']);
        $this->assertSame('venues.store', $log->metadata['route']);
        $this->assertNotNull($response->getCookie('mskba_browser_fp'));
    }

    public function test_model_update_writes_only_changed_non_ignored_fields(): void
    {
        $user = User::factory()->create([
            'username' => 'audit_user',
            'password' => 'secret',
        ]);

        AuditLog::query()->delete();

        $user->forceFill([
            'username' => 'audit_user_renamed',
            'password' => 'changed-secret',
        ])->save();

        $log = AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->where('event', 'updated')
            ->firstOrFail();

        $this->assertSame('audit_user', $log->old_values['username']);
        $this->assertSame('audit_user_renamed', $log->new_values['username']);
        $this->assertArrayNotHasKey('password', $log->old_values);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertArrayNotHasKey('updated_at', $log->new_values);
    }
}
