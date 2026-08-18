<?php

namespace Tests\Feature\Reaction;

use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reaction\Application\Services\CanonicalReactionConsolidator;
use App\Modules\Reaction\Application\Services\ReactionReadService;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use App\Modules\Reaction\Domain\Models\Reaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReactionFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_like_switch_and_remove_reaction(): void
    {
        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $content = $this->content();
        $url = route('reactions.set', ['subjectType' => 'content', 'subjectId' => $content->id]);

        $this->actingAs($user)
            ->putJson($url, ['value' => 1])
            ->assertOk()
            ->assertExactJson([
                'likes' => 1,
                'dislikes' => 0,
                'viewer_reaction' => 1,
            ]);

        $this->assertDatabaseHas('reactions', [
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'user_id' => $user->id,
            'value' => 1,
            'source' => 'web',
        ]);

        $this->actingAs($user)
            ->putJson($url, ['value' => -1])
            ->assertOk()
            ->assertExactJson([
                'likes' => 0,
                'dislikes' => 1,
                'viewer_reaction' => -1,
            ]);

        $this->assertSame(1, (int) \DB::table('reactions')->count());

        $this->actingAs($user)
            ->putJson($url, ['value' => null])
            ->assertOk()
            ->assertExactJson([
                'likes' => 0,
                'dislikes' => 0,
                'viewer_reaction' => null,
            ]);

        $this->assertDatabaseHas('reactions', [
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'value' => 0,
        ]);
    }

    public function test_reaction_endpoint_requires_auth_valid_value_and_public_subject(): void
    {
        $content = $this->content();
        $url = route('reactions.set', ['subjectType' => 'content', 'subjectId' => $content->id]);

        $this->putJson($url, ['value' => 1])->assertUnauthorized();

        $user = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $this->actingAs($user)
            ->putJson($url, ['value' => 2])
            ->assertUnprocessable();

        $unpublished = $this->content(false);
        $this->actingAs($user)
            ->putJson(route('reactions.set', [
                'subjectType' => 'content',
                'subjectId' => $unpublished->id,
            ]), ['value' => 1])
            ->assertNotFound();

        $this->actingAs($user)
            ->putJson(route('reactions.set', [
                'subjectType' => 'venue',
                'subjectId' => 1,
            ]), ['value' => 1])
            ->assertNotFound();
    }

    public function test_feed_renders_aggregate_counts_and_viewer_state(): void
    {
        $content = $this->content();
        $viewer = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $other = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $url = route('reactions.set', ['subjectType' => 'content', 'subjectId' => $content->id]);

        $this->actingAs($viewer)->putJson($url, ['value' => 1])->assertOk();
        $this->actingAs($other)->putJson($url, ['value' => -1])->assertOk();

        $this->actingAs($viewer)
            ->get(route('news.index'))
            ->assertOk()
            ->assertSee('data-reaction-current="1"', false)
            ->assertSee('data-reaction-count="likes">1</span>', false)
            ->assertSee('data-reaction-count="dislikes">1</span>', false);

        $this->actingAs($viewer)
            ->get(route('news.show', $content->alias))
            ->assertOk()
            ->assertSee('data-reaction-current="1"', false)
            ->assertSee('data-reaction-count="likes">1</span>', false)
            ->assertSee('data-reaction-count="dislikes">1</span>', false);
    }

    public function test_alias_user_votes_as_canonical_identity(): void
    {
        $canonical = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $alias = User::factory()->create([
            'status' => UserStatusEnum::CONFIRMED,
            'canonical_user_id' => $canonical->id,
        ]);
        $content = $this->content();

        $this->actingAs($alias)
            ->putJson(route('reactions.set', [
                'subjectType' => 'content',
                'subjectId' => $content->id,
            ]), ['value' => 1])
            ->assertOk()
            ->assertJsonPath('likes', 1);

        $this->assertDatabaseHas('reactions', [
            'actor_type' => 'user',
            'actor_id' => (string) $canonical->id,
            'user_id' => $canonical->id,
        ]);
        $this->assertDatabaseMissing('reactions', [
            'actor_type' => 'user',
            'actor_id' => (string) $alias->id,
        ]);
    }

    public function test_identity_consolidation_keeps_latest_vote(): void
    {
        $canonical = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $alias = User::factory()->create(['status' => UserStatusEnum::CONFIRMED]);
        $content = $this->content();

        Reaction::query()->create([
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'user',
            'actor_id' => (string) $canonical->id,
            'user_id' => $canonical->id,
            'value' => 1,
            'source' => 'web',
            'source_occurred_at' => now()->subMinute(),
        ]);
        Reaction::query()->create([
            'subject_type' => 'content',
            'subject_id' => $content->id,
            'actor_type' => 'user',
            'actor_id' => (string) $alias->id,
            'user_id' => $alias->id,
            'value' => -1,
            'source' => 'web',
            'source_occurred_at' => now(),
        ]);

        $alias->forceFill(['canonical_user_id' => $canonical->id])->save();
        app(CanonicalReactionConsolidator::class)->consolidate($canonical);

        $summary = app(ReactionReadService::class)->forSubject(
            ReactionSubjectTypeEnum::CONTENT,
            (int) $content->id,
            $alias,
        );

        $this->assertSame(0, $summary->likes);
        $this->assertSame(1, $summary->dislikes);
        $this->assertSame(-1, $summary->viewerReaction?->value);
        $this->assertDatabaseCount('reactions', 1);
        $this->assertDatabaseHas('reactions', [
            'actor_id' => (string) $canonical->id,
            'user_id' => $canonical->id,
            'value' => -1,
        ]);
    }

    private function content(bool $published = true): ContentItem
    {
        $author = User::factory()->create();
        $suffix = bin2hex(random_bytes(4));

        return ContentItem::query()->create([
            'created_by_user_id' => $author->id,
            'type' => 'material',
            'title' => 'Reaction test '.$suffix,
            'alias' => 'reaction-test-'.$suffix,
            'short_description' => 'Короткое описание.',
            'full_description' => 'Текст материала.',
            'publish_in_feed' => $published,
            'publish_in_telegram' => false,
            'feed_published_at' => $published ? now()->subMinute() : null,
        ]);
    }
}
