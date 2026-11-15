<?php

namespace Tests\Feature\Venue;

use App\Filament\Backoffice\Resources\Venues\VenueBackofficeEditor;
use App\Modules\Audit\Domain\Models\AuditLog;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Application\Services\VenueSearchCache;
use App\Modules\Venue\Application\UseCases\FindNearbyVenuesHandler;
use App\Modules\Venue\Application\UseCases\ListVenuesHandler;
use App\Modules\Venue\Application\UseCases\SearchVenuesHandler;
use App\Modules\Venue\Application\UseCases\UpdateVenueStatusHandler;
use App\Modules\Venue\Domain\Enums\VenueOperationalStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class VenuePublicationControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_venue_can_be_hidden_without_blocking_it_and_republished(): void
    {
        $venue = Venue::factory()->create([
            'name' => 'Тестовая площадка Академическая',
            'status' => VenueStatusEnum::CONFIRMED,
            'operational_status' => VenueOperationalStatusEnum::TEMPORARILY_CLOSED,
            'is_published' => true,
        ]);
        $visible = Venue::factory()->create([
            'name' => 'Публичная площадка Ленина',
            'status' => VenueStatusEnum::CONFIRMED,
            'is_published' => true,
        ]);
        $this->assertNotNull(collect(app(ListVenuesHandler::class)->handle(null))->firstWhere('id', $venue->id));
        $this->getJson(route('api.v1.venues.show', ['venue' => $venue->id]))->assertOk();
        // Populate search cache before hiding: the model observer must invalidate it.
        app(VenueSearchCache::class)->documents();
        $venue->update(['is_published' => false]);

        $this->assertSame(VenueStatusEnum::CONFIRMED, $venue->refresh()->status);
        $this->assertSame(VenueOperationalStatusEnum::TEMPORARILY_CLOSED, $venue->operational_status);
        $this->assertNull(collect(app(ListVenuesHandler::class)->handle(null))->firstWhere('id', $venue->id));
        $this->assertNotNull(collect(app(ListVenuesHandler::class)->handle(null))->firstWhere('id', $visible->id));
        $this->getJson(route('api.v1.venues.index'))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
        $this->getJson(route('api.v1.venues.show', ['venue' => $venue->id]))->assertNotFound();
        $this->get(route('venues.show', $venue->routeIdentifier()))->assertNotFound();
        $this->get(route('venues.preview', $venue->routeIdentifier()))->assertNotFound();
        $this->getJson(route('venues.schedule.day', [
            'venue' => $venue->id, 'date' => '2026-11-16',
        ]))->assertNotFound();
        $this->get(route('venues', ['view' => 'map']))->assertOk()
            ->assertDontSee($venue->name)->assertSee($visible->name);
        $this->assertNull(collect(app(SearchVenuesHandler::class)->handle(
            user: null, actor: null, query: 'Академическая', confirmedOnly: true,
        ))->firstWhere('id', $venue->id));
        $this->assertSame([], collect(app(FindNearbyVenuesHandler::class)->handle(
            $visible->id, 55.75, 37.60,
        ))->where('id', $venue->id)->all());
        $this->get('/sitemap.xml')->assertOk()->assertDontSee($venue->routeIdentifier());

        $venue->update(['is_published' => true]);
        $this->assertNotNull(collect(app(ListVenuesHandler::class)->handle(null))->firstWhere('id', $venue->id));
        $this->getJson(route('api.v1.venues.show', ['venue' => $venue->id]))->assertOk();
    }

    public function test_hidden_venue_is_private_but_accessible_to_superadmin_for_preview(): void
    {
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED, 'is_published' => false,
        ]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->get(route('venues.show', $venue->routeIdentifier()))->assertNotFound();

        $super = User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN, 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($super);
        $this->get(route('venues.show', $venue->routeIdentifier()))->assertOk();
        // Even an administrator must not publish a hidden venue on the map.
        $this->assertNull(collect(app(ListVenuesHandler::class)->handle($super))->firstWhere('id', $venue->id));
    }

    public function test_filament_editor_requires_permission_and_audits_publication_and_notes(): void
    {
        config()->set('audit.ignore_console', false);
        $venue = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED]);
        $editor = app(VenueBackofficeEditor::class);
        $super = User::factory()->create(['system_role' => UserSystemRoleEnum::SUPERADMIN, 'status' => UserStatusEnum::CONFIRMED]);
        $super->createProfile([]);
        $this->actingAs($super);

        $data = array_replace([
            'name' => $venue->name, 'type' => $venue->type->value,
            'status' => VenueStatusEnum::CONFIRMED->value,
            'operational_status' => $venue->operational_status->value,
        ], $editor->fields($venue), [
            'is_published' => false,
            'status_info' => 'Информация по площадке пока проверяется',
        ]);
        $saved = $editor->save($data, $venue);
        $this->assertFalse($saved->is_published);
        $this->assertSame('Информация по площадке пока проверяется', $saved->status_info);
        $this->assertSame(VenueStatusEnum::CONFIRMED, $saved->status);
        $publicationAudit = AuditLog::query()
            ->where('auditable_type', Venue::class)->where('auditable_id', $venue->id)
            ->get()->first(fn (AuditLog $audit): bool => array_key_exists('is_published', $audit->new_values ?? []));
        $this->assertNotNull($publicationAudit);
        $this->assertNotNull($publicationAudit->actor_id);
        $this->assertFalse((bool) $publicationAudit->new_values['is_published']);
        $this->assertSame('Информация по площадке пока проверяется', $publicationAudit->new_values['status_info']);

        $nonManager = User::factory()->create(['system_role' => UserSystemRoleEnum::ADMIN, 'status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($nonManager);
        try {
            $editor->save(array_replace($data, ['is_published' => true]), $venue);
            $this->fail('Without backoffice venue manage permission publication cannot be changed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertFalse($venue->refresh()->is_published);
    }

    public function test_status_note_is_independent_of_blocking_and_can_be_changed_without_status_change(): void
    {
        $venue = Venue::factory()->create([
            'status' => VenueStatusEnum::CONFIRMED,
            'status_info' => 'Ждём документы',
        ]);
        $transitions = app(UpdateVenueStatusHandler::class);
        $transitions->handle($venue, VenueStatusEnum::BLOCKED, 'Ожидаем уточнение');
        $this->assertSame('Ожидаем уточнение', $venue->refresh()->status_info);
        $transitions->handle($venue, VenueStatusEnum::UNCONFIRMED);
        $this->assertSame('Ожидаем уточнение', $venue->refresh()->status_info);
        $transitions->handle($venue, VenueStatusEnum::UNCONFIRMED, 'Материалы получены');
        $this->assertSame('Материалы получены', $venue->refresh()->status_info);
    }
}
