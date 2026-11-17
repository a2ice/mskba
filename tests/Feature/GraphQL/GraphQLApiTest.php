<?php

namespace Tests\Feature\GraphQL;

use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GraphQLApiTest extends TestCase
{
    use RefreshDatabase;

    private function graph(string $query, array $variables = [], ?string $token = null): \Illuminate\Testing\TestResponse
    {
        $request = $this->withHeaders(array_filter([
            'Origin' => 'https://external.example',
            'Authorization' => $token === null ? null : 'Bearer '.$token,
        ]));

        return $request->postJson('/api/graphql', [
            'query' => $query,
            'variables' => (object) $variables,
        ]);
    }

    public function test_public_venues_support_field_selection_and_cursor_pagination(): void
    {
        $venues = collect(range(1, 3))->map(fn (int $i): Venue => Venue::factory()->create([
            'name' => "Public court {$i}",
            'status' => VenueStatusEnum::CONFIRMED->value,
        ]));

        Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED->value]);
        Venue::factory()->create(['status' => VenueStatusEnum::BLOCKED->value]);
        $deleted = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $deleted->delete();

        $query = <<<'GQL'
        query($first: Int, $after: String) {
          venues(first: $first, after: $after) {
            edges { cursor node { id name type } }
            pageInfo { hasNextPage endCursor }
          }
        }
        GQL;

        $first = $this->graph($query, ['first' => 2])
            ->assertOk()
            ->assertJsonCount(2, 'data.venues.edges')
            ->assertJsonPath('data.venues.pageInfo.hasNextPage', true)
            ->assertJsonPath('data.venues.edges.0.node.name', 'Public court 1')
            ->assertJsonMissingPath('data.venues.edges.0.node.shortDescription')
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $cursor = $first->json('data.venues.pageInfo.endCursor');
        $this->assertIsString($cursor);

        $last = $this->graph($query, ['first' => 2, 'after' => $cursor])
            ->assertOk()
            ->assertJsonCount(1, 'data.venues.edges')
            ->assertJsonPath('data.venues.pageInfo.hasNextPage', false)
            ->assertJsonPath('data.venues.pageInfo.endCursor', null);

        $actual = array_merge(
            array_column(array_column($first->json('data.venues.edges'), 'node'), 'id'),
            array_column(array_column($last->json('data.venues.edges'), 'node'), 'id'),
        );
        $this->assertSame($venues->pluck('id')->map(fn (int $id): string => (string) $id)->all(), $actual);
    }

    public function test_published_venue_lookup_only_returns_confirmed(): void
    {
        $public = Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $private = Venue::factory()->create(['status' => VenueStatusEnum::UNCONFIRMED->value]);

        $this->graph('query($id: ID!) { venue(id: $id) { id name } }', ['id' => (string) $public->id])
            ->assertJsonPath('data.venue.id', (string) $public->id);

        $this->graph('query($id: ID!) { venue(id: $id) { id name } }', ['id' => (string) $private->id])
            ->assertJsonPath('data.venue', null);
    }

    public function test_invalid_cursors_and_unbounded_pages_are_rejected(): void
    {
        $this->graph('{ venues(first: 101) { edges { node { id } } } }')
            ->assertJsonStructure(['errors' => [['message']]]);

        $this->graph('query($after: String) { venues(first: 2, after: $after) { edges { node { id } } } }',
            ['after' => 'bad-cursor'])
            ->assertJsonStructure(['errors' => [['message']]]);

        $this->graph('{ venues(first: 0) { edges { node { id } } } }')
            ->assertJsonStructure(['errors' => [['message']]]);
    }

    public function test_graphql_only_exposes_declared_fields(): void
    {
        Venue::factory()->create(['status' => VenueStatusEnum::CONFIRMED->value]);
        $this->graph('{ venues(first: 1) { edges { node { id createdByActorId } } } }')
            ->assertJsonStructure(['errors' => [['message']]]);
    }

    public function test_authenticated_me_uses_existing_sanctum_permissions(): void
    {
        $user = User::factory()->create([
            'username' => 'graphql_qa_user',
            'status' => UserStatusEnum::UNCONFIRMED->value,
        ]);
        $user->createProfile(['first_name' => 'GraphQL', 'last_name' => 'Tester']);

        $query = '{ me { id username status profile { firstName lastName } participationRoles } }';
        $this->graph($query)
            ->assertJsonStructure(['errors' => [['message']]]);

        $token = $user->createToken('ios', ['profile:read'], now()->addDays(30))->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->graph($query, [], $token)
            ->assertOk()
            ->assertJsonPath('data.me.id', (string) $user->id)
            ->assertJsonPath('data.me.username', 'graphql_qa_user')
            ->assertJsonPath('data.me.profile.firstName', 'GraphQL')
            ->assertJsonMissingPath('data.me.password')
            ->assertJsonMissingPath('data.me.systemRole');

        $this->app['auth']->forgetGuards();
        $user->forceFill(['status' => UserStatusEnum::BLOCKED])->save();
        $this->graph($query, [], $token)
            ->assertJsonStructure(['errors' => [['message']]]);
    }

    public function test_revoked_or_insufficient_token_cannot_read_profile(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED->value]);
        $user->createProfile([]);

        $query = '{ me { id username } }';
        $token = $user->createToken('wrong-scope', ['auth:logout'])->plainTextToken;
        $this->graph($query, [], $token)->assertJsonStructure(['errors' => [['message']]]);

        $user->tokens()->delete();
        $this->app['auth']->forgetGuards();
        $this->graph($query, [], $token)->assertJsonStructure(['errors' => [['message']]]);
    }

    public function test_graphql_query_depth_is_bounded(): void
    {
        $this->assertSame(7, config('lighthouse.security.max_query_depth'));
        $this->assertSame(1500, config('lighthouse.security.max_query_complexity'));
    }
}
