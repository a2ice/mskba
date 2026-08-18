<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Application\UseCases\ListEventsHandler;
use App\Modules\Event\Domain\Enums\EventStatusEnum;
use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
use App\Modules\Event\Domain\Models\Event;
use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EventCanonicalIdentityAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_user_sees_private_event_created_by_alias(): void
    {
        $canonical = User::factory()->create();
        $alias = User::factory()->create(['canonical_user_id' => $canonical->id]);
        $aliasActor = app(CurrentActorResolver::class)->resolve($alias, null);
        $canonicalActor = app(CurrentActorResolver::class)->resolve($canonical, null);
        $event = Event::factory()->create([
            'organizer_actor_id' => $aliasActor->id,
            'status' => EventStatusEnum::DRAFT,
            'visibility' => EventVisibilityEnum::PRIVATE,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        $events = app(ListEventsHandler::class)->handle($canonicalActor);

        $this->assertTrue($events->getCollection()->contains('id', $event->id));
    }
}
